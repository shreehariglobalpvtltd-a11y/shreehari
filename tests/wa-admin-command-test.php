<?php
/**
 * =====================================================================
 *  wa-admin-command-test.php — WhatsApp commands (26 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/wa-admin-command-test.php
 *
 *  Guards the command block in includes/wabot.php:
 *    - office commands do nothing while wa_admin_commands_on is off
 *    - VERIFY from a number that is not staff is just a status check
 *    - a staff role without payments.verify is refused; an agent is ignored
 *    - VERIFY / REJECT (reason required) from a manager's own number work
 *    - CANCEL never cancels: the owner is told the office will call, a
 *      stranger learns nothing
 *    - STATUS <PNR> from the owner answers like the bare PNR
 *    - (27 Sep) an account that still owes its first password, or two
 *      accounts on one handset, command nothing; with step-up on, a money
 *      command asks for the panel link first and works once it is fresh
 *
 *  Creates its own tagged bookings and admins and removes them.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/wabot.php';

$pass = 0; $fail = 0;
function wa_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$saved = [
    'wa_admin_commands_on' => Settings::getString('wa_admin_commands_on', '0'),
    'wa_local_first'       => Settings::getString('wa_local_first', '1'),
    'wa_agent_on'          => Settings::getString('wa_agent_on', '0'),
    'wa_ai_enabled'        => Settings::getString('wa_ai_enabled', '1'),
    'wa_ops_stepup_on'     => Settings::getString('wa_ops_stepup_on', '0'),
];
$savedActions = Settings::getString('wa_ops_stepup_actions', '');

$cleanup = static function (): void {
    Database::run("DELETE FROM bookings WHERE pnr LIKE 'SHG-WACMD-%'");
    Database::run("DELETE FROM admins WHERE username LIKE 'wacmd_%'");
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('wa_office_cmd','wa_ticket_code_try','wa_stepup_link') OR bucket LIKE 'notify_%'");
    try { Database::run("DELETE FROM wa_identity_links WHERE phone LIKE '97798110000%'"); } catch (Throwable $e) {}
};
$mkAdmin = static fn (string $name, string $role, string $phone): int => Database::insert('admins', [
    'username' => 'wacmd_' . $name, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
    'full_name' => 'WA ' . $name, 'role' => $role, 'is_active' => 1, 'phone' => $phone, 'must_change_pw' => 0,
]);
$mkBooking = static function (string $suffix, string $phone): int {
    $id = Database::insert('bookings', [
        'pnr' => 'SHG-WACMD-' . $suffix, 'contact_phone' => $phone, 'status' => 'pending', 'total_amount' => 500,
    ]);
    Database::insert('payments', ['booking_id' => $id, 'payment_ref' => 'PAY-WACMD-' . $suffix, 'amount' => 500, 'status' => 'pending']);
    return $id;
};
$status = static fn (int $bid): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bid], '');

$cleanup();
try {
    // Keep the model and the local booking engine out of the way.
    Settings::set('wa_local_first', '0', 'bool', 'whatsapp');
    Settings::set('wa_agent_on', '0', 'bool', 'whatsapp');
    Settings::set('wa_ai_enabled', '0', 'bool', 'whatsapp');

    $mgrPhone = '9779811000001';
    $supPhone = '9779811000002';
    $agtPhone = '9779811000003';
    $owner    = '9779811000009';
    $mkAdmin('mgr', 'manager', '+' . $mgrPhone);
    $mkAdmin('sup', 'support', $supPhone);
    $mkAdmin('agt', 'agent', $agtPhone);

    $b1 = $mkBooking('0001', $owner);
    $pnr1 = 'SHG-WACMD-0001';

    // Switch off
    Settings::set('wa_admin_commands_on', '0', 'bool', 'whatsapp');
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'VERIFY ' . $pnr1);
    wa_check('commands are off by default: VERIFY does nothing', $status($b1) === 'pending', mb_substr($r['text'], 0, 60));

    Settings::set('wa_admin_commands_on', '1', 'bool', 'whatsapp');

    // Not staff
    $r = WaBot::reply('whatsapp:+9779800099999', 'VERIFY ' . $pnr1);
    wa_check('VERIFY from a stranger is only a status check', $status($b1) === 'pending' && str_contains($r['text'], $pnr1));

    // Support role lacks payments.verify
    $r = WaBot::reply('whatsapp:+' . $supPhone, 'VERIFY ' . $pnr1);
    wa_check('a role without payments.verify is refused', $status($b1) === 'pending' && str_contains($r['text'], 'cannot'), mb_substr($r['text'], 0, 60));

    // Agent is not an office commander
    $r = WaBot::reply('whatsapp:+' . $agtPhone, 'VERIFY ' . $pnr1);
    wa_check('an agent number is ignored', $status($b1) === 'pending');

    // Manager
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'verify ' . $pnr1);
    wa_check('VERIFY from a manager\'s own number confirms', $status($b1) === 'confirmed', mb_substr($r['text'], 0, 60));
    $audit = Database::exists("SELECT 1 FROM audit_logs WHERE action = 'booking.wa_command' AND entity_id = :p", ['p' => $pnr1]);
    wa_check('...and is audited', $audit);

    $b2 = $mkBooking('0002', $owner);
    $pnr2 = 'SHG-WACMD-0002';
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'REJECT ' . $pnr2);
    wa_check('REJECT without a reason asks for one', $status($b2) === 'pending' && str_contains($r['text'], 'reason'));
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'REJECT ' . $pnr2 . ' UTR not received');
    wa_check('REJECT with a reason rejects', $status($b2) === 'rejected', mb_substr($r['text'], 0, 60));

    // CANCEL
    $b3 = $mkBooking('0003', $owner);
    $pnr3 = 'SHG-WACMD-0003';
    $r = WaBot::reply('whatsapp:+' . $owner, 'CANCEL ' . $pnr3);
    wa_check('CANCEL from the owner never cancels', $status($b3) === 'pending' && str_contains($r['text'], 'रद्द भएको छैन'));
    $r = WaBot::reply('whatsapp:+9779800099999', 'CANCEL ' . $pnr3);
    wa_check('CANCEL from a stranger reveals nothing', $status($b3) === 'pending' && !str_contains($r['text'], 'PENDING') && str_contains($r['text'], '🔒'));

    // STATUS
    $r = WaBot::reply('whatsapp:+' . $owner, 'STATUS ' . $pnr3);
    wa_check('STATUS <PNR> from the owner answers like the bare PNR', str_contains($r['text'], 'PNR: ' . $pnr3));

    // 27 Sep 2026: an account that has never set its password commands nothing
    $newPhone = '9779811000004';
    $newId = $mkAdmin('new', 'manager', $newPhone);
    Database::run('UPDATE admins SET must_change_pw = 1 WHERE id = :i', ['i' => $newId]);
    $b4 = $mkBooking('0004', $owner);
    $pnr4 = 'SHG-WACMD-0004';
    WaBot::reply('whatsapp:+' . $newPhone, 'VERIFY ' . $pnr4);
    wa_check('a manager who still owes the first password confirms nothing', $status($b4) === 'pending');

    // ...nor do two accounts sharing one handset
    $twinPhone = '9779811000005';
    $mkAdmin('twin1', 'manager', $twinPhone);
    $mkAdmin('twin2', 'manager', '+' . $twinPhone);
    WaBot::reply('whatsapp:+' . $twinPhone, 'VERIFY ' . $pnr4);
    wa_check('two accounts on one handset confirm nothing', $status($b4) === 'pending');

    // Step-up on: the link first, the action once it is fresh
    Settings::set('wa_ops_stepup_on', '1', 'bool', 'ai');
    Settings::set('wa_ops_stepup_actions', 'office_confirm,office_reject,office_settle_cod', 'string', 'ai');
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'VERIFY ' . $pnr4);
    wa_check('with step-up on, VERIFY asks for the panel link first', $status($b4) === 'pending'
        && (str_contains($r['text'], 'wa-verify.php?t=') || str_contains($r['text'], 'Verif')), mb_substr($r['text'], 0, 70));
    $mgrId = (int) Database::scalar("SELECT id FROM admins WHERE username = 'wacmd_mgr'", [], 0);
    Database::run('UPDATE wa_identity_links SET admin_id = :a, verified_at = NOW(), verified_via = :v WHERE phone = :p',
        ['a' => $mgrId, 'v' => 'panel_link', 'p' => normalisePhone($mgrPhone)]);
    $r = WaBot::reply('whatsapp:+' . $mgrPhone, 'VERIFY ' . $pnr4);
    wa_check('...and confirms once the verification is fresh', $status($b4) === 'confirmed', mb_substr($r['text'], 0, 60));
} catch (Throwable $e) {
    wa_check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($saved as $k => $v) { Settings::set($k, $v, 'bool', $k === 'wa_ops_stepup_on' ? 'ai' : 'whatsapp'); }
    if ($savedActions === '') { Database::run("DELETE FROM settings WHERE skey = 'wa_ops_stepup_actions'"); }
    else { Settings::set('wa_ops_stepup_actions', $savedActions, 'string', 'ai'); }
    $cleanup();
}

echo "\n  wa-admin-command: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
