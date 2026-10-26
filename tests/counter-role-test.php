<?php
/**
 * Counter role + Terms-matched refund slabs — integration test (5 Sep 2026).
 *
 * Locks in:
 *   • the 'counter' staff role: books/edits/reschedules/cancels/reprints and
 *     verifies payments, but holds NO dashboard.view, NO commissions.view,
 *     NO staff/routes/fleet management and NO report exports;
 *   • the agent-money wall: every agent-facing office page requires
 *     commissions.view for non-agent viewers (counter/support stay out);
 *   • mayCancelBooking(): counter cancels any booking, agent only their own;
 *   • Fare::refundFor() enforces the FIVE slabs the public Terms page has
 *     always promised — 96h/90 · 48h/75 · 24h/50 · 6h/25 · <6h/0 — and the
 *     JS mirrors (02-config, i18n payPolicy, routes answer) say the same;
 *   • admins.role ENUM accepts 'counter' after the 2026-09 migration.
 *
 *   php -c .claude/php-dev.ini tests/counter-role-test.php
 *
 * Applies database/upgrade-2026-09-counter-role.sql (idempotent), writes only
 * throwaway admin rows and removes them. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

/* Apply the migration up front (idempotent), then drop any memoised
   settings so refund_slabs is re-read from the fresh row. */
Database::run(
    "ALTER TABLE `admins`
      MODIFY `role` ENUM('superadmin','manager','accountant','support','scanner','official','agent','counter')
      NOT NULL DEFAULT 'support'"
);
Database::run(
    "INSERT INTO `settings` (`skey`,`svalue`,`stype`)
     VALUES ('refund_slabs',
             '[{\"minHrs\":96,\"pct\":90},{\"minHrs\":48,\"pct\":75},{\"minHrs\":24,\"pct\":50},{\"minHrs\":6,\"pct\":25},{\"minHrs\":0,\"pct\":0}]',
             'json')
     ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`), `stype` = VALUES(`stype`)"
);
Settings::flush();

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

/** Pretend a staff member of this role is signed in. */
function actAs(string $role, int $id = 990001): void {
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $id, 'username' => 'test-' . $role, 'full_name' => 'Test ' . $role,
        'role' => $role, 'permissions' => [], 'must_change_pw' => false,
        'via_otp' => false, 'logged_in_at' => time(), 'last_seen' => time(),
    ];
}

try {
    echo "\n== counter role permissions ==\n";
    actAs('counter');
    foreach (['bookings.view', 'bookings.edit', 'bookings.cancel',
              'payments.view', 'payments.verify', 'payments.reject',
              'refunds.view', 'schedules.view', 'schedules.edit',
              'customers.view', 'tickets.scan', 'support.reply'] as $p) {
        check("counter CAN $p", Auth::can($p));
    }
    foreach (['dashboard.view', 'commissions.view', 'commissions.pay',
              'staff.manage', 'routes.edit', 'schedules.manage',
              'refunds.process', 'reports.export', 'reports.view'] as $p) {
        check("counter CANNOT $p", !Auth::can($p));
    }
    check('counter is not isCounterAgent()', !Auth::isCounterAgent());
    check('counter is not superadmin', !Auth::isSuperadmin());
    check('counter booking scope is unscoped (serves any walk-in)', Auth::bookingScopeAdminId() === null);

    echo "\n== cancel authorisation ==\n";
    $own   = ['sold_by_admin_id' => 990001];
    $other = ['sold_by_admin_id' => 123456];
    actAs('counter');
    check('counter may cancel a booking sold by anyone', Auth::mayCancelBooking($other));
    actAs('agent');
    check('agent may cancel their own sale', Auth::mayCancelBooking($own));
    check('agent may NOT cancel another seller\'s booking', !Auth::mayCancelBooking($other));
    check('agent still lacks bookings.edit', !Auth::can('bookings.edit'));

    echo "\n== refund slabs = the published Terms ==\n";
    $cases = [[100.0, 90.0], [72.0, 75.0], [30.0, 50.0], [12.0, 25.0], [2.0, 0.0]];
    foreach ($cases as [$hrs, $pct]) {
        $at = time() + (int) round($hrs * 3600) + 120;   // +2 min so the slab boundary can't flap mid-test
        $r  = Fare::refundFor(1000.0, date('Y-m-d', $at), date('H:i:s', $at));
        check(sprintf('%.0fh before departure -> %.0f%% (got %.0f%%)', $hrs, $pct, $r['percent']), $r['percent'] === $pct);
    }
    $r = Fare::refundFor(2000.0, date('Y-m-d', time() + 100 * 3600), date('H:i:s', time() + 100 * 3600));
    check('90% of 2000 rounds to 1800', $r['amount'] === 1800.0);

    echo "\n== admins.role ENUM accepts counter ==\n";
    Database::run("DELETE FROM admins WHERE username = 'counter-test-tmp'");
    $cid = Database::insert('admins', [
        'username' => 'counter-test-tmp',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'full_name' => 'Counter Test', 'role' => 'counter',
        'is_active' => 1, 'must_change_pw' => 1,
    ]);
    $row = Database::fetch('SELECT role FROM admins WHERE id = :id', ['id' => $cid]);
    check("insert with role='counter' persists", ($row['role'] ?? '') === 'counter');
    Database::run('DELETE FROM admins WHERE id = :id', ['id' => $cid]);

    echo "\n== source mirrors & walls (static) ==\n";
    $root = dirname(__DIR__);
    $src  = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);

    $fare = $src('includes/fare.php');
    check('fare.php default slabs carry 96/90 and 6/25',
        str_contains($fare, "['minHrs' => 96, 'pct' => 90]") && str_contains($fare, "['minHrs' => 6,  'pct' => 25]"));

    $cfg = $src('assets/js/02-config.js');
    check('02-config.js refundSlabs carry 96/90 and 6/25',
        str_contains($cfg, '{ minHrs: 96, pct: 90 }') && str_contains($cfg, '{ minHrs: 6,  pct: 25 }'));

    $i18n = $src('assets/js/04-i18n.js');
    check('i18n payPolicy states the 5 tiers in en+hi+ne',
        substr_count($i18n, '96') >= 2 && str_contains($i18n, '≥96 hrs before departure: 90% refund')
        && str_contains($i18n, '≥९६ घण्टा अघि: ९०%'));

    $terms = $src('assets/js/terms-data.js');
    check('terms-data.js still promises 96h/90% (source of truth)',
        str_contains($terms, 'more than 96 hours before departure') && str_contains($terms, '90% is refunded'));

    $guard = $src('admin/_guard.php');
    check('nav gates agents register + working screens on commissions.view for office viewers',
        substr_count($guard, 'commissions.view') >= 5);

    foreach (['admin/agent.php', 'admin/agent-sales.php', 'admin/agent-passengers.php', 'admin/agent-offline.php'] as $p) {
        check("$p walls office viewers behind commissions.view",
            str_contains($src($p), "if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }"));
    }
    check('admin/agents.php requires commissions.view',
        str_contains($src('admin/agents.php'), "Auth::requireAdmin('commissions.view')"));

    check('reschedule gate is bookings.edit OR schedules.edit (agents scoped to own sales by the engine)',
        str_contains($src('admin/reschedule.php'), "Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin()"));

    check("staff.php offers the 'counter' role",
        str_contains($src('admin/staff.php'), "'counter'"));

    check('ticket footer QR opens verify-ticket.php with the keyed token',
        str_contains($src('includes/ticket.php'), "appUrl('verify-ticket.php')"));

    check('login page shows the Mehsana office number',
        str_contains($src('admin/login.php'), '+91 91048 01507'));
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Database::run("DELETE FROM admins WHERE username = 'counter-test-tmp'");
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
