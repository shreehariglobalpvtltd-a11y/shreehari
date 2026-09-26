<?php
/**
 * =====================================================================
 *  notifier-test.php — office alerts and customer messages (26 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/notifier-test.php
 *
 *  Guards includes/notifier.php:
 *    - the office hears about one event on one booking once per window
 *      (a retried webhook must not alert five times); another event or
 *      another booking still goes through
 *    - a customer is never written to unless they wrote to us in the last
 *      24 h (S Hari never opens a conversation); a number saved without a
 *      country code still finds the window
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
require_once INCLUDE_PATH . '/notifier.php';
require_once INCLUDE_PATH . '/watemplates.php';

$pass = 0; $fail = 0;
function nt_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$intl  = '9779811000077';
$local = '9811000078';
$cleanup = static function () use ($intl, $local): void {
    Database::run("DELETE FROM bookings WHERE pnr LIKE 'SHG-NTTEST-%'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'notify_%'");
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey IN (:a, :b, :c)",
        ['a' => $intl, 'b' => '91' . $local, 'c' => '977' . $local]);
};
$mkBooking = static fn (string $suffix, string $phone): int => Database::insert('bookings', [
    'pnr' => 'SHG-NTTEST-' . $suffix, 'contact_phone' => $phone, 'status' => 'pending', 'total_amount' => 300,
]);

$cleanup();
try {
    $b1 = $mkBooking('01', $intl);
    $b2 = $mkBooking('02', $local);

    // --- office alerts are rate-limited per event per booking --------------
    $first  = Notifier::notifyAdmin('possible_match_detected', $b1, 'test alert', ['PNR' => 'SHG-NTTEST-01']);
    $second = Notifier::notifyAdmin('possible_match_detected', $b1, 'test alert', ['PNR' => 'SHG-NTTEST-01']);
    nt_check('the first alert goes out', $first === true);
    nt_check('the same alert on the same booking inside the window is suppressed', $second === false);
    nt_check('another event on the same booking still goes out',
        Notifier::notifyAdmin('cancel_requested', $b1, 'test alert') === true);
    nt_check('the same event on another booking still goes out',
        Notifier::notifyAdmin('possible_match_detected', $b2, 'test alert') === true);

    nt_check('two different unmatched payments both alert (keyed by event, not "no booking")',
        Notifier::notifyAdmin('payment_unmatched', null, 'test', [], 'nttest:ev-1') === true
        && Notifier::notifyAdmin('payment_unmatched', null, 'test', [], 'nttest:ev-2') === true
        && Notifier::notifyAdmin('payment_unmatched', null, 'test', [], 'nttest:ev-1') === false);

    // --- customers only inside their 24 h window ---------------------------
    nt_check('no inbound message: the customer is not written to',
        Notifier::notifyCustomer($b1, 'test') === 'suppressed_window');
    nt_check('an unknown booking is not written to', Notifier::notifyCustomer(0, 'test') === 'suppressed_window');

    WaTemplates::noteInbound($intl);
    $r = Notifier::notifyCustomer($b1, 'test');
    nt_check('after the customer wrote in, the message is attempted', $r !== 'suppressed_window', $r);

    Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'wa_inbound' AND kkey = :k",
        ['v' => (string) (time() - 25 * 3600), 'k' => $intl]);
    nt_check('25 h later the window is closed again', Notifier::notifyCustomer($b1, 'test') === 'suppressed_window');

    nt_check('a number saved without a country code: closed', !Notifier::windowOpen($local));
    WaTemplates::noteInbound('91' . $local);
    nt_check('...opens when the customer wrote from +91', Notifier::windowOpen($local));
} catch (Throwable $e) {
    nt_check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

echo "\n  notifier: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
