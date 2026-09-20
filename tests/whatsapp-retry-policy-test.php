<?php
/**
 * whatsapp-retry-policy-test.php — a recovered sender releases the backlog.
 *
 * The retry job must stop while Meta is refusing every ticket, but a later
 * successful ticket has to release that brake. Administrative "skipped" rows
 * are ledger notes, not provider attempts, and must not consume MAX_TRIES.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

const WR_TAG = 'WARETRYPOLICY';
$pass = 0; $fail = 0;
function wr_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static fn () => Database::run("DELETE FROM message_logs WHERE provider_ref LIKE '" . WR_TAG . "%'");
$cleanup();

try {
    $source = (string) file_get_contents(dirname(__DIR__) . '/cron/whatsapp-retry.php');
    wr_check('sender probe keeps the failed row id', str_contains($source, 'SELECT id, error FROM message_logs'));
    wr_check('only a newer success can release the brake', str_contains($source, 'id > :failed_id'));
    wr_check('success gets an async-callback grace period', str_contains($source, "created_at <= NOW() - INTERVAL 2 MINUTE"));
    wr_check('skipped ledger notes are excluded from attempts', substr_count($source, "status IN ('sent','failed')") >= 2);

    $insert = static function (string $status, string $created): int {
        return Database::insert('message_logs', [
            'channel'      => 'whatsapp',
            'purpose'      => 'ticket',
            'provider'     => 'cloud_api',
            'to_number'    => '919999000111',
            'status'       => $status,
            'provider_ref' => WR_TAG . bin2hex(random_bytes(5)),
            'error'        => $status === 'failed' ? 'WhatsApp failed (code 131042) - payment method problem' : null,
            'created_at'   => $created,
        ]);
    };

    $failedId = $insert('failed', date('Y-m-d H:i:s', strtotime('-10 minutes')));
    $recovered = static fn () => Database::exists(
        "SELECT 1 FROM message_logs
          WHERE channel='whatsapp' AND purpose='ticket' AND status='sent'
            AND id > :failed_id AND created_at <= NOW() - INTERVAL 2 MINUTE
          LIMIT 1",
        ['failed_id' => $failedId]
    );
    wr_check('a failure alone keeps the sender blocked', !$recovered());

    $insert('skipped', date('Y-m-d H:i:s', strtotime('-5 minutes')));
    wr_check('a skipped/reset row does not prove recovery', !$recovered());

    $sentId = $insert('sent', date('Y-m-d H:i:s'));
    wr_check('a just-accepted send waits for its failure callback', !$recovered());
    Database::update('message_logs', ['created_at' => date('Y-m-d H:i:s', strtotime('-3 minutes'))], 'id=:id', ['id' => $sentId]);
    wr_check('a later stable success releases the sender brake', $recovered());

    $attempts = (int) Database::scalar(
        "SELECT COUNT(*) FROM message_logs
          WHERE provider_ref LIKE :tag AND status IN ('sent','failed')",
        ['tag' => WR_TAG . '%'], 0
    );
    wr_check('only real provider attempts consume the cap', $attempts === 2, (string) $attempts);
} finally {
    $cleanup();
}

echo "\nwhatsapp-retry-policy: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
