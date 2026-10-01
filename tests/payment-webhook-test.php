<?php
/**
 * =====================================================================
 *  payment-webhook-test.php — signed payment webhooks (26 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/payment-webhook-test.php
 *
 *  Guards includes/paywebhook.php and api/payment-webhook.php:
 *    - a valid HMAC verifies, a wrong / missing one does not, and an empty
 *      secret verifies nothing (fail closed)
 *    - the same event_id twice is processed once (no double credit)
 *    - a failed / pending payment changes nothing
 *    - with auto-confirm OFF (the default) matching money only sets the
 *      possible_match hint and the booking stays pending
 *    - with auto-confirm ON, the full amount confirms; a short amount does not
 *    - money that matched nothing is kept (as a hash) and hinted the moment
 *      the customer types the same UTR
 *    - the endpoint is switched off by default and never stores the body
 *
 *  Creates its own tagged bookings and removes them.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/paywebhook.php';

$pass = 0; $fail = 0;
function pw_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const PW_TAG = 'SHG-PWTEST-';
$keys  = ['payment_webhook_on', 'payment_webhook_autoconfirm', 'payment_webhook_admin_id', 'payment_webhook_secret'];
$saved = [];
foreach ($keys as $k) { $saved[$k] = Settings::getString($k, ''); }

$mkBooking = static function (string $suffix, float $total, string $utr): int {
    $id = Database::insert('bookings', [
        'pnr' => PW_TAG . $suffix, 'contact_phone' => '9779800000' . substr('00' . $suffix, -3),
        'status' => 'pending', 'total_amount' => $total,
    ]);
    Database::insert('payments', [
        'booking_id' => $id, 'payment_ref' => 'PAY-PWTEST-' . $suffix, 'amount' => $total,
        'utr_number' => $utr !== '' ? $utr : null, 'status' => 'pending',
    ]);
    return $id;
};
$cleanup = static function (): void {
    Database::run("DELETE FROM webhooks_received WHERE provider = 'pwtest'");
    Database::run("DELETE FROM bookings WHERE pnr LIKE 'SHG-PWTEST-%'");
    Database::run("DELETE FROM admins WHERE username = 'pwtest_admin'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'notify_%'");
};
$hint = static fn (int $bid): ?string => Database::scalar(
    'SELECT match_hint FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid], null);
$status = static fn (int $bid): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bid], '');

$cleanup();
try {
    // --- signatures -----------------------------------------------------
    $body = '{"event_id":"e1","utr":"123456789012","amount":500,"status":"success"}';
    $sig  = hash_hmac('sha256', $body, 'topsecret');
    pw_check('a correct HMAC verifies', PayWebhook::verifySignature($body, $sig, 'topsecret'));
    pw_check('..."sha256=" prefix accepted', PayWebhook::verifySignature($body, 'sha256=' . $sig, 'topsecret'));
    pw_check('a wrong HMAC is refused', !PayWebhook::verifySignature($body, hash_hmac('sha256', $body, 'other'), 'topsecret'));
    pw_check('a tampered body is refused', !PayWebhook::verifySignature($body . ' ', $sig, 'topsecret'));
    pw_check('no signature is refused', !PayWebhook::verifySignature($body, '', 'topsecret'));
    pw_check('no secret configured verifies nothing (fail closed)', !PayWebhook::verifySignature($body, hash_hmac('sha256', $body, ''), ''));

    // --- switched off by default ------------------------------------------
    $src = (string) file_get_contents(dirname(__DIR__) . '/api/payment-webhook.php');
    pw_check('endpoint answers 404 while switched off', str_contains($src, "if (!PayWebhook::enabled())") && str_contains($src, '$answer(404'));
    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-09-26-payment-engine.sql');
    pw_check('migration ships webhook and auto-confirm OFF',
        (bool) preg_match("/'payment_webhook_on',\s*'0'/", $sql) && (bool) preg_match("/'payment_webhook_autoconfirm',\s*'0'/", $sql));

    Settings::set('payment_webhook_on', '1', 'bool', 'payment');
    Settings::set('payment_webhook_autoconfirm', '0', 'bool', 'payment');

    // --- idempotency --------------------------------------------------------
    $b1 = $mkBooking('001', 500, 'UTR111111111');
    $ev = ['event_id' => 'pw-1', 'utr' => 'UTR111111111', 'amount' => 500, 'status' => 'success'];
    $r1 = PayWebhook::process('pwtest', $ev, json_encode($ev));
    $r2 = PayWebhook::process('pwtest', $ev, json_encode($ev));
    pw_check('first delivery is processed', $r1['outcome'] === 'possible_match', $r1['outcome']);
    pw_check('the same event_id again is a duplicate', $r2['outcome'] === 'duplicate', $r2['outcome']);
    pw_check('...and recorded once', (int) Database::scalar("SELECT COUNT(*) FROM webhooks_received WHERE provider='pwtest' AND event_id='pw-1'") === 1);

    // --- auto-confirm OFF: hint only ------------------------------------------
    pw_check('auto-confirm off: the booking stays pending', $status($b1) === 'pending');
    pw_check('...and the payment carries the possible_match hint', $hint($b1) === 'possible_match');
    $row = Database::fetch("SELECT * FROM webhooks_received WHERE provider='pwtest' AND event_id='pw-1'") ?? [];
    pw_check('the raw body and UTR are stored only as hashes',
        !in_array('UTR111111111', array_map('strval', $row), true) && strlen((string) $row['payload_hash']) === 64
        && $row['utr_hash'] === PayWebhook::utrHash('UTR111111111'));

    // --- non-success ------------------------------------------------------------
    $b2 = $mkBooking('002', 700, 'UTR222222222');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-2', 'utr' => 'UTR222222222', 'amount' => 700, 'status' => 'failed'], '{}');
    pw_check('a failed payment is ignored', $r['outcome'] === 'ignored' && $hint($b2) === null && $status($b2) === 'pending');

    // --- auto-confirm ON ------------------------------------------------------------
    $aid = Database::insert('admins', [
        'username' => 'pwtest_admin', 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
        'full_name' => 'PW Test', 'role' => 'manager', 'is_active' => 1,
    ]);
    Settings::set('payment_webhook_autoconfirm', '1', 'bool', 'payment');
    Settings::set('payment_webhook_admin_id', '', 'string', 'payment');
    $b3 = $mkBooking('003', 800, 'UTR333333333');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-3', 'utr' => 'UTR333333333', 'amount' => 800, 'status' => 'success'], '{}');
    pw_check('auto-confirm without a named admin account does not confirm', $r['outcome'] === 'possible_match' && $status($b3) === 'pending');

    Settings::set('payment_webhook_admin_id', (string) $aid, 'string', 'payment');
    $b4 = $mkBooking('004', 900, 'UTR444444444');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-4', 'utr' => 'UTR444444444', 'amount' => 600, 'status' => 'success'], '{}');
    pw_check('a short amount never confirms', $r['outcome'] === 'possible_match' && $status($b4) === 'pending');

    $b5 = $mkBooking('005', 900, 'UTR555555555');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-5', 'reference' => PW_TAG . '005', 'amount' => 900, 'status' => 'SUCCESS'], '{}');
    pw_check('the full amount with auto-confirm on confirms (matched by PNR reference)', $r['outcome'] === 'confirmed' && $status($b5) === 'confirmed', $r['outcome']);
    pw_check('...recorded against the named admin',
        (int) Database::scalar('SELECT verified_by FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $b5]) === $aid);
    $r = PayWebhook::process('pwtest', ['event_id' => 'pw-5b', 'reference' => PW_TAG . '005', 'amount' => 900, 'status' => 'success'], '{}');
    pw_check('a second payment for a confirmed booking changes nothing', $r['outcome'] === 'ignored');

    // --- two bookings with one UTR are ambiguous ---------------------------------
    $b6 = $mkBooking('006', 100, 'UTRDUP000001');
    $b7 = $mkBooking('007', 100, 'UTRDUP000001');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-6', 'utr' => 'UTRDUP000001', 'amount' => 100, 'status' => 'success'], '{}');
    pw_check('a UTR on two bookings matches neither', $r['outcome'] === 'unmatched' && $hint($b6) === null && $hint($b7) === null);

    // --- unmatched now, hinted when the customer types the UTR ---------------------
    Settings::set('payment_webhook_autoconfirm', '0', 'bool', 'payment');
    $r  = PayWebhook::process('pwtest', ['event_id' => 'pw-8', 'utr' => 'UTR888888888', 'amount' => 450, 'status' => 'success'], '{}');
    pw_check('money for no known booking is kept as unmatched', $r['outcome'] === 'unmatched');
    $b8 = $mkBooking('008', 450, '');
    pw_check('the customer typing that UTR sets the hint', PayWebhook::onProof($b8, 'utr888888888') && $hint($b8) === 'possible_match');
    pw_check('...never confirms', $status($b8) === 'pending');
    pw_check('...and claims the event', (int) Database::scalar("SELECT booking_id FROM webhooks_received WHERE provider='pwtest' AND event_id='pw-8'") === $b8);
} catch (Throwable $e) {
    pw_check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($saved as $k => $v) { Settings::set($k, $v, in_array($k, ['payment_webhook_on', 'payment_webhook_autoconfirm'], true) ? 'bool' : 'string', 'payment'); }
    $cleanup();
}

echo "\n  payment-webhook: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
