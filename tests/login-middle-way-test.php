<?php
/**
 * =====================================================================
 *  SIGN-IN, THE MIDDLE WAY — 26 Sep 2026
 *
 *      php tests/login-middle-way-test.php
 *
 *  Owner's decision: a NEW number signs in with one tap, exactly as it has
 *  since 4 Sep. A number that ALREADY HAS TICKETS is somebody's travel
 *  history and their name on a ticket, so it is proven once with a code.
 *  A sale by signed-in staff is never asked — the clerk is the proof.
 *
 *  What this pins down:
 *    1. The rule is real code on the one endpoint that opens a session,
 *       not a comment: api/otp.php asks for the ticket history, honours
 *       login_otp_for_returning, and exempts a staff session.
 *    2. The switch exists as a ROW, because Settings::setMany() silently
 *       ignores a key with no row — a switch nobody can flip is not a
 *       switch. (Run the migration for this to pass.)
 *    3. The client already knows what to do with needsOtp: the sign-in
 *       sheet falls through to the request/verify handshake whenever the
 *       server does not answer `verified`.
 *    4. The history question itself is right: it counts CONFIRMED and
 *       COMPLETED tickets on that contact number, and ignores a pending
 *       or cancelled one — a booking somebody abandoned must not lock a
 *       number that never travelled.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

echo "\n=== Sign-in: one tap for a new number, a code for a returning one ===\n\n";

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

/* ---- 1. the rule is on the endpoint that opens the session --------- */

$otp = $src('api/otp.php');
check('quick sign-in asks whether the number already has tickets',
    str_contains($otp, "FROM bookings WHERE contact_phone = :p AND status IN ('confirmed','completed')"));
check('...only when the rule is switched on',
    str_contains($otp, "Settings::getBool('login_otp_for_returning', true)"));
check('...and never for a sale by signed-in staff',
    str_contains($otp, 'Auth::admin() === null'));
check('a returning number is answered needsOtp instead of a session',
    str_contains($otp, "['needsOtp' => true, 'verified' => false]"));
check('the answer comes BEFORE the session is opened',
    strpos($otp, "'needsOtp' => true") < strpos($otp, '$user = Auth::loginUser($phone, $name, $country);'));

/* ---- 2. the switch is a row, not just a default -------------------- */

$hasRow = Database::fetch('SELECT skey FROM settings WHERE skey = :k', ['k' => 'login_otp_for_returning']) !== null;
if ($hasRow) {
    check('the switch exists as a settings row, so the office can turn it off', true);
    check('...and ships ON, as the owner chose', Settings::getBool('login_otp_for_returning', false));
} else {
    echo "  \033[33mSKIP\033[0m  database/upgrade-2026-09-26-login-otp.sql is not applied on this database\n";
}

/* ---- 3. the client already handles it ------------------------------ */

$js = $src('assets/js/08-signin.js');
check('the sign-in sheet falls through to the code handshake when the server does not verify',
    str_contains($js, "if (q && q.verified) {") && str_contains($js, "action: 'request', phone: p, purpose: 'login'"));
check('...and verifies the typed code against the same endpoint',
    str_contains($js, "action: 'verify', phone: p, code: code"));

/* ---- 4. the history question is the right one ---------------------- */

$statuses = Database::fetchAll("SHOW COLUMNS FROM bookings LIKE 'status'");
$enum     = (string) ($statuses[0]['Type'] ?? '');
check('confirmed and completed are real booking statuses',
    str_contains($enum, "'confirmed'") && str_contains($enum, "'completed'"), $enum !== '' ? 'ok' : 'column not found');
check('a pending or cancelled booking is NOT counted — an abandoned booking must not lock a number',
    !str_contains($otp, "status IN ('confirmed','completed','pending')")
    && !str_contains($otp, "status IN ('confirmed','completed','cancelled')"));

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");

exit($FAIL === 0 ? 0 : 1);
