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
 *  - only rows older than 10 minutes (the inline answer has had its chance);
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

const WAI_NOREPLY_MIN      = 10;    // 16 Nov 2026: the no-reply alert (wai_noreply_alerts below)
const WAI_NOREPLY_LOOK_MIN = 60;

/* tests/u3b-noreply-alert-test.php loads only this file's functions (PHP
   declares them before the file runs) - not the webhook, not the replay. */
if (defined('SHG_WAI_FUNCS_ONLY')) {
    return;
}

/* The webhook's own functions, without its HTTP part (see the guard there). */
define('SHG_WA_INBOUND_REPLAY', true);
require_once ROOT_PATH . '/whatsapp/webhook.php';

const WAI_MAX_ATTEMPTS = 3;
const WAI_BATCH        = 20;
const WAI_MAX_AGE_MIN  = 60;

/**
 * 16 Nov 2026 (Master v2 P6-10c): nobody answered. A WhatsApp message
 * 10-60 minutes old with nothing sent back to that number since it arrived
 * (the bot paused for a person who never replied, a failed answer, a
 * replay that gave up) tells the OFFICE once per number per hour: one bell
 * alert and one office WhatsApp. Never the customer. Behind
 * wa_noreply_alert_on, default OFF. Only the last hour is looked at, so
 * switching it on never alerts an old backlog.
 *
 * @param null|callable(string $to, string $text): bool $send  the office WhatsApp (tests pass a recorder)
 * @return array<string, int|bool>
 */
function wai_noreply_alerts(?callable $send = null): array
{
    if (!Settings::getBool('wa_noreply_alert_on', false)) {
        return ['off' => true];
    }
    require_once INCLUDE_PATH . '/adminalerts.php';
    require_once INCLUDE_PATH . '/wainbox.php';
    require_once INCLUDE_PATH . '/aihandoff.php';
    require_once INCLUDE_PATH . '/notify.php';

    // The office's own numbers write to the bot too; an alert about them is noise.
    $own = [];
    foreach ([Settings::officeWhatsApp(), Settings::getString('admin_whatsapp', '')] as $n) {
        $d = preg_replace('/\D/', '', (string) $n) ?? '';
        if (strlen($d) >= 10) {
            $own[substr($d, -10)] = true;
        }
    }
    $rows = Database::fetchAll(
        "SELECT from_digits, MAX(id) AS last_id, MAX(created_at) AS last_at
           FROM wa_inbound
          WHERE created_at <  NOW() - INTERVAL " . WAI_NOREPLY_MIN . " MINUTE
            AND created_at >= NOW() - INTERVAL " . WAI_NOREPLY_LOOK_MIN . " MINUTE
            AND msg_type NOT IN ('reaction', 'unsupported', 'system', 'ephemeral', 'request_welcome')
          GROUP BY from_digits
          ORDER BY last_at
          LIMIT 50"
    );
    $out = ['checked' => 0, 'waiting' => 0, 'alerted' => 0, 'office_wa' => 0];
    $office = Settings::officeWhatsApp();
    foreach ($rows as $r) {
        $d = preg_replace('/\D/', '', (string) $r['from_digits']) ?? '';
        if (strlen($d) < 10 || isset($own[substr($d, -10)])) {
            continue;
        }
        $out['checked']++;
        // message_logs keeps the number either as international digits or as the bare 10.
        $answered = Database::exists(
            "SELECT 1 FROM message_logs
              WHERE created_at >= :t AND channel = 'whatsapp' AND status IN ('sent', 'queued')
                AND to_number IN (:a, :b)",
            ['t' => (string) $r['last_at'], 'a' => $d, 'b' => substr($d, -10)]
        );
        if ($answered) {
            continue;
        }
        $out['waiting']++;
        $paused = WaInbox::botPaused($d);
        $msg    = Database::fetch('SELECT msg_type, payload FROM wa_inbound WHERE id = :i', ['i' => (int) $r['last_id']]);
        $said   = $msg !== null ? AiHandoff::redact(WaInbox::textOf((string) $msg['msg_type'], (string) ($msg['payload'] ?? ''))) : '';
        $said   = mb_substr(trim(preg_replace('/\s+/u', ' ', $said) ?? ''), 0, 80);
        $title  = 'No reply for ' . WAI_NOREPLY_MIN . '+ min · +' . $d . ($paused ? ' (bot paused)' : '');
        $id = AdminAlerts::push(
            'wa_noreply', $title,
            ($said !== '' ? '"' . $said . '" — ' : '') . 'nothing has gone back to this number since it wrote. Open the chat and answer.',
            '/admin/wa-inbox.php?tab=inbox&p=' . $d, '⏰',
            'wa_noreply:' . $d . ':' . date('Y-m-d H')            // once per number per hour
        );
        if ($id <= 0) {
            continue;                                           // already told this hour
        }
        $out['alerted']++;
        if ($office !== '') {
            try {
                $text = '⏰ WhatsApp: १० मिनेटदेखि जवाफ गएको छैन · No reply for ' . WAI_NOREPLY_MIN . '+ min' . "\n"
                      . '+' . $d . ($paused ? ' (bot paused / बोट रोकिएको)' : '') . "\n"
                      . ($said !== '' ? '"' . $said . '"' . "\n" : '')
                      . appUrl('/admin/wa-inbox.php?tab=inbox&p=' . $d);
                $sent = $send !== null
                    ? (bool) $send($office, $text)
                    : Notify::whatsapp($office, $text, null, null, [], null, ['purpose' => 'noreply_alert']) === true;
                if ($sent) {
                    $out['office_wa']++;
                }
            } catch (Throwable $e) {
                Logger::warning('No-reply office alert failed', ['error' => $e->getMessage()], 'whatsapp');
            }
        }
    }
    return $out;
}

/**
 * 16 Nov 2026: the Messenger conversation (fb_inbound, admin/messenger-inbox.php)
 * keeps the words 30 days; the row stays (the message id is the dedupe key).
 * Runs on every pass, also with the replay switched off. Never throws.
 */
function wai_fb_blank(): int
{
    try {
        if (Database::fetch("SHOW TABLES LIKE 'fb_inbound'") === null) {
            return 0;
        }
        return (int) Database::run(
            'UPDATE fb_inbound SET body = NULL WHERE body IS NOT NULL AND created_at < NOW() - INTERVAL 30 DAY'
        );
    } catch (Throwable $e) {
        Logger::warning('fb_inbound clean-up skipped', ['error' => $e->getMessage()], 'facebook');
        return 0;
    }
}

/** wai_noreply_alerts() that can never stop the replay below. @return array<string, int|bool|string> */
function wai_noreply_safe(): array
{
    try {
        return wai_noreply_alerts();
    } catch (Throwable $e) {
        Logger::warning('No-reply alert check failed', ['error' => $e->getMessage()], 'whatsapp');
        return ['error' => mb_substr($e->getMessage(), 0, 120)];
    }
}

$hasTable = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_inbound'",
    [], 0
) > 0;
/* 15 Oct 2026: the office can switch the replay off (Settings - WhatsApp:
   wa_inbound_replay_on = 0). Messages are still written down by the webhook;
   nothing is answered late. */
if (!Settings::getBool('wa_inbound_replay_on', true)) {
    // The no-reply alert is its own switch: it still runs with the replay off.
    cron_done(['skipped' => 'replay switched off (wa_inbound_replay_on = 0)', 'fb_blanked' => wai_fb_blank()]
        + ($hasTable ? ['noreply' => wai_noreply_safe()] : []));
    exit;
}
if (!$hasTable) {
    cron_done(['skipped' => 'wa_inbound table not installed (database/upgrade-2026-10-15-wa-booking.sql)', 'fb_blanked' => wai_fb_blank()]);
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
        AND created_at < NOW() - INTERVAL 10 MINUTE   -- review fix: well past any inline answer still running (a slow sale must never be replayed into a second booking)
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
    /* review fix: a newer message from the same number was already answered -
       replaying this older one would overwrite what the customer said since. */
    if (Database::exists("SELECT 1 FROM wa_inbound WHERE from_digits = :f AND id > :i AND status = 'done'", ['f' => (string) $row['from_digits'], 'i' => (int) $row['id']])) {
        wh_queue_mark((string) $row['wamid'], 'done', 'superseded by a newer answered message');
        $skipped++;
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
    'fb_blanked' => wai_fb_blank(),
    'noreply'  => wai_noreply_safe(),
]);
