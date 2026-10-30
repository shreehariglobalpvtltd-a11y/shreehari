<?php
/**
 * =====================================================================
 *  agentpro-edit-approved-test.php — 31 Oct 2026 (owner: "ekdum purai
 *  details — agent code, name, number, anything — admin change garna
 *  milos"). The office edits an APPROVED member through the same office
 *  form, and every change reaches the live account at once. Against the
 *  TEST database; everything it makes is deleted again.
 *
 *    - an older member with no form gets an approved office record
 *      (officeRecordFor), the same one on a second call
 *    - a super-admin / manager session may edit it; no session, a counter,
 *      or a manager on a super-admin's form may not
 *    - name, email, city, address, DOB, gender, guardian, designation, ID
 *      number reach admins / admin_profiles / ap_members
 *    - a new mobile (office check) reaches admins.phone; a number already
 *      on the most logins it may carry is refused
 *    - a draft / submitted form still behaves as before
 *
 *    php tests/agentpro-edit-approved-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
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
function caught(callable $fn): ?Throwable {
    try { $fn(); return null; } catch (Throwable $e) { return $e; }
}
function section(string $s): void { echo "-- $s --\n"; }
/** A hand-built admin session (Auth::admin() skips the re-check for sessions without checked_at). */
function actAs(?array $a): void {
    if ($a === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'role' => (string) $a['role'], 'username' => (string) $a['username'], 'last_seen' => time()];
}

if (!AgentPro::available() || !AgentPro::appHas('office_filled_by')) {
    check('ap_* tables + the af1 migration are installed', false);
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

$made = [];
$mk = static function (string $user, string $role, string $name, ?string $phone) use (&$made): array {
    $id = (int) Database::insert('admins', [
        'username' => $user, 'full_name' => $name, 'email' => $user . '@example.test', 'phone' => $phone,
        'role' => $role, 'is_active' => 1, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
    ]);
    $made[] = $id;
    return Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
};
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$super   = $mk('zz_ed_super_' . $tag, 'superadmin', 'ZZ Edit Super', null);
$manager = $mk('zz_ed_mgr_' . $tag, 'manager', 'ZZ Edit Manager', null);
$counter = $mk('zz_ed_ctr_' . $tag, 'counter', 'ZZ Edit Counter', null);
$agent   = $mk('zz_ed_agent_' . $tag, 'agent', 'ZZ Old Agent', '7000000711');
$other   = $mk('zz_ed_other_' . $tag, 'agent', 'ZZ Other Agent', '7000000712');
AgentWallet::saveProfile((int) $agent['id'], ['agent_kind' => 'person', 'address' => 'Old Road', 'counter_name' => 'Mehsana', 'id_type' => 'Aadhaar', 'id_number' => 'XXXX XXXX 1234']);

try {
    section('an older member gets an office record');
    actAs($super);
    $rec = AgentPro::officeRecordFor((int) $agent['id'], (int) $super['id']);
    check('approved office record made', ($rec['status'] ?? '') === 'approved' && (int) $rec['admin_id'] === (int) $agent['id']);
    check('…with the account\'s name, phone, city, ID', $rec['full_name'] === 'ZZ Old Agent' && $rec['phone'] === '7000000711'
        && $rec['city'] === 'Mehsana' && $rec['id_type'] === 'aadhaar' && $rec['id_number_masked'] === 'XXXX XXXX 1234',
        (string) json_encode(array_intersect_key($rec, array_flip(['full_name', 'org_name', 'agent_kind', 'phone', 'city', 'id_type', 'id_number_masked']))));
    $again = AgentPro::officeRecordFor((int) $agent['id'], (int) $super['id']);
    check('a second call opens the same form', (int) $again['id'] === (int) $rec['id']);
    $key = (string) $rec['draft_key'];

    section('who may edit an approved form');
    actAs(null);
    check('no session: refused', caught(fn() => AgentPro::saveDraft($key, ['city' => 'X'], true)) instanceof RuntimeException);
    actAs($counter);
    check('a counter: refused', caught(fn() => AgentPro::saveDraft($key, ['city' => 'X'], true)) instanceof RuntimeException);
    actAs($super);
    check('the public side (office=false): refused', caught(fn() => AgentPro::saveDraft($key, ['city' => 'X'], false)) instanceof RuntimeException);
    actAs($manager);
    check('a manager: allowed', caught(fn() => AgentPro::saveDraft($key, ['district' => 'Mehsana'], true)) === null);

    section('changes reach the live account');
    actAs($super);
    AgentPro::saveDraft($key, [
        'full_name' => 'ZZ New Name', 'email' => 'zz.new.' . $tag . '@example.test', 'city' => 'Surat', 'address' => 'Ring Road 5',
        'dob' => '1990-04-02', 'gender' => 'male', 'guardian' => 'ZZ Father', 'designation' => 'Senior Agent',
        'id_type' => 'pan', 'id_number' => 'ABCDE1234F',
    ], true);
    $a = Database::fetch('SELECT full_name, email, phone FROM admins WHERE id = :i', ['i' => (int) $agent['id']]);
    $p = AgentWallet::profile((int) $agent['id']);
    $mem = Database::fetch('SELECT dob, gender, guardian, designation FROM ap_members WHERE admin_id = :a', ['a' => (int) $agent['id']]);
    check('admins.full_name', $a['full_name'] === 'ZZ New Name', (string) $a['full_name']);
    check('admins.email', $a['email'] === 'zz.new.' . $tag . '@example.test');
    check('profile address = street, city, district', str_contains((string) $p['address'], 'Ring Road 5') && str_contains((string) $p['address'], 'Surat'), (string) $p['address']);
    check('an agent\'s area follows the city', (string) $p['counter_name'] === 'Surat');
    check('profile ID = PAN, masked', (string) $p['id_type'] === 'PAN' && (string) $p['id_number'] !== '' && !str_contains((string) $p['id_number'], 'ABCDE1234F'), (string) $p['id_number']);
    check('ap_members dob / gender / guardian / designation', $mem['dob'] === '1990-04-02' && $mem['gender'] === 'Male'
        && $mem['guardian'] === 'ZZ Father' && $mem['designation'] === 'Senior Agent');
    $m = AgentPro::member((int) $agent['id']);
    check('the ID card model reads the new name + designation', $m['name'] === 'ZZ New Name' && $m['designation'] === 'Senior Agent');

    section('a new mobile');
    AgentPro::officeVerify($key, (int) $super['id'], 'number changed at the office', '7000000713', 'IN');
    check('admins.phone is the new mobile', normalisePhone((string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => (int) $agent['id']], '')) === normalisePhone('7000000713'));
    $taken = caught(fn() => AgentPro::officeVerify($key, (int) $super['id'], 'try a taken number', '7000000712', 'IN'));
    check('another login\'s mobile asks first (no silent share)', $taken instanceof ApPhoneTaken);
    check('…and the phone did not change', normalisePhone((string) Database::scalar('SELECT phone FROM admins WHERE id = :i', ['i' => (int) $agent['id']], '')) === normalisePhone('7000000713'));

    section('role stays fixed, submit is a no-op');
    check('officeSetRole on an approved form is refused', caught(fn() => AgentPro::officeSetRole($key, 'counter')) instanceof RuntimeException);
    $s = AgentPro::submit($key, true, (int) $super['id']);
    check('submit() leaves an approved form as it is', ($s['status'] ?? '') === 'approved' && (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id = :i', ['i' => (int) $agent['id']], 0) === 1);

    section('a super-admin\'s form');
    $sRec = AgentPro::officeRecordFor((int) $super['id'], (int) $super['id']);
    actAs($manager);
    check('a manager cannot edit a super-admin\'s form', caught(fn() => AgentPro::saveDraft((string) $sRec['draft_key'], ['city' => 'X'], true)) instanceof RuntimeException);
    actAs($super);
    check('a super-admin can', caught(fn() => AgentPro::saveDraft((string) $sRec['draft_key'], ['city' => 'Surat'], true)) === null);

    section('a draft still works as before');
    actAs(null);
    $d = AgentPro::newDraft('', (int) $super['id']);
    check('a draft saves without a session', caught(fn() => AgentPro::saveDraft((string) $d['draft_key'], ['full_name' => 'ZZ Draft Person'], false)) === null);
    Database::delete('ap_applications', 'id = :i', ['i' => (int) $d['id']]);
} finally {
    actAs(null);
    foreach ($made as $id) {
        Database::delete('ap_applications', 'admin_id = :a', ['a' => $id]);
        Database::delete('ap_members', 'admin_id = :a', ['a' => $id]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
}

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
