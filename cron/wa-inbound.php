<?php
/**
 * cron/wa-inbound.php — answer the WhatsApp messages the webhook took in but
 * never finished (15 Oct 2026, 2F-queue).
 *
 * Recommended: every 2 minutes (crontab minute field: a step of 2).
 *   crontab:   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-inbound.php
 *   or by URL: https://www.shreehariglobal.in/cron/wa-inbound.php?token=CRON_TOKEN
 *
 * whatsapp/webhook.php writes every inbound message into wa_inbound FIRST
 * (wamid UNIQUE - the same row is the dedupe), answers it inline exactly as
 * before, then marks it done. A PHP crash, a timeout or a deploy in between
 * used to lose the customer's message for good: Meta had its 200, the row
 * of "seen" ids said answered, and nobody ever replied. Such a row now stays
 * pending (or failed), and this job answers it.
 *
 * Deliberately conservative:
 *  - only rows older than 2 minutes (the inline answer has had its chance);
 *  - at most 3 attempts in all - the webhook's own inline try is the first -
 *    spaced 2, 4, 8 minutes apart (attempts doubles the wait);
 *  - never a message older than an hour: the conversation has moved on, and
 *    a late answer to "ho" would be read against a summary that is gone;
 *  - each attempt is CLAIMED with one guarded UPDATE (attempts = the value
 *    read), so two overlapping runs can never answer the same message twice;
 *  - the stored copy of a finished message is blanked after 7 days - the
 *    wamid stays (it is the dedupe key), the customer's words do not.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';

/* The webhook's own functions, without its HTTP part (see the guard there). */
define('SHG_WA_INBOUND_REPLAY', true);
require_once ROOT_PATH . '/whatsapp/webhook.php';

const WAI_MAX_ATTEMPTS = 3;
const WAI_BATCH        = 20;
const WAI_MAX_AGE_MIN  = 60;

$hasTable = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_inbound'",
    [], 0
) > 0;
if (!$hasTable) {
    cron_done(['skipped' => 'wa_inbound table not installed (database/upgrade-2026-10-15-wa-booking.sql)']);
    exit;
}

// Too old to answer, or out of attempts: closed, never replayed.
$expired = (int) Database::run(
    "UPDATE wa_inbound
        SET status = 'failed', last_error = COALESCE(last_error, 'not answered in time - left for the office')
      WHERE status = 'pending'
        AND (created_at < NOW() - INTERVAL " . WAI_MAX_AGE_MIN . " MINUTE OR attempts >= " . WAI_MAX_ATTEMPTS . ")
        AND (next_at IS NULL OR next_at <= NOW())"
);

$due = Database::fetchAll(
    "SELECT id, wamid, from_digits, payload, attempts
       FROM wa_inbound
      WHERE status IN ('pending', 'failed')
        AND attempts < " . WAI_MAX_ATTEMPTS . "
        AND created_at < NOW() - INTERVAL 2 MINUTE
        AND created_at >= NOW() - INTERVAL " . WAI_MAX_AGE_MIN . " MINUTE
        AND (next_at IS NULL OR next_at <= NOW())
      ORDER BY id ASC
      LIMIT " . WAI_BATCH
);

$answered = 0;
$failed   = 0;
$skipped  = 0;
foreach ($due as $row) {
    $seen = (int) $row['attempts'];
    // The claim: this run takes attempt N+1, and the next one waits 2^(N+1) minutes.
    $mine = (int) Database::run(
        "UPDATE wa_inbound
            SET attempts = attempts + 1, status = 'pending',
                next_at = NOW() + INTERVAL (2 * POW(2, :n1)) MINUTE
          WHERE id = :id AND attempts = :n2 AND status IN ('pending', 'failed')",
        ['n1' => $seen, 'id' => (int) $row['id'], 'n2' => $seen]
    );
    if ($mine !== 1) {
        $skipped++;                                    // another run took it
        continue;
    }
    $msg = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($msg) || (string) ($msg['id'] ?? '') !== (string) $row['wamid']) {
        wh_queue_mark((string) $row['wamid'], 'failed', 'stored message unreadable');
        $failed++;
        continue;
    }
    try {
        wh_process_inbound($msg);
        wh_queue_mark((string) $row['wamid'], 'done', 'answered by the replay (attempt ' . ($seen + 1) . ')');
        $answered++;
    } catch (Throwable $e) {
        wh_queue_mark((string) $row['wamid'], 'failed', $e->getMessage());
        Logger::warning('WhatsApp inbound replay failed', [
            'id' => (int) $row['id'], 'attempt' => $seen + 1, 'err' => $e->getMessage(),
        ], 'whatsapp');
        $failed++;
    }
}

// The customer's words are not kept longer than the replay can need them.
$blanked = (int) Database::run(
    "UPDATE wa_inbound SET payload = NULL
      WHERE payload IS NOT NULL AND status = 'done' AND created_at < NOW() - INTERVAL 7 DAY"
);

cron_done([
    'due'      => count($due),
    'answered' => $answered,
    'failed'   => $failed,
    'skipped'  => $skipped,
    'expired'  => $expired,
    'blanked'  => $blanked,
]);
