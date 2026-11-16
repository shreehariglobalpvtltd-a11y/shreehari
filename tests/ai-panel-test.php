<?php
/**
 * =====================================================================
 *  ai-panel-test.php — the assistant's STAFF door (28 Sep 2026).
 *
 *  The office asks the assistant from a chair instead of a handset. The
 *  messages are not what can hurt anyone; the DOOR is. On WhatsApp the
 *  identity is a phone number that matched a row in `admins`. From the
 *  panel it is a session — and a session proves only that a password was
 *  typed, not that the account behind it is fit to hold office tools.
 *
 *  16 Nov 2026 (battery Y7): the separate panel door this suite was first
 *  written for (admin/ai-agent.php + AiTools::whoIsAdmin(), AiAgent::
 *  panelEnabled() / handlePanel(), switch ai_panel_on) is not part of this
 *  code line. Staff reach the assistant through the staff AI bubble ->
 *  api/ai-chat.php, whose identity comes ONLY from AiTools::whoIsWeb()
 *  reading the signed-in admin session (24 Sep 2026). The same properties
 *  are tested on THAT door:
 *
 *    • ROLE        superadmin and manager reach the office tools, an
 *                  agent is scoped to their own book, and a role that
 *                  merely signs in (accountant, support, scanner,
 *                  official) reaches NOTHING of the staff side
 *    • LIVENESS    suspended, locked, deleted, or still on the first
 *                  temporary password — each one is refused, session or
 *                  no session (a real session re-reads its admins row at
 *                  least every 30 s - Auth::admin() - so the sessions here
 *                  are built as a sign-in builds them, past that clock)
 *    • /api/       the must_change_pw case is the one that matters most:
 *                  api/ai-chat.php IS a /api/ URL and never calls
 *                  Auth::requireAdmin(), so the refusal has to come from
 *                  the identity itself
 *    • STAGING     a panel ctx and a WhatsApp ctx for the SAME person
 *                  file their parked quote under different keys, so a
 *                  fare quoted on one channel cannot be confirmed on the
 *                  other without the second message
 *    • SWITCH      ai_web_agent_on = 0 and the door does not open at all
 *    • CATALOGUE   the office tools reach the office only
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

echo "\n=== The staff AI door (api/ai-chat.php -> AiTools::whoIsWeb) — who gets through ===\n\n";

/* ---- switches this suite drives; restored exactly as found ----------- */
$PINNED = ['ai_web_agent_on', 'wa_local_brain_on', 'wa_report_chart_on'];
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
 * default changing in the schema cannot quietly make a case pass.
 *
 * NOTE the role ENUM is ('superadmin','manager','accountant','support',
 * 'scanner','official','agent') — there is no 'counter' row to make, even
 * though whoIs()/whoIsWeb() both name it defensively.
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

/**
 * The session a sign-in builds (Auth: id, role, permissions, must_change_pw,
 * via, a checked_at clock) with that clock past 30 s, so Auth::admin()
 * re-reads the admins row exactly as it does on a live request - liveness is
 * judged from the row, never from what the session happened to remember.
 * A session for an id with no row stands for an account deleted under it.
 */
$sessionFor = static function (int $id, array $over = []): void {
    $row = $id > 0 ? Database::fetch('SELECT id, username, full_name, role, permissions, must_change_pw FROM admins WHERE id = :i', ['i' => $id]) : null;
    $_SESSION[ADMIN_SESSION_KEY] = $over + [
        'id'             => $id,
        'username'       => (string) ($row['username'] ?? 'aip-ghost'),
        'full_name'      => (string) ($row['full_name'] ?? 'AI Panel ghost'),
        'role'           => (string) ($row['role'] ?? 'superadmin'),
        'permissions'    => $row !== null ? jsonColumn($row['permissions']) : [],
        'must_change_pw' => $row !== null && (int) $row['must_change_pw'] === 1,
        'via_otp'        => false,
        'via'            => 'password',
        'logged_in_at'   => time() - 120,
        'last_seen'      => time(),
        'checked_at'     => time() - 60,
    ];
};
/** whoIsWeb() for this account's session, as api/ai-chat.php asks it. */
$who = static function (int $id, array $over = []) use ($sessionFor): array {
    $sessionFor($id, $over);
    $ctx = AiTools::whoIsWeb();
    unset($_SESSION[ADMIN_SESSION_KEY]);
    return $ctx;
};

$cleanup = static function () : void {
    try { Database::delete('ai_agent_calls', "phone LIKE :p", ['p' => AIP_PHONE . '%']); } catch (Throwable $e) {}
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE :u", ['u' => AIP_PREFIX . '%']) as $r) {
        $id = (int) $r['id'];
        try { Database::delete('kv_store', 'kkey = :k', ['k' => 'panel:' . $id]); } catch (Throwable $e) {}
        try { Database::delete('ai_agent_calls', 'phone = :p', ['p' => 'web-staff-' . $id]); } catch (Throwable $e) {}
        try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
        try { Database::delete('audit_logs', "action = 'admin.session_revoked' AND entity_id = :a", ['a' => (string) $id]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :i', ['i' => $id]); } catch (Throwable $e) {}
    }
};
$cleanup();
$ghost = 0;

try {
    /* =================================================================
     *  ROLE — what each kind of account is allowed to be
     * ================================================================= */
    echo "-- role mapping --\n";

    $bossId = $mk('boss', 'superadmin');
    $boss   = $who($bossId);
    check('a superadmin is the office', $boss['role'] === 'admin', 'got ' . $boss['role']);
    check('the office is not scoped to one book', $boss['scopeAdminId'] === null);
    /* The phone the panel used to keep real is what ai_agent_calls is keyed
       on. A web session carries no number, so AiTools logs the stage key
       instead - run one read-only office tool and read the row it wrote: it
       must name THIS account, never another number or a guest key. */
    $sessionFor($bossId);
    $bossLive = AiTools::whoIsWeb();
    $tLog     = date('Y-m-d H:i:s', time() - 1);
    AiTools::run('office_day', [], $bossLive);
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $logged = Database::fetch("SELECT phone, role, channel FROM ai_agent_calls WHERE tool = 'office_day' AND created_at >= :t ORDER BY id DESC LIMIT 1", ['t' => $tLog]);
    check('the call log names the account (its number, else web-staff-<id>), so ai_agent_calls stays truthful',
        $logged !== null && in_array((string) $logged['phone'], array_filter([normalisePhone(AIP_PHONE), 'web-staff-' . $bossId]), true)
        && (string) $logged['role'] === 'admin' && (string) $logged['channel'] === 'web',
        $logged === null ? 'no ai_agent_calls row written' : 'logged as "' . $logged['phone'] . '" / ' . $logged['role'] . ' / ' . $logged['channel']);
    check('a panel ctx files its quote under its own staff key (web-staff-<id>)',
        $boss['stageKey'] === 'web-staff-' . $bossId, 'got ' . $boss['stageKey']);

    $mgrId = $mk('mgr', 'manager');
    check('a manager is the office', $who($mgrId)['role'] === 'admin');

    $agentId = $mk('agent', 'agent');
    $agent   = $who($agentId);
    check('a counter agent is staff, not the office', $agent['role'] === 'staff', 'got ' . $agent['role']);
    check('a counter agent is scoped to their own book', $agent['scopeAdminId'] === $agentId);

    /* The escalation case. These roles can sign in, so if whoIsWeb() did not
       carry its own role allow-list they would reach office tools they cannot
       reach anywhere else in the panel. */
    echo "\n-- a signed-in session is not a licence --\n";
    foreach (['acct' => 'accountant', 'supp' => 'support', 'scan' => 'scanner', 'offi' => 'official'] as $slug => $role) {
        $id  = $mk($slug, $role);
        $ctx = $who($id);
        check("a {$role} reaches no staff tools at all", $ctx['role'] === 'customer' && (int) $ctx['adminId'] === 0, 'got ' . $ctx['role']);
    }

    /* =================================================================
     *  LIVENESS — every gate whoIs() applies, applied here too
     * ================================================================= */
    echo "\n-- liveness --\n";

    $offId = $mk('off', 'superadmin', ['is_active' => 0]);
    check('a suspended superadmin is refused', $who($offId)['role'] === 'customer');

    // THE /api/ CASE. Auth::requireAdmin() refuses a temporary password on a
    // /api/ URL (4 Oct 2026) - but api/ai-chat.php never calls it, it asks
    // whoIsWeb() alone. If this is red, a temporary password handed out over
    // the phone reaches the office tools without ever being changed.
    $tmpId = $mk('tmp', 'superadmin', ['must_change_pw' => 1]);
    check('a superadmin still on a temporary password is refused',
        $who($tmpId)['role'] === 'customer', 'got ' . $who($tmpId)['role']);

    $lockId = $mk('lock', 'superadmin', ['locked_until' => date('Y-m-d H:i:s', time() + 3600)]);
    check('a locked superadmin is refused', $who($lockId)['role'] === 'customer');

    $pastId = $mk('past', 'superadmin', ['locked_until' => date('Y-m-d H:i:s', time() - 3600)]);
    check('a lock that has EXPIRED does not refuse', $who($pastId)['role'] === 'admin');

    unset($_SESSION[ADMIN_SESSION_KEY]);
    check('no session at all is refused', AiTools::whoIsWeb()['role'] === 'customer');
    $ghost = (int) Database::scalar('SELECT COALESCE(MAX(id),0) + 9999 FROM admins', [], 999999);
    check('a session whose account no longer exists is refused', $who($ghost)['role'] === 'customer');
    check('api/ai-chat.php takes the identity from the session, never from the message',
        substr_count((string) file_get_contents(dirname(__DIR__) . '/api/ai-chat.php'), 'AiTools::whoIsWeb()') >= 2);

    /* 16 Nov 2026 (F1-1): Auth::admin() re-reads the admins row only every
       30 s, so a session stamped just now carries whatever the row said at
       sign-in. whoIs() reads the row on every message; the web door must too,
       or a temporary password / suspension / lock / demotion issued while the
       person is signed in keeps the office tools for up to half a minute. */
    echo "\n-- inside Auth's 30-second window: the row decides NOW --\n";
    $fresh = ['checked_at' => time()];   // Auth::admin() will not re-read this session's row
    $winId = $mk('win', 'superadmin');
    $setWin = static function (array $set) use ($winId): void {
        Database::update('admins', $set + ['is_active' => 1, 'must_change_pw' => 0, 'locked_until' => null, 'role' => 'superadmin'],
            'id = :i', ['i' => $winId]);
    };
    check('control: a fresh session on a clean account is the office', $who($winId, $fresh)['role'] === 'admin');
    $setWin(['must_change_pw' => 1]);
    check('a temporary password issued mid-session is refused at once, not 30 s later',
        $who($winId, $fresh)['role'] === 'customer', 'got ' . $who($winId, $fresh)['role']);
    $setWin(['is_active' => 0]);
    check('a suspension mid-session is refused at once', $who($winId, $fresh)['role'] === 'customer');
    $setWin(['locked_until' => date('Y-m-d H:i:s', time() + 3600)]);
    check('a lock mid-session is refused at once', $who($winId, $fresh)['role'] === 'customer');
    $setWin(['role' => 'accountant']);
    check('a role taken away mid-session reaches nothing (the row\'s role, not the session\'s)',
        $who($winId, $fresh)['role'] === 'customer' && (int) $who($winId, $fresh)['adminId'] === 0);
    $setWin(['role' => 'agent']);
    $demoted = $who($winId, $fresh);
    check('a superadmin demoted to agent mid-session is scoped to their own book',
        $demoted['role'] === 'staff' && $demoted['scopeAdminId'] === $winId, 'got ' . $demoted['role']);
    $setWin([]);
    check('a session that itself carries must_change_pw (signed in on the temporary password) is refused',
        $who($winId, $fresh + ['must_change_pw' => true])['role'] === 'customer');
    check('…and the same session without the flag is the office again (the gate is the flag, nothing else)',
        $who($winId, $fresh)['role'] === 'admin');

    /* The local brain answers BEFORE the AI (api/ai-chat.php) and gives the
       office its day summary off the same ctx: a refused account gets none. */
    echo "\n-- office summaries from the local brain (WaLocal::answerWeb) --\n";
    require_once INCLUDE_PATH . '/walocal.php';
    Settings::set('wa_local_brain_on', '1', 'bool', 'whatsapp', false);
    Settings::set('wa_report_chart_on', '0', 'bool', 'whatsapp', false);
    Settings::flush();
    $q = 'aaja kati ticket bikyo';
    $bossLoc = WaLocal::answerWeb($who($bossId), $q, 'en');
    check('control: the office gets its day summary from the local brain', ($bossLoc['intent'] ?? '') === 'summary',
        'intent ' . ($bossLoc['intent'] ?? 'none'));
    $tmpLoc = WaLocal::answerWeb($who($tmpId), $q, 'en');
    check('a superadmin on a temporary password gets NO office summary', ($tmpLoc['intent'] ?? '') !== 'summary',
        'intent ' . ($tmpLoc['intent'] ?? 'none'));
    $setWin(['must_change_pw' => 1]);
    $winLoc = WaLocal::answerWeb($who($winId, $fresh), $q, 'en');
    check('…nor one whose temporary password was issued mid-session', ($winLoc['intent'] ?? '') !== 'summary',
        'intent ' . ($winLoc['intent'] ?? 'none'));
    $setWin([]);

    /* =================================================================
     *  STAGING — the panel and the phone are two conversations
     * ================================================================= */
    echo "\n-- a quote parked on one channel is not confirmable on the other --\n";

    $stageKey = new ReflectionMethod(AiTools::class, 'stageKey');
    $stageKey->setAccessible(true);

    $panelCtx = $who($bossId);
    $waCtx    = AiTools::whoIs(AIP_PHONE);
    $kPanel   = (string) $stageKey->invoke(null, $panelCtx);
    $kWa      = (string) $stageKey->invoke(null, $waCtx);

    check('the panel key is web-staff-<id>', $kPanel === 'web-staff-' . $bossId, 'got ' . $kPanel);
    check('a WhatsApp ctx still keys on the phone, unchanged',
        $kWa === normalisePhone(AIP_PHONE), 'got ' . $kWa);
    check('the two channels do NOT share a parked quote', $kPanel !== $kWa);
    check('a ctx with neither falls back to an empty key, never a crash',
        (string) $stageKey->invoke(null, []) === '');

    /* =================================================================
     *  SWITCH — off means off
     * ================================================================= */
    echo "\n-- the switch --\n";

    $agentSrc = (string) file_get_contents(INCLUDE_PATH . '/aiagent.php');
    check('the web door is ai_web_agent_on FIRST, before any key is looked at',
        (bool) preg_match("/function webEnabled\(\): bool\s*\{\s*return Settings::getBool\('ai_web_agent_on', true\)\s*&&/", $agentSrc));
    Settings::set('ai_web_agent_on', '0', 'bool', 'ai', false);
    Settings::flush();
    check('ai_web_agent_on = 0 keeps the door shut whatever keys are set',
        AiAgent::webEnabled() === false);
    check('and handleWeb() answers nothing at all',
        AiAgent::handleWeb($who($bossId), 'aaja kasto cha?') === null);

    /* =================================================================
     *  CATALOGUE — the panel reaches the same office tools as a phone
     * ================================================================= */
    echo "\n-- the tools the panel can reach --\n";

    $names = static fn(array $ctx): array => array_map(
        static fn(array $t): string => (string) ($t['name'] ?? ''),
        AiTools::catalogue($ctx)
    );

    $bossTools  = $names($who($bossId));
    $agentTools = $names($who($agentId));

    check('the office reaches office_day from the panel', in_array('office_day', $bossTools, true));
    check('the office reaches office_alerts from the panel', in_array('office_alerts', $bossTools, true));
    check('a counter agent does NOT reach office_day', !in_array('office_day', $agentTools, true));
    check('a refused account reaches no catalogue worth the name',
        !in_array('office_day', $names($who($mk('acct', 'accountant'))), true));

    /* The prompt used to name four marketing tools that do not exist, so
       the assistant argued with the office about buttons it had been told
       it owned. Keep them gone until the tools are real. */
    echo "\n-- the prompt promises nothing the catalogue cannot do --\n";
    $code = (string) file_get_contents(INCLUDE_PATH . '/aitools.php');
    foreach (['marketing_draft', 'marketing_preview', 'marketing_send', 'marketing_status'] as $t) {
        $inPrompt = str_contains($agentSrc, '"' . $t) || str_contains($agentSrc, $t . ':');
        $inTools  = str_contains($code, "'" . $t . "'");
        check("{$t} is either a real tool or unmentioned", !$inPrompt || $inTools);
    }
} catch (Throwable $e) {
    check('suite ran to the end', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $restoreSettings();
    $cleanup();
    if ($ghost > 0) {
        try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $ghost]); } catch (Throwable $e) {}
        try { Database::delete('audit_logs', "action = 'admin.session_revoked' AND entity_id = :a", ['a' => (string) $ghost]); } catch (Throwable $e) {}
    }
}

echo "\n" . ($FAIL === 0
        ? "\033[32mALL {$PASS} CHECKS PASSED\033[0m\n\n"
        : "\033[31m{$FAIL} FAILED\033[0m, {$PASS} passed\n\n");
exit($FAIL === 0 ? 0 : 1);
