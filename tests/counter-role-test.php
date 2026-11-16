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
 *   • Fare::refundFor() follows the ONE refund policy (D8, 15 Oct 2026:
 *     ≥48h 75% · 24–48h 50% · under 24h nothing) and the editable
 *     `refund_slabs` row, not a copy; the 96h/90 and 6h/25 tiers of the
 *     Sep 2026 Terms are gone everywhere (fare.php default, 02-config.js
 *     offline fallback, i18n {policy}, terms-data.js, legal.php);
 *   • admins.role ENUM accepts 'counter' (and every role Auth knows).
 *
 *   php -c .claude/php-dev.ini tests/counter-role-test.php
 *
 * 16 Nov 2026 (T3): the suite no longer ALTERs admins.role — the shared
 * ENUM has grown ('operations', 'viewer'), and shrinking it back to the
 * Sep 2026 list failed with "Data truncated for column 'role'" (and would
 * have broken those accounts). It only READS the ENUM now. It also no longer
 * writes the 5-tier refund_slabs row into the shared settings table: the
 * policy is set in this process only (Settings' in-memory cache), so the DB
 * row is never touched. Writes only a throwaway admin row and removes it.
 * CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/**
 * Put a refund_slabs value into THIS process's settings cache only (null =
 * as if the row were missing). Other suites share the database, so the
 * stored row is never written; Settings::flush() drops the override.
 *
 * @param mixed $value list of slabs, any other JSON value, or null
 */
function slabsInProcess(mixed $value): void {
    Settings::get('refund_slabs');                         // load the cache once
    $cache   = new ReflectionProperty(Settings::class, 'cache');
    $decoded = new ReflectionProperty(Settings::class, 'decoded');
    $c = (array) $cache->getValue();
    if ($value === null) {
        unset($c['refund_slabs']);
    } else {
        $c['refund_slabs'] = ['value' => (string) json_encode($value), 'type' => 'json', 'public' => true];
    }
    $cache->setValue(null, $c);
    $d = (array) $decoded->getValue();
    unset($d['refund_slabs']);
    $decoded->setValue(null, $d);
}

/** The refund percent Fare::refundFor() gives $hrs hours before departure. */
function pctAt(float $hrs): float {
    $at = time() + (int) round($hrs * 3600) + 120;   // +2 min so the slab boundary can't flap mid-test
    return (float) Fare::refundFor(1000.0, date('Y-m-d', $at), date('H:i:s', $at))['percent'];
}

/* The D8 policy (owner's SmartBook v5, 15 Oct 2026). */
const D8_SLABS = [['minHrs' => 48, 'pct' => 75], ['minHrs' => 24, 'pct' => 50], ['minHrs' => 0, 'pct' => 0]];

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

    echo "\n== refund slabs = the one D8 policy ==\n";
    slabsInProcess(D8_SLABS);
    /* 100h and 72h both sit in the top slab now: the old 96h/90% tier is gone,
       so a 100h cancel must NOT get 90%. 12h is under 24h: nothing (the old
       6h/25% tier is gone too). */
    $cases = [[100.0, 75.0], [72.0, 75.0], [30.0, 50.0], [12.0, 0.0], [2.0, 0.0]];
    foreach ($cases as [$hrs, $pct]) {
        $got = pctAt($hrs);
        check(sprintf('%.0fh before departure -> %.0f%% (got %.0f%%)', $hrs, $pct, $got), $got === $pct);
    }
    $r = Fare::refundFor(2000.0, date('Y-m-d', time() + 100 * 3600), date('H:i:s', time() + 100 * 3600));
    check('75% of 2000 rounds to 1500', $r['amount'] === 1500.0);
    check('the policy line says the same', str_contains(Fare::refundPolicyLine('en'), '≥48 hrs before departure: 75% refund')
        && str_contains(Fare::refundPolicyLine('en'), 'under 24 hrs: no refund'));

    /* The row is the editable source, not a copy in the code. */
    slabsInProcess([['minHrs' => 72, 'pct' => 90], ['minHrs' => 0, 'pct' => 10]]);
    check('an edited refund_slabs row drives refundFor() (100h -> 90%)', pctAt(100.0) === 90.0);
    check('  …and its bottom slab (30h -> 10%)', pctAt(30.0) === 10.0);
    slabsInProcess('not a list');
    check('an unreadable row falls back to the D8 default (72h -> 75%)', pctAt(72.0) === 75.0 && pctAt(30.0) === 50.0);
    slabsInProcess(null);
    check('a missing row falls back to the D8 default (12h -> 0%)', pctAt(12.0) === 0.0 && pctAt(72.0) === 75.0);
    Settings::flush();                                   // back to the stored row (never written)

    echo "\n== admins.role ENUM accepts counter ==\n";
    $col = Database::fetch("SHOW COLUMNS FROM admins LIKE 'role'");
    preg_match_all("/'([^']*)'/", (string) ($col['Type'] ?? ''), $em);
    $enum = $em[1] ?? [];
    check("admins.role ENUM lists 'counter'", in_array('counter', $enum, true), implode(',', $enum));
    $missing = array_values(array_diff(array_merge(['superadmin'], Auth::MATRIX_ROLES), $enum));
    check('  …and every role Auth hands out (superadmin + the matrix roles)', $missing === [], 'missing: ' . implode(',', $missing));
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

    /* D8 (15 Oct 2026): one policy, read from refund_slabs everywhere. The
       copies that used to state 96h/90 · 6h/25 are either the same D8
       fallback or a {policy} placeholder filled from the live row. */
    $norm = static fn(array $s): array => array_map(static fn(array $x): array => [(int) $x['minHrs'], (int) $x['pct']], $s);
    check('fare.php REFUND_SLABS_DEFAULT is the D8 policy (48/75 · 24/50 · 0/0)',
        $norm(Fare::REFUND_SLABS_DEFAULT) === $norm(D8_SLABS), (string) json_encode(Fare::REFUND_SLABS_DEFAULT));
    $fare = $src('includes/fare.php');
    check('  …and fare.php carries no 96h/90 or 6h/25 tier',
        !str_contains($fare, "['minHrs' => 96") && !str_contains($fare, "['minHrs' => 6,"));

    $cfg = $src('assets/js/02-config.js');
    check('02-config.js offline refundSlabs fallback is the D8 policy',
        str_contains($cfg, '{ minHrs: 48, pct: 75 }') && str_contains($cfg, '{ minHrs: 24, pct: 50 }')
        && str_contains($cfg, '{ minHrs: 0,  pct: 0 }') && !str_contains($cfg, 'minHrs: 96') && !str_contains($cfg, 'minHrs: 6,'));

    $i18n = $src('assets/js/04-i18n.js');
    check('i18n payPolicy is the {policy} placeholder in en+hi+ne (filled from the live slabs)',
        substr_count($i18n, "payPolicy: '{policy}'") >= 3);
    check('  …with no hard-coded 96h tier left in any language',
        !str_contains($i18n, '≥96 hrs before departure') && !str_contains($i18n, '≥९६ घण्टा अघि'));

    $terms = $src('assets/js/terms-data.js');
    /* (96 hours still appears there, correctly, in the RESCHEDULING clause:
       a free reschedule more than 96 h out. Only the refund scale is checked.) */
    check('terms-data.js states the D8 refund scale (48h+ 75% · 24–48h 50% · under 24h none)',
        str_contains($terms, '48 hours or more before departure — 25% of the base fare is retained and 75% is refunded')
        && str_contains($terms, 'between 24 and 48 hours before departure — 50% is retained and 50% is refunded')
        && str_contains($terms, 'less than 24 hours before departure, or after departure — no refund is payable'));
    check('  …and no longer promises the gone 90% tier', !str_contains($terms, '90% is refunded'));
    check('the legal Terms page builds its refund scale from Fare::refundSlabs() (never a copy)',
        str_contains($src('includes/legal.php'), 'Fare::refundSlabs()'));

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

    /* The number is no longer hard-coded in login.php: the page prints
       Settings::officePhone() (the company_phone row, else the Mehsana
       office constant) as a tel: link, so an office move is one setting. */
    $login = $src('admin/login.php');
    check('login page shows the office number from Settings::officePhone() as a tel: link',
        str_contains($login, 'Settings::officePhone()') && preg_match('/href="tel:\+<\?= [^>]*helpTel/', $login) === 1);
    check('  …and the fallback is the Mehsana office number',
        OFFICE_PHONE_DISPLAY === '+91 91048 01507', OFFICE_PHONE_DISPLAY);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    Database::run("DELETE FROM admins WHERE username = 'counter-test-tmp'");
    Settings::flush();
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
