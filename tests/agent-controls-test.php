<?php
/**
 * Agent selling controls — route permissions and the daily booking cap.
 *
 * Both are opt-in restrictions layered onto an agent who could previously
 * sell anything, any number of times. The two rules that matter most are the
 * DEFAULTS, because getting either backwards silently stops a live counter
 * from selling:
 *
 *   - no route rows  = every route allowed (not "no routes allowed")
 *   - limit 0        = no cap            (not "zero bookings a day")
 *
 * and that neither applies to staff who are not counter agents.
 *
 *   php -c .claude/php-dev.ini tests/agent-controls-test.php
 *
 * Writes only throwaway rows and cleans up after itself. CLI only.
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

/** Did assertMaySell() refuse, and with which message? */
function refusal(int $adminId, int $routeId): string {
    try {
        AgentWallet::assertMaySell($adminId, $routeId);
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

const T_USER = 'testctl-agent';
$agentId = 0;

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    $asAdmin = static function (int $id, string $role, string $user): void {
        $_SESSION[ADMIN_SESSION_KEY] = [
            'id' => $id, 'username' => $user, 'full_name' => $user,
            'role' => $role, 'permissions' => [], 'must_change_pw' => false,
            'logged_in_at' => time(), 'last_seen' => time(),
        ];
    };

    Database::delete('admins', 'username = :u', ['u' => T_USER]);
    $agentId = Database::insert('admins', [
        'username'       => T_USER,
        'password_hash'  => password_hash('ctl-test-pw-1', PASSWORD_DEFAULT),
        'full_name'      => 'Controls Test Agent',
        'role'           => 'agent',
        'is_active'      => 1,
        'must_change_pw' => 0,
    ]);

    $routes = array_map('intval', pluck(
        Database::fetchAll('SELECT id FROM routes ORDER BY id LIMIT 2'), 'id'
    ));
    if (count($routes) < 2) {
        throw new RuntimeException('need at least two routes seeded to test route permissions');
    }
    [$r1, $r2] = $routes;

    $asAdmin($agentId, 'agent', T_USER);

    // ---- Default: unrestricted -------------------------------------------
    check('an agent with no route rows may sell route 1', AgentWallet::maySellRoute($agentId, $r1) === true);
    check('an agent with no route rows may sell route 2', AgentWallet::maySellRoute($agentId, $r2) === true);
    check('routePermissions() is empty, meaning "all"', AgentWallet::routePermissions($agentId) === []);
    check('assertMaySell allows an unrestricted agent', refusal($agentId, $r1) === '');

    // ---- Restricting to one route -----------------------------------------
    AgentWallet::setRoutePermissions($agentId, [$r2], 1);
    check('restriction is stored', AgentWallet::routePermissions($agentId) === [$r2]);
    check('the permitted route is sellable',   AgentWallet::maySellRoute($agentId, $r2) === true);
    check('the other route is NOT sellable',   AgentWallet::maySellRoute($agentId, $r1) === false);
    check('assertMaySell refuses the unassigned route',
        str_contains(refusal($agentId, $r1), 'not assigned to this route'));
    check('assertMaySell still allows the assigned route', refusal($agentId, $r2) === '');

    // ---- Clearing goes back to unrestricted, not to locked-out ------------
    AgentWallet::setRoutePermissions($agentId, [], 1);
    check('clearing restores "every route"', AgentWallet::maySellRoute($agentId, $r1) === true);
    check('assertMaySell allows again after clearing', refusal($agentId, $r1) === '');

    // ---- Daily booking cap -------------------------------------------------
    check('no cap by default', AgentWallet::dailyLimitFor($agentId) === 0);
    check('no bookings sold today yet', AgentWallet::bookingsToday($agentId) === 0);
    check('assertMaySell allows when uncapped', refusal($agentId, $r1) === '');

    // Sell two today, then cap at exactly two.
    foreach (['SHG-CTLTEST-1', 'SHG-CTLTEST-2'] as $pnr) {
        Database::insert('bookings', [
            'pnr' => $pnr, 'status' => 'confirmed', 'sold_by_admin_id' => $agentId,
            'contact_phone' => '9998887770', 'total_amount' => 500, 'currency' => 'INR',
            'source' => 'agent', 'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
    }
    check('today\'s sales are counted', AgentWallet::bookingsToday($agentId) === 2);

    AgentWallet::saveProfile($agentId, ['daily_booking_limit' => 5]);
    check('a cap above today\'s count still allows selling', refusal($agentId, $r1) === '');

    AgentWallet::saveProfile($agentId, ['daily_booking_limit' => 2]);
    check('the cap is stored', AgentWallet::dailyLimitFor($agentId) === 2);
    check('reaching the cap refuses the sale',
        str_contains(refusal($agentId, $r1), 'daily limit of 2'));

    // Zero must mean "no limit", not "never sell" — the whole migration
    // depends on this, since every existing agent defaults to 0.
    AgentWallet::saveProfile($agentId, ['daily_booking_limit' => 0]);
    check('a cap of 0 means NO limit, not zero sales', refusal($agentId, $r1) === '');

    // ---- The controls bind counter agents only ----------------------------
    AgentWallet::setRoutePermissions($agentId, [$r2], 1);
    AgentWallet::saveProfile($agentId, ['daily_booking_limit' => 1]);

    $asAdmin(1, 'superadmin', 'superadmin');
    check('a superadmin is not bound by an agent\'s route list', refusal($agentId, $r1) === '');
    $asAdmin(2, 'manager', 'manager');
    check('a manager is not bound by an agent\'s daily cap', refusal($agentId, $r1) === '');

} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Database::delete('bookings', 'pnr LIKE :p', ['p' => 'SHG-CTLTEST-%']);
    if ($agentId > 0) {
        // agent_route_permissions and admin_profiles both cascade / key off
        // the admin row, but delete explicitly so a failed run leaves nothing.
        Database::delete('agent_route_permissions', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]);
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
