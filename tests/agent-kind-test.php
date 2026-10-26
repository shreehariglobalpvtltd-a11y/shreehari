<?php
/**
 * Agent kind + serial bands — integration test (Agent CRM, 5 Sep 2026).
 *
 * Locks in:
 *   • an Organization agent gets the lowest free serial in 1-20, a Person
 *     agent the lowest free serial from 21 up;
 *   • the org band spills past 20 only when every org number is held;
 *   • switching kind keeps the serial, the wallet ledger rows and the
 *     commission tier (owner: "serial nabigrine, wallet/history surakshit");
 *   • resequence packs orgs into 1.. and persons into 21..;
 *   • profile() exposes agent_kind / contact_person / whatsapp and
 *     saveProfile() whitelists them (whatsapp digits-only).
 *
 *   php -c .claude/php-dev.ini tests/agent-kind-test.php
 *
 * Writes only throwaway rows and restores the agent_codes / agent_types
 * settings maps it touched. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

/* Snapshot the two settings maps so the test can put them back exactly. */
$origCodes = Settings::getArray('agent_codes', []);
$origTypes = Settings::getArray('agent_types', []);

$made = [];
function mkAgent(string $u, string $kind): int {
    global $made;
    Database::run("DELETE FROM admins WHERE username = :u", ['u' => $u]);
    $id = Database::insert('admins', [
        'username' => $u, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => 'Test ' . $u,
        'phone' => (string) random_int(7000000000, 7999999999), 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    AgentWallet::saveProfile($id, ['agent_kind' => $kind]);
    $made[] = $id;
    return $id;
}

try {
    echo "\n== kind helpers ==\n";
    check('normaliseKind(person)', AgentWallet::normaliseKind('Person') === 'person');
    check('normaliseKind(anything else) = org', AgentWallet::normaliseKind('xyz') === 'org');
    check('codeBandFor(org) = 1-20', AgentWallet::codeBandFor('org') === [1, 20]);
    check('codeBandFor(person) = 21-1000', AgentWallet::codeBandFor('person') === [21, 1000]);
    check('codeInBand(5, org)', AgentWallet::codeInBand(5, 'org'));
    check('!codeInBand(5, person)', !AgentWallet::codeInBand(5, 'person'));
    check('cleanPhone strips to digits', AgentWallet::cleanPhone('+91 98765-43210') === '919876543210');
    check('cleanPhone rejects junk', AgentWallet::cleanPhone('abc') === '');

    echo "\n== allocation follows the band ==\n";
    // Start from an empty map so the expectations are exact.
    Settings::set('agent_codes', [], 'json', 'agent', false);
    $org1 = mkAgent('testkind-org1', 'org');
    $per1 = mkAgent('testkind-per1', 'person');
    check('profile() reads agent_kind=org', AgentWallet::profile($org1)['agent_kind'] === 'org');
    check('agentKindFor(person agent)', AgentWallet::agentKindFor($per1) === 'person');

    $c = AgentWallet::nextFreeAgentCode('org');   AgentWallet::setAgentCode($org1, $c);
    check('first org serial = 1', $c === 1 && AgentWallet::agentCodeFor($org1) === 1);
    $c = AgentWallet::nextFreeAgentCode('person'); AgentWallet::setAgentCode($per1, $c);
    check('first person serial = 21', $c === 21 && AgentWallet::agentCodeFor($per1) === 21);
    check('flat nextFreeAgentCode() still lowest overall (2)', AgentWallet::nextFreeAgentCode() === 2);
    check('label still 4-digit SHG-0021', AgentWallet::agentCodeLabel($per1) === 'SHG-0021');

    // Fill the org band 2..20 with dummies (ids that never exist is fine for the map).
    $map = Settings::getArray('agent_codes', []);
    for ($n = 2; $n <= 20; $n++) { $map['9' . str_pad((string) $n, 5, '0', STR_PAD_LEFT)] = $n; }
    Settings::set('agent_codes', $map, 'json', 'agent', false);
    check('org band full -> spills to lowest free >= 21 (22)', AgentWallet::nextFreeAgentCode('org') === 22);
    check('person still 22', AgentWallet::nextFreeAgentCode('person') === 22);

    echo "\n== switching kind keeps serial, ledger and tier ==\n";
    AgentWallet::setAgentType($org1, 'joint');
    Database::insert('agent_ledger', [
        'agent_admin_id' => $org1, 'booking_id' => null, 'account' => 'commission', 'entry_type' => 'adjustment',
        'amount' => 123.00, 'note' => 'testkind row', 'created_by' => 0,
    ]);
    $ledgerBefore = (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $org1]);
    AgentWallet::setAgentKind($org1, 'person', 0);
    check('kind switched to person', AgentWallet::agentKindFor($org1) === 'person');
    check('serial unchanged (1)', AgentWallet::agentCodeFor($org1) === 1);
    check('tier unchanged (joint)', AgentWallet::agentTypeFor($org1) === 'joint');
    check('ledger rows unchanged', (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $org1]) === $ledgerBefore && $ledgerBefore >= 1);
    $aud = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE action = 'agent.kind_changed' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => (string) $org1]);
    check('audit row agent.kind_changed written', $aud !== null && str_contains((string) $aud['new_value'], 'person'));
    AgentWallet::setAgentKind($org1, 'org', 0);
    check('switched back to org, serial still 1', AgentWallet::agentKindFor($org1) === 'org' && AgentWallet::agentCodeFor($org1) === 1);

    echo "\n== saveProfile whitelist ==\n";
    AgentWallet::saveProfile($per1, ['contact_person' => ' Sita Devi ', 'whatsapp' => '98765 43210', 'agent_kind' => 'bogus']);
    $p = AgentWallet::profile($per1);
    check('contact_person saved (trimmed)', $p['contact_person'] === 'Sita Devi');
    check('whatsapp saved digits-only', $p['whatsapp'] === '9876543210');
    check('bogus kind normalised to org', $p['agent_kind'] === 'org');
    AgentWallet::saveProfile($per1, ['agent_kind' => 'person']);

    echo "\n== resequence packs the bands ==\n";
    // Only our test agents exist in the map now (dummies are not admins rows and get dropped).
    $org2 = mkAgent('testkind-org2', 'org');
    AgentWallet::setAgentCode($org2, 25);          // an org holding a person-band number
    $r = AgentWallet::resequenceAgentCodes(0);
    $codes = Settings::getArray('agent_codes', []);
    $orgCodes = array_values(array_filter([$codes[(string) $org1] ?? null, $codes[(string) $org2] ?? null]));
    sort($orgCodes);
    check('orgs packed into 1,2', $orgCodes === [1, 2]);
    check('person packed at 21', ($codes[(string) $per1] ?? null) === 21);
    $dummiesLeft = count(array_filter(array_keys($codes), static fn($k) => str_starts_with((string) $k, '9') && strlen((string) $k) === 6));
    check('dummy (non-admin) entries dropped', $dummiesLeft === 0);

    // Other real agents in this DB (e.g. seed agent1) may also be in the map — resequence
    // includes them; make sure none of them collided with our bands.
    check('no duplicate serials after resequence', count($codes) === count(array_unique($codes)));
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage(), false);
} finally {
    foreach ($made as $id) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :i', ['i' => $id]);
        Database::delete('audit_logs', "entity_type = 'admin' AND entity_id = :e", ['e' => (string) $id]);
    }
    Settings::set('agent_codes', $origCodes, 'json', 'agent', false);
    Settings::set('agent_types', $origTypes, 'json', 'agent', false);
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
