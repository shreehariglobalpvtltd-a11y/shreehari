<?php
/**
 * =====================================================================
 *  delivery-sentinel-test.php — the sentinel names the RIGHT fault.
 *
 *  cron/health-delivery.php exists because on 8 September 2026 nineteen of
 *  the last fifty WhatsApp tickets had failed with 63112 (Meta had disabled
 *  the WhatsApp Business Account) and nothing in the system said so.
 *
 *  A sentinel that raises the WRONG card is worse than none: the owner
 *  learns to ignore it, and the real outage arrives to a screen full of
 *  noise. This suite drives the classifier with rows shaped exactly like
 *  the ones the notifier actually writes, and checks the fault it names.
 *
 *  It also pins the one that was wrong on the first attempt: most rows in
 *  this ledger carry NO provider code at all — they are sentences our own
 *  notifier writes ("no WhatsApp API configured", "sender paused …") — and
 *  a code-only classifier filed every real failure as "unrecognised".
 *
 *    php -c .claude/php-dev.ini tests/delivery-sentinel-test.php
 *
 *  Seeds message_logs rows tagged with its own provider_ref and deletes
 *  every one of them, plus the incidents it caused.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/health.php';

const TAG = 'SENTINELTEST';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/**
 * The sentinel reads the WHOLE ledger, so this suite cannot assert on a
 * clean slate unless it has one. Park every existing whatsapp row outside
 * the 24h window, run, then put them back exactly as they were.
 */
$parked = Database::fetchAll(
    "SELECT id, created_at FROM message_logs WHERE channel = 'whatsapp' AND created_at >= :s",
    ['s' => date('Y-m-d H:i:s', strtotime('-25 hours'))]
);
foreach ($parked as $p) {
    Database::update('message_logs', ['created_at' => '2020-01-01 00:00:00'], 'id = :i', ['i' => (int) $p['id']]);
}

$unpark = static function () use ($parked): void {
    foreach ($parked as $p) {
        Database::update('message_logs', ['created_at' => $p['created_at']], 'id = :i', ['i' => (int) $p['id']]);
    }
};

$cleanup = static function () use ($unpark): void {
    Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . TAG . "%'");
    Database::run("DELETE FROM health_incidents WHERE dedupe_key LIKE 'delivery.%'");
    $unpark();
};

/** Write one fake outcome row. */
function seed(string $error, string $status = 'failed', ?int $bookingId = null): void
{
    Database::insert('message_logs', [
        'booking_id'   => $bookingId,
        'channel'      => 'whatsapp',
        'provider'     => 'twilio',
        'to_number'    => '9779812345678',
        'status'       => $status,
        'provider_ref' => TAG . bin2hex(random_bytes(6)),
        'error'        => $error !== '' ? $error : null,
    ]);
}

/** Run the sentinel as a real subprocess and return its JSON line. */
function runSentinel(): array
{
    $php  = PHP_BINARY;
    $ini  = dirname(__DIR__, 2) . '/.claude/php-dev.ini';
    $job  = dirname(__DIR__) . '/cron/health-delivery.php';
    $cmd  = escapeshellarg($php) . (is_file($ini) ? ' -c ' . escapeshellarg($ini) : '')
          . ' ' . escapeshellarg($job) . ' 2>&1';
    $out  = (string) shell_exec($cmd);
    $brace = strpos($out, '{');
    $json  = $brace === false ? [] : (json_decode(substr($out, $brace), true) ?: []);
    if ($json === []) {
        echo "  (sentinel output was: " . trim($out) . ")\n";
    }
    return $json;
}

/** Is an incident with this dedupe key currently open? */
function isOpen(string $key): bool
{
    return (string) Database::scalar(
        'SELECT status FROM health_incidents WHERE dedupe_key = :d LIMIT 1', ['d' => $key], ''
    ) === 'open';
}

$cleanupRan = false;
register_shutdown_function(static function () use ($cleanup, &$cleanupRan): void {
    if (!$cleanupRan) { $cleanup(); }
});

Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . TAG . "%'");
Database::run("DELETE FROM health_incidents WHERE dedupe_key LIKE 'delivery.%'");

echo "\n=== The delivery sentinel ===\n\n";

/* -----------------------------------------------------------------
 *  A. The fault that started all this: Meta disabled the WABA.
 * --------------------------------------------------------------- */
echo "-- A. 63112 — Meta disabled the account --\n";
for ($i = 0; $i < 5; $i++) {
    seed('WhatsApp failed (code 63112) - Meta disabled the WhatsApp Business Account', 'failed', 900 + $i);
}
$res = runSentinel();
check('the sentinel ran', $res !== []);
check('it counted the five failures', ($res['failed'] ?? 0) === 5, 'failed=' . ($res['failed'] ?? '?'));
check('…and classified them as 63112', ($res['classes']['63112'] ?? 0) === 5, json_encode($res['classes'] ?? []));
check('a CRITICAL incident is open for the WABA', isOpen('delivery.63112'));

$inc = Database::fetch("SELECT * FROM health_incidents WHERE dedupe_key = 'delivery.63112'");
check('…marked critical', (string) $inc['severity'] === 'critical');
check('…and it tells the owner where to go',
      stripos((string) $inc['fix_steps'], 'security centre') !== false, substr((string) $inc['fix_steps'], 0, 60));
check('…in owner language, not a code dump',
      stripos((string) $inc['title'], 'Meta') !== false && stripos((string) $inc['title'], 'DISABLED') !== false,
      (string) $inc['title']);
check('sample booking ids are recorded', (string) $inc['sample_ids'] !== '');
check('…and no phone number came with them',
      strpos((string) $inc['sample_ids'], '9779812345678') === false, (string) $inc['sample_ids']);

/* -----------------------------------------------------------------
 *  B. The regression: failures that carry NO provider code.
 *
 *  These are the majority of real rows, and the first version of the
 *  classifier filed all of them as "unrecognised".
 * --------------------------------------------------------------- */
echo "\n-- B. failures with no provider code --\n";
Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . TAG . "%'");
for ($i = 0; $i < 4; $i++) {
    seed('no WhatsApp API configured - click-to-chat link only');
}
seed('sender paused after an account-level refusal - click-to-chat link only');

$res = runSentinel();
check('"no WhatsApp API configured" is its own fault class',
      ($res['classes']['unconfigured'] ?? 0) === 4, json_encode($res['classes'] ?? []));
check('"sender paused" is its own fault class',
      ($res['classes']['paused'] ?? 0) === 1, json_encode($res['classes'] ?? []));
check('NOTHING was filed as unrecognised',
      !isset($res['classes']['other']), json_encode($res['classes'] ?? []));
check('the unconfigured incident is open and critical', isOpen('delivery.unconfigured'));
check('the paused incident is open', isOpen('delivery.paused'));

/* -----------------------------------------------------------------
 *  C. A fault that stops is resolved by itself.
 * --------------------------------------------------------------- */
echo "\n-- C. incidents heal --\n";
check('the 63112 incident from section A is still on the books',
      Database::scalar("SELECT COUNT(*) FROM health_incidents WHERE dedupe_key = 'delivery.63112'", [], 0) == 1);
check('…but is now RESOLVED, because it stopped happening',
      !isOpen('delivery.63112'),
      (string) Database::scalar("SELECT status FROM health_incidents WHERE dedupe_key = 'delivery.63112'", [], ''));

/* -----------------------------------------------------------------
 *  D. A Twilio 2xx is not a delivery.
 *
 *  'sent' rows are ACCEPTED, not confirmed. The sentinel must not count
 *  them as failures — and must not count them as proof either.
 * --------------------------------------------------------------- */
echo "\n-- D. accepted is not delivered --\n";
Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . TAG . "%'");
Database::run("DELETE FROM health_incidents WHERE dedupe_key LIKE 'delivery.%'");
for ($i = 0; $i < 3; $i++) {
    seed('', 'sent');
}
seed('', 'skipped');
seed('', 'queued');

$res = runSentinel();
check('accepted rows are not failures',   ($res['failed'] ?? -1) === 0, 'failed=' . ($res['failed'] ?? '?'));
check('…and are counted as sent',         ($res['sent'] ?? 0) === 3, 'sent=' . ($res['sent'] ?? '?'));
check('skipped/queued raise nothing',     ($res['incidents'] ?? -1) === 0, json_encode($res['classes'] ?? []));

/* -----------------------------------------------------------------
 *  E. The sentinel only ever observes.
 * --------------------------------------------------------------- */
echo "\n-- E. it observes, it does not act --\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/cron/health-delivery.php');
check('it never sends a message',        !preg_match('/Notify::(whatsapp|sms|email)\s*\(/', $src));
check('it never creates a booking',      !str_contains($src, 'BookingService::create'));
check('it never touches a seat',         !preg_match('/Seats::(assertAvailable|claim|lock|release)/', $src));
check('it writes only incidents',        !preg_match('/Database::(update|insert|delete)\s*\(\s*[\'"]bookings/', $src));

$cleanupRan = true;
$cleanup();

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
