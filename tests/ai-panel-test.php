<?php
/**
 * =====================================================================
 *  ai-panel-test.php — the assistant's SECOND door (28 Sep 2026).
 *
 *  admin/ai-agent.php lets the office ask the assistant from a chair
 *  instead of a handset. The messages are not what can hurt anyone; the
 *  DOOR is. On WhatsApp the identity is a phone number that matched a
 *  row in `admins`. In the panel it is a session — and a session proves
 *  only that a password was typed, not that the account behind it is fit
 *  to hold office tools.
 *
 *  So this suite tests AiTools::whoIsAdmin() the way wa-agent-test.php
 *  tests whoIs():
 *
 *    • ROLE        superadmin and manager reach the office tools, an
 *                  agent is scoped to their own book, and a role that
 *                  merely holds dashboard.view (accountant, support)
 *                  reaches NOTHING
 *    • LIVENESS    suspended, locked, or still on the first temporary
 *                  password — each one is refused, session or no session
 *    • /api/       the must_change_pw case is the one that matters most:
 *                  Auth::requireAdmin() waves a temp-password account
 *                  through on a /api/ URL, and the endpoint IS a /api/
 *                  URL, so the refusal has to come from here
 *    • STAGING     a panel ctx and a WhatsApp ctx for the SAME person
 *                  file their parked quote under different keys, so a
 *                  fare quoted on one channel cannot be confirmed on the
 *                  other without the second message
 *    • SWITCH      ai_panel_on = 0 and the door does not open at all
 *
 *  Creates throwaway admins (username prefix 'aip-') and removes them.
 *      php -c .claude/php-dev.ini tests/ai-panel-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';

const AIP_PREFIX = 'aip-';
const AIP_PHONE  = '9100007001';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== The admin AI panel — who gets through the door ===\n\n";

/* ---- switches this suite drives; restored exactly as found ----------- */
$PINNED = ['ai_panel_on', 'ai_panel_daily_cap', 'ai_panel_max_tools', 'ai_admin_tools_deep'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try {
                Database::update('settings', [
                    'svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public'],
                ], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    Settings::flush();
};

/**
 * One throwaway staff row. Every field the gates read is explicit, so a
 * default changing in schema.sql cannot quietly make a case pass.
 *
 * NOTE the role ENUM is ('superadmin','manager','accountant','support',
 * 'scanner','official','agent') — there is no 'counter' row to make, even
 * though whoIs()/whoIsAdmin() both name it defensively.
 */
$mk = static function (string $slug, string $role, array $over = []) : int {
    $username = AIP_PREFIX . $slug;
    $row = array_merge([
        'username'       => $username,
        'password_hash'  => password_hash('Aip@123456', PASSWORD_BCRYPT),
        'full_name'      => 'AI Panel ' . $slug,
        'role'           => $role,
        'phone'          => AIP_PHONE,
        'is_active'      => 1,
        'must_change_pw' => 0,
        'locked_until'   => null,
    ], $over);

    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', $row);
    }
    unset($row['username'], $row['password_hash']);
    Database::update('admins', $row, 'id = :i', ['i' => $id]);
    return $id;
};

$cleanup = static function () : void {
    try { Database::delete('ai_agent_calls', "phone LIKE :p", ['p' => AIP_PHONE . '%']); } catch (Throwable $e) {}
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE :u", ['u' => AIP_PREFIX . '%']) as $r) {
        try { Database::delete('kv_store', 'kkey = :k', ['k' => 'panel:' . (int) $r['id']]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :i', ['i' => (int) $r['id']]); } catch (Throwable $e) {}
    }
};
$cleanup();

try {
    /* =================================================================
     *  ROLE — what each kind of account is allowed to be
     * ================================================================= */
    echo "-- role mapping --\n";

    $bossId = $mk('boss', 'superadmin');
    $boss   = AiTools::whoIsAdmin($bossId);
    check('a superadmin is the office', $boss['role'] === 'admin', 'got ' . $boss['role']);
    check('the office is not scoped to one book', $boss['scopeAdminId'] === null);
    check('the phone is kept real, so ai_agent_calls stays truthful',
        $boss['phone'] === normalisePhone(AIP_PHONE), 'got "' . $boss['phone'] . '"');
    check('a panel ctx files its quote under panel:<id>',
        $boss['stageKey'] === 'panel:' . $bossId, 'got ' . $boss['stageKey']);

    $mgrId = $mk('mgr', 'manager');
    check('a manager is the office', AiTools::whoIsAdmin($mgrId)['role'] === 'admin');

    $agentId = $mk('agent', 'agent');
    $agent   = AiTools::whoIsAdmin($agentId);
    check('a counter agent is staff, not the office', $agent['role'] === 'staff', 'got ' . $agent['role']);
    check('a counter agent is scoped to their own book', $agent['scopeAdminId'] === $agentId);

    /* The escalation case. Both of these can sign in and both hold
       dashboard.view, which is all admin/ai-agent.php asks of them — so if
       whoIsAdmin() did not carry its own role allow-list, they would reach
       office tools they cannot reach anywhere else in the panel. */
    echo "\n-- a signed-in session is not a licence --\n";
    foreach (['acct' => 'accountant', 'supp' => 'support', 'scan' => 'scanner', 'offi' => 'official'] as $slug => $role) {
        $id = $mk($slug, $role);
        check("a {$role} reaches no tools at all",
            AiTools::whoIsAdmin($id)['role'] === 'customer', 'got ' . AiTools::whoIsAdmin($id)['role']);
    }

    /* =================================================================
     *  LIVENESS — every gate whoIs() applies, applied here too
     * ================================================================= */
    echo "\n-- liveness --\n";

    $offId = $mk('off', 'superadmin', ['is_active' => 0]);
    check('a suspended superadmin is refused', AiTools::whoIsAdmin($offId)['role'] === 'customer');

    // THE /api/ CASE. Auth::requireAdmin() skips its must_change_pw
    // redirect when the URL contains '/api/', and admin/api/ai-agent.php
    // is such a URL. If this ever goes green-to-red, a temporary password
    // handed out over the phone reaches the office tools.
    $tmpId = $mk('tmp', 'superadmin', ['must_change_pw' => 1]);
    check('a superadmin still on a temporary password is refused',
        AiTools::whoIsAdmin($tmpId)['role'] === 'customer');

    $lockId = $mk('lock', 'superadmin', ['locked_until' => date('Y-m-d H:i:s', time() + 3600)]);
    check('a locked superadmin is refused', AiTools::whoIsAdmin($lockId)['role'] === 'customer');

    $pastId = $mk('past', 'superadmin', ['locked_until' => date('Y-m-d H:i:s', time() - 3600)]);
    check('a lock that has EXPIRED does not refuse', AiTools::whoIsAdmin($pastId)['role'] === 'admin');

    check('id 0 is refused', AiTools::whoIsAdmin(0)['role'] === 'customer');
    check('a negative id is refused', AiTools::whoIsAdmin(-5)['role'] === 'customer');
    $ghost = (int) Database::scalar('SELECT COALESCE(MAX(id),0) + 9999 FROM admins', [], 999999);
    check('an id that is not an account is refused', AiTools::whoIsAdmin($ghost)['role'] === 'customer');

    /* =================================================================
     *  STAGING — the panel and the phone are two conversations
     * ================================================================= */
    echo "\n-- a quote parked on one channel is not confirmable on the other --\n";

    $stageKey = new ReflectionMethod(AiTools::class, 'stageKey');
    $stageKey->setAccessible(true);

    $panelCtx = AiTools::whoIsAdmin($bossId);
    $waCtx    = AiTools::whoIs(AIP_PHONE);
    $kPanel   = (string) $stageKey->invoke(null, $panelCtx);
    $kWa      = (string) $stageKey->invoke(null, $waCtx);

    check('the panel key is panel:<id>', $kPanel === 'panel:' . $bossId, 'got ' . $kPanel);
    check('a WhatsApp ctx still keys on the phone, unchanged',
        $kWa === normalisePhone(AIP_PHONE), 'got ' . $kWa);
    check('the two channels do NOT share a parked quote', $kPanel !== $kWa);
    check('a ctx with neither falls back to an empty key, never a crash',
        (string) $stageKey->invoke(null, []) === '');

    /* =================================================================
     *  SWITCH — off means off
     * ================================================================= */
    echo "\n-- the switch --\n";

    Settings::set('ai_panel_on', '0', 'bool', 'ai', false);
    Settings::flush();
    check('ai_panel_on = 0 keeps the door shut whatever keys are set',
        AiAgent::panelEnabled() === false);
    check('and handlePanel() answers nothing at all',
        AiAgent::handlePanel($bossId, 'aaja kasto cha?') === null);

    /* =================================================================
     *  CATALOGUE — the panel reaches the same office tools as a phone
     * ================================================================= */
    echo "\n-- the tools the panel can reach --\n";

    $names = static fn(array $ctx): array => array_map(
        static fn(array $t): string => (string) ($t['name'] ?? ''),
        AiTools::catalogue($ctx)
    );

    $bossTools  = $names(AiTools::whoIsAdmin($bossId));
    $agentTools = $names(AiTools::whoIsAdmin($agentId));

    check('the office reaches office_day from the panel', in_array('office_day', $bossTools, true));
    check('the office reaches office_alerts from the panel', in_array('office_alerts', $bossTools, true));
    check('a counter agent does NOT reach office_day', !in_array('office_day', $agentTools, true));
    check('a refused account reaches no catalogue worth the name',
        !in_array('office_day', $names(AiTools::whoIsAdmin($mk('acct', 'accountant'))), true));

    /* The prompt used to name four marketing tools that do not exist, so
       the assistant argued with the office about buttons it had been told
       it owned. Keep them gone until the tools are real. */
    echo "\n-- the prompt promises nothing the catalogue cannot do --\n";
    $prompt = (string) file_get_contents(INCLUDE_PATH . '/aiagent.php');
    $code   = (string) file_get_contents(INCLUDE_PATH . '/aitools.php');
    foreach (['marketing_draft', 'marketing_preview', 'marketing_send', 'marketing_status'] as $t) {
        $inPrompt = str_contains($prompt, '"' . $t) || str_contains($prompt, $t . ':');
        $inTools  = str_contains($code, "'" . $t . "'");
        check("{$t} is either a real tool or unmentioned", !$inPrompt || $inTools);
    }
} catch (Throwable $e) {
    check('suite ran to the end', false, $e->getMessage());
} finally {
    $restoreSettings();
    $cleanup();
}

echo "\n" . ($FAIL === 0
        ? "\033[32mALL {$PASS} CHECKS PASSED\033[0m\n\n"
        : "\033[31m{$FAIL} FAILED\033[0m, {$PASS} passed\n\n");
exit($FAIL === 0 ? 0 : 1);
