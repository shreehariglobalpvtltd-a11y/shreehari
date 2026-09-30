<?php
/**
 * wa-fail-alert-test.php — a failed passenger WhatsApp pages the office once.
 *
 * Runs cron/wa-fail-alert.php as a real CLI job against throwaway rows and
 * checks the promises in its header: the first run pages nobody, a new
 * failure pages the office exactly once with the PNR, the failed mobile and
 * the ticket link, a booking already recovered is skipped, and the office
 * alerts never page about themselves.
 *
 *   php tests/wa-fail-alert-test.php
 *
 * CLI only, test database only. Cleans up after itself.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

const FA_TAG   = 'WAFAILALERT';
const FA_KV    = 'wa_fail_alert.alerted';
const FA_ADMIN = '919100009999';
$pass = 0; $fail = 0;
function fa_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$keep = [];
foreach (['wa_fail_alert_on', 'whatsapp_notify_admin', 'admin_whatsapp', 'whatsapp_driver', 'admin_email', 'company_email'] as $k) {
    $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]);
}
$keepKv = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope='global' AND kkey=:k", ['k' => FA_KV], null);

$cleanup = static function (): void {
    Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . FA_TAG . "%'
                     OR (purpose = 'wa_fail_alert' AND to_number LIKE '%9100009999')");
    Database::run("DELETE FROM bookings WHERE pnr LIKE '" . FA_TAG . "%'");
    Database::run("DELETE FROM kv_store WHERE kscope='global' AND kkey=:k", ['k' => FA_KV]);
};
$cleanup();

$runCron = static function (): string {
    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/wa-fail-alert.php') . ' 2>&1');
};
$booking = static function (string $suffix, string $phone): int {
    return Database::insert('bookings', [
        'pnr' => FA_TAG . $suffix, 'contact_phone' => $phone, 'status' => 'confirmed', 'total_amount' => 1500,
    ]);
};
$log = static function (int $bookingId, string $to, string $status, string $error = '', string $purpose = 'ticket'): int {
    return Database::insert('message_logs', [
        'booking_id' => $bookingId, 'channel' => 'whatsapp', 'purpose' => $purpose, 'provider' => 'cloud_api',
        'to_number' => $to, 'status' => $status, 'provider_ref' => FA_TAG . bin2hex(random_bytes(5)),
        'error' => $error !== '' ? $error : null,
    ]);
};
$alerts = static fn (): array => Database::fetchAll(
    "SELECT * FROM message_logs WHERE purpose = 'wa_fail_alert' AND to_number LIKE '%9100009999' ORDER BY id"
);

try {
    Settings::set('wa_fail_alert_on', true, 'bool', 'notify', false);
    Settings::set('whatsapp_notify_admin', true, 'bool', 'notify', false);
    Settings::set('admin_whatsapp', FA_ADMIN, 'string', 'notify', false);
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);   // no network: every send "fails"
    Settings::set('admin_email', '', 'string', 'notify', false);
    Settings::set('company_email', '', 'string', 'notify', false);

    // 1. First run: remembers the old backlog, pages nobody.
    $old = $booking('OLD', '9800000001');
    $log($old, '9779800000001', 'failed', 'WhatsApp failed (code 131026)');
    $out = $runCron();
    fa_check('first run only remembers the backlog', str_contains($out, '"first_run":true'), trim($out));
    fa_check('first run sends no alert', count($alerts()) === 0);

    // 2. A new failure pages the office once, with PNR, mobile and ticket link.
    $new = $booking('NEW', '9800000002');
    $log($new, '9779800000002', 'failed', 'WhatsApp failed (code 63024) - this number is not on WhatsApp');
    $out = $runCron();
    $a   = $alerts();
    fa_check('a new failure writes one office alert', count($a) === 1, trim($out));
    $body = (string) ($a[0]['body'] ?? '');
    fa_check('alert carries the PNR', str_contains($body, FA_TAG . 'NEW'));
    fa_check('alert carries the failed mobile', str_contains($body, '9779800000002'));
    fa_check('alert carries the ticket link', str_contains($body, 'download-ticket.php?pnr=' . FA_TAG . 'NEW'));
    fa_check('alert gives a plain reason', str_contains($body, 'not on WhatsApp'));
    fa_check('alert has a one-tap send-by-hand link', str_contains($body, 'https://wa.me/9779800000002?text='));
    fa_check('alert is not tied to the booking (wa-pending stays honest)', isset($a[0]) && $a[0]['booking_id'] === null);
    fa_check('old backlog stayed quiet', !str_contains(implode("\n", array_column($a, 'body')), FA_TAG . 'OLD'));

    // 3. Retries failing again the same day do not page again.
    $log($new, '9779800000002', 'failed', 'WhatsApp failed (code 63024)');
    $runCron();
    fa_check('one alert per booking per day', count($alerts()) === 1);

    // 4. The failed office alert never pages about itself.
    $runCron();
    fa_check('alerts never report themselves', count($alerts()) === 1);

    // 5. A booking whose newer WhatsApp went through is skipped.
    $ok = $booking('OK', '9800000003');
    $log($ok, '9779800000003', 'failed', 'provider refused the send - click-to-chat link only');
    $log($ok, '9779800000003', 'sent');
    $runCron();
    fa_check('a recovered booking is not reported', !str_contains(implode("\n", array_column($alerts(), 'body')), FA_TAG . 'OK'));

    // 6. Off switch.
    Settings::set('wa_fail_alert_on', false, 'bool', 'notify', false);
    $b4 = $booking('OFF', '9800000004');
    $log($b4, '9779800000004', 'failed', 'x');
    $out = $runCron();
    fa_check('wa_fail_alert_on=off sends nothing', str_contains($out, 'wa_fail_alert_on is off') && count($alerts()) === 1, trim($out));
} finally {
    $cleanup();
    if (is_string($keepKv)) {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'test')",
            ['k' => FA_KV, 'v' => $keepKv]);
    }
    foreach ($keep as $k => $row) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($row !== null) {
            Database::insert('settings', $row);
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
