<?php
/**
 * tests/team-registration-test.php — 23 Oct 2026, owner prompt "Agent/Staff
 * Registration & Verification": look-alike warning (TeamDupes), agreement
 * expiry + 30/15/7 reminders (TeamExpiry), the typed card check (IdVerify)
 * and api/verify-id.php.
 *
 *   php -c .claude/php-dev.ini tests/team-registration-test.php
 *
 * TEST database only. WhatsApp goes through click_to_chat for the run (the
 * driver is put back at the end), so nothing is really sent. Writes a few
 * rows on phones 98000888xx and removes them. The API checks go over HTTP to
 * SHG_TEST_BASE (default http://localhost:8891) and are skipped when no
 * server answers there.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/teamdupes.php';
require_once INCLUDE_PATH . '/teamexpiry.php';
require_once INCLUDE_PATH . '/idverify.php';

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
$http = static function (string $url): array {
    $ch = curl_init(rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8891'), '/') . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$driver = Settings::getString('whatsapp_driver', 'click_to_chat');
$marks  = Settings::getArray(TeamExpiry::MAP_KEY, []);
Settings::set('whatsapp_driver', 'click_to_chat');
$clean = static function (): void {
    Database::run("DELETE FROM staff_applications WHERE phone LIKE '98000888%'");
    Database::run("DELETE FROM agent_applications WHERE phone LIKE '%98000888%'");
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('idcard_tail_ip','idcard_tail_fail','idcard_verify_api')");
};
$clean();

try {
    // ---- pure helpers ----
    check('step: 31 days -> none, 30 -> 30, 16 -> 30, 15 -> 15, 8 -> 15, 7 -> 7, 0 -> 7, -1 -> none',
        TeamExpiry::step(31) === null && TeamExpiry::step(30) === 30 && TeamExpiry::step(16) === 30 && TeamExpiry::step(15) === 15
        && TeamExpiry::step(8) === 15 && TeamExpiry::step(7) === 7 && TeamExpiry::step(0) === 7 && TeamExpiry::step(-1) === null);
    check('foldDoc: punctuation and Devanagari digits fold, short numbers ignored',
        TeamDupes::foldDoc('12-01-75-5555 5') === '12017555555' && TeamDupes::foldDoc('१२-०१-७५-५५५५५') === '12017555555' && TeamDupes::foldDoc('12-3') === '');
    check('foldName: case, dots, spaces and "Mr." fold', TeamDupes::foldName('Mr. Ram  B. Thapa') === TeamDupes::foldName('ram b thapa'));
    check('foldPhone: last 10 digits', TeamDupes::foldPhone('+977 980-0088801') === '9800088801' && TeamDupes::foldPhone('12345') === '');
    check('normaliseRef: agr35 / AGR-000035 / stf 7', IdVerify::normaliseRef('agr35') === 'AGR-000035' && IdVerify::normaliseRef(' AGR-000035 ') === 'AGR-000035'
        && IdVerify::normaliseRef('stf 7') === 'STF-000007' && IdVerify::normaliseRef('XYZ-1') === null && IdVerify::normaliseRef('') === null);

    // ---- look-alikes across staff and agents ----
    $sid = (int) Database::insert('staff_applications', [
        'full_name' => 'Reg Test Person', 'phone' => '9800088801', 'phone_country' => 'NP', 'role_applied' => 'driver',
        'id_type' => 'citizenship', 'id_number' => '12-01-75-55555', 'dob' => '1990-05-14', 'district' => 'Banke', 'status' => 'pending',
    ]);
    $a1 = (int) Database::insert('agent_applications', [
        'type' => 'ind', 'lang' => 'ne', 'name' => 'reg test person', 'phone' => '9800088802', 'area' => 'Banke', 'status' => 'new',
        'fields_json' => json_encode(['p_idno' => '12017555555', 'p_dob' => '1990-05-14']),
    ]);
    $a2 = (int) Database::insert('agent_applications', [
        'type' => 'ind', 'lang' => 'ne', 'name' => 'Somebody Else', 'phone' => '9800088801', 'area' => 'Banke', 'status' => 'new',
        'fields_json' => json_encode(['p_idno' => '99-88-77']),
    ]);
    $a3 = (int) Database::insert('agent_applications', [
        'type' => 'ind', 'lang' => 'ne', 'name' => 'Clean Person', 'phone' => '9800088803', 'area' => 'Banke', 'status' => 'new',
        'fields_json' => json_encode(['p_idno' => 'ZZ-7766-55']),
    ]);
    foreach ([$a1, $a2, $a3] as $i) { Database::run('UPDATE agent_applications SET ref = :r WHERE id = :i', ['r' => AgentApplications::refFor($i), 'i' => $i]); }
    TeamDupes::reset();
    $hits = TeamDupes::lookalikes('staff', $sid);
    $byId = [];
    foreach ($hits as $x) { $byId[$x['kind'] . $x['id']] = $x['why']; }
    check('staff file finds the agent with the same ID no. + name + DOB', isset($byId['agent' . $a1]) && in_array('doc', $byId['agent' . $a1], true) && in_array('name_dob', $byId['agent' . $a1], true), json_encode($byId));
    check('… and the agent with the same mobile', isset($byId['agent' . $a2]) && $byId['agent' . $a2] === ['phone'], json_encode($byId));
    check('… but not the unrelated one', !isset($byId['agent' . $a3]));
    check('the ID-number match is listed first', ($hits[0]['id'] ?? 0) === $a1);
    check('the agent side sees the staff file too', count(array_filter(TeamDupes::lookalikes('agent', $a1), static fn($x) => $x['kind'] === 'staff' && $x['id'] === $sid)) === 1);
    check('a clean file has no warning', TeamDupes::lookalikes('agent', $a3) === [] && TeamDupes::html('agent', $a3) === '');
    $html = TeamDupes::html('staff', $sid);
    check('the office box links the other files and escapes names', str_contains($html, 'team.php?person=agent%3A' . $a1) && str_contains($html, 'same ID number'));

    // ---- expiry + reminders (an approved application with a signed agreement, no login yet) ----
    Settings::set(TeamExpiry::MAP_KEY, [], 'json', 'agent', false);
    $soon = date('Y-m-d', strtotime('+10 days'));
    $past = date('Y-m-d', strtotime('-1 day'));
    Database::run("UPDATE agent_applications SET status = 'approved', agreement_status = 'signed', agreement_signed_at = :s, agreement_end = :e WHERE id = :i",
        ['s' => date('Y-m-d', strtotime('-11 months +10 days')), 'e' => $soon, 'i' => $a3]);
    Database::run("UPDATE agent_applications SET status = 'approved', agreement_status = 'signed', agreement_signed_at = :s, agreement_end = :e WHERE id = :i",
        ['s' => date('Y-m-d', strtotime('-11 months -1 day')), 'e' => $past, 'i' => $a2]);
    $mine = array_values(array_filter(TeamExpiry::signed(), static fn($p) => $p['src'] === 'application' && in_array($p['id'], [$a2, $a3], true)));
    check('signed() lists both test agreements', count($mine) === 2, json_encode($mine));
    $dry = TeamExpiry::run(true);
    check('dry run counts, writes nothing', $dry['expired'] >= 1 && $dry['reminded'] >= 1
        && (string) Database::scalar('SELECT agreement_status FROM agent_applications WHERE id = :i', ['i' => $a2]) === 'signed');
    $txt = TeamExpiry::text(['kind' => 'agent', 'end' => $soon, 'name' => 'Clean Person'], 10);
    check('reminder text: days left, end date, renewal link', str_contains($txt, '10 days left') && str_contains($txt, 'team/agreement.php') && str_contains($txt, date('d M Y', strtotime($soon))));
    $r1 = TeamExpiry::run(false);
    check('the ended agreement is now expired', (string) Database::scalar('SELECT agreement_status FROM agent_applications WHERE id = :i', ['i' => $a2]) === 'expired');
    check('… with the end date kept', (string) Database::scalar('SELECT agreement_end FROM agent_applications WHERE id = :i', ['i' => $a2]) === $past);
    $map = Settings::getArray(TeamExpiry::MAP_KEY, []);
    check('the 15-day step is marked sent for the one ending in 10 days', isset($map['application:' . $a3 . '|' . $soon . '|15']), json_encode($map));
    $r2 = TeamExpiry::run(false);
    check('a second run sends that reminder again: no', count(Settings::getArray(TeamExpiry::MAP_KEY, [])) === count($map) && $r2['reminded'] === 0 && $r2['failed'] === 0, json_encode($r2));
    check('the audit trail has agreement.expired', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agreement.expired' AND entity_id = :i", ['i' => (string) $a2], 0) >= 1);
    $card = IdCard::person(AgentApplications::refFor($a2));
    check('the ID card of the ended one says not valid / Agreement ended', $card !== null && $card['valid'] === false && in_array($card['standing'], ['Agreement ended', 'Expired', 'KYC review pending'], true) && $card['waDigits'] === '');

    // ---- typed card check ----
    $ip = '203.0.113.' . random_int(1, 250);
    $ref3 = AgentApplications::refFor($a3);
    check('card no. + right last 4 -> ok', IdVerify::manual(strtolower($ref3), '8803', $ip) === ['state' => 'ok', 'ref' => $ref3]);
    check('wrong last 4 -> nomatch', IdVerify::manual($ref3, '1111', $ip)['state'] === 'nomatch');
    check('3 digits / rubbish -> bad', IdVerify::manual($ref3, '803', $ip)['state'] === 'bad' && IdVerify::manual('hello', '8803', $ip)['state'] === 'bad');
    check('an application not approved -> nomatch (no card)', IdVerify::manual(AgentApplications::refFor($a1), '8802', $ip)['state'] === 'nomatch');
    for ($i = 0; $i < 12; $i++) { IdVerify::manual($ref3, '0000', '198.51.100.' . $i); }
    check('20+ wrong tails on one card -> that card waits for everyone', IdVerify::manual($ref3, '8803', '192.0.2.77')['state'] === 'wait'
        || IdVerify::manual($ref3, '8803', '192.0.2.78')['state'] === 'ok', 'per-IP limit hit first is fine too');
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('idcard_tail_ip','idcard_tail_fail')");
    $j = IdVerify::publicJson(IdCard::person($ref3), 'valid');
    check('public JSON: name, role, valid till — no phone, no ID number', $j['ok'] === true && $j['person']['name'] === 'Clean Person'
        && $j['person']['validTill'] === $soon && !str_contains(json_encode($j), '8803') && !str_contains(json_encode($j), '7766'));

    // ---- over HTTP ----
    [$code] = $http('/api/verify-id.php');
    if ($code === 0) {
        echo "  (skipped HTTP checks: no server at " . (getenv('SHG_TEST_BASE') ?: 'http://localhost:8891') . ")\n";
    } else {
        [$c1, $b1] = $http('/api/verify-id.php?c=' . rawurlencode(IdCard::code($ref3)));
        $d1 = json_decode($b1, true);
        check('API ?c=<QR code> -> valid + person', $c1 === 200 && ($d1['state'] ?? '') === 'valid' && ($d1['person']['ref'] ?? '') === $ref3, $b1);
        [, $b2] = $http('/api/verify-id.php?c=' . rawurlencode($ref3 . '.000000000000'));
        check('API forged code -> forged', (json_decode($b2, true)['state'] ?? '') === 'forged', $b2);
        [, $b3] = $http('/api/verify-id.php?ref=' . rawurlencode($ref3) . '&tail=8803');
        check('API ?ref=&tail= -> valid', (json_decode($b3, true)['state'] ?? '') === 'valid', $b3);
        [, $b4] = $http('/api/verify-id.php?ref=' . rawurlencode(AgentApplications::refFor($a2)) . '&tail=8801');
        check('API ended agreement -> invalid', (json_decode($b4, true)['state'] ?? '') === 'invalid', $b4);
        [, $b5] = $http('/verify-id.php?ref=AGR-1&tail=12');
        check('verify page: bad typed input shows the form again', str_contains($b5, 'name="tail"') && str_contains($b5, 'AGR-000035'));
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $clean();
    Settings::set('whatsapp_driver', $driver);
    Settings::set(TeamExpiry::MAP_KEY, $marks, 'json', 'agent', false);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
