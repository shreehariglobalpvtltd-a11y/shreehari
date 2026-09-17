<?php
/**
 * cron/wa-reminders.php — WhatsApp payment reminders to agents whose cash
 * has sat past the settlement window (17 Sep 2026).
 *
 * Recommended: once a day at 10:00, from the command line only.
 *   crontab:  0 10 * * * /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-reminders.php >> /var/log/shg-wa-reminders.log 2>&1
 *
 * What it does: for every ACTIVE agent whose cash is overdue by at least
 * Settings agent_settle_reminder_days (AgentWallet::settlementOverdueDays —
 * which itself only counts once agent_settlement_due_days > 0), it composes
 * the very same "Payment reminder" the office sends by hand from Agent 360
 * (WaTemplates 'agent_payment_reminder') and sends it through
 * Notify::whatsapp(), logged to message_logs with purpose / agent_admin_id
 * exactly like a staff send from admin/api/wa-send.php.
 *
 * Deliberately conservative — it mirrors cron/whatsapp-retry.php:
 *  - OFF until Settings agent_settle_reminder_days > 0 (default 0 = off),
 *  - one reminder per agent per 24 h; message_logs is the memory, so a
 *    re-run, a second server or a staff member pressing the same button
 *    earlier in the day never doubles up,
 *  - respects the office switch wa_admin_tools_enabled,
 *  - nothing at all while the Twilio sender breaker is paused, and nothing
 *    without a WhatsApp API — a cron has no staff phone to hand a wa.me
 *    link to,
 *  - WhatsApp delivers a business-initiated free text ONLY inside the
 *    24-hour window after the agent last wrote to us; outside it an approved
 *    Content template is required (Settings twilio_content_sid_payment_reminder,
 *    which counts as configured once the template carries variables — the
 *    same rule as admin/api/wa-send.php). An agent reachable neither way is
 *    reported as HELD and tried again tomorrow instead of burning a send
 *    that fails 63016 later,
 *  - one bad agent never stops the loop (try/catch per agent).
 *
 * Command line only: a reminder blast is not something a URL should be able
 * to trigger, so the ?token= door _cron.php opens for other jobs is closed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cron/wa-reminders.php runs from the command line only.\n");
}

require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/watemplates.php';

const WA_REM_PURPOSE = 'agent_payment_reminder';

/**
 * Report and STOP. cron_done() only prints — it returns — so on its own it
 * would let the job carry on (see the note in cron/whatsapp-retry.php).
 */
function wa_rem_stop(array $result, bool $ok = true): never
{
    cron_done($result, $ok);
    exit;
}

$minDays = Settings::getInt('agent_settle_reminder_days', 0);
if ($minDays <= 0) {
    wa_rem_stop(['skipped' => 'agent_settle_reminder_days is 0 — automatic reminders are off']);
}
if (Settings::getInt('agent_settlement_due_days', 0) <= 0) {
    wa_rem_stop(['skipped' => 'agent_settlement_due_days is 0 — no agent can be overdue']);
}
if (!Settings::getBool('wa_admin_tools_enabled', true)) {
    wa_rem_stop(['skipped' => 'wa_admin_tools_enabled is off']);
}

$driver = Settings::getString('whatsapp_driver', 'click_to_chat');
$apiOn  = ($driver === 'twilio' && Settings::getString('twilio_account_sid', '') !== '' && Settings::getString('twilio_whatsapp_from', '') !== '')
       || ($driver === 'cloud_api' && Settings::getString('whatsapp_api_token', '') !== '');
if (!$apiOn) {
    wa_rem_stop(['skipped' => 'no WhatsApp API configured (whatsapp_driver = ' . $driver . ') — a cron has no staff phone to hand the message to']);
}
$pause = $driver === 'twilio' ? Notify::twilioPause() : null;
if ($pause !== null) {
    wa_rem_stop([
        'skipped' => 'sender paused',
        'until'   => date('H:i', (int) $pause['until']),
        'code'    => $pause['code'] ?? '',
    ]);
}

/* message_logs.purpose / agent_admin_id arrive with
   database/upgrade-2026-09-wa-templates.sql. Without them this job cannot
   remember whom it reminded yesterday, so it must not send at all. */
$hasCols = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs'
        AND COLUMN_NAME IN ('purpose', 'agent_admin_id')",
    [],
    0
);
if ($hasCols < 2) {
    wa_rem_stop(['skipped' => 'message_logs has no purpose / agent_admin_id columns — run database/upgrade-2026-09-wa-templates.sql'], false);
}

$agents = Database::fetchAll(
    "SELECT id, full_name, username FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id"
);

// The composer signs the statement PDF with the actor id; a reminder has no
// PDF, and 0 / 'cron' is what message_logs and the audit trail should show.
$actor = ['id' => 0, 'name' => 'cron'];

$due = 0; $sent = 0; $held = 0; $recent = 0; $failed = 0;

foreach ($agents as $a) {
    $id  = (int) $a['id'];
    $who = (string) ($a['full_name'] ?: $a['username']);
    try {
        $overdue = AgentWallet::settlementOverdueDays($id);
        if ($overdue < $minDays) {
            continue;
        }
        $due++;

        // Reminded (by this job or by a staff button) in the last 24 h? Any
        // outcome counts: a refused send is not retried until tomorrow either.
        $already = (int) Database::scalar(
            "SELECT COUNT(*) FROM message_logs
              WHERE channel = 'whatsapp' AND purpose = :p AND agent_admin_id = :a
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ['p' => WA_REM_PURPOSE, 'a' => $id],
            0
        );
        if ($already > 0) {
            $recent++;
            echo 'agent #' . $id . ' ' . $who . ': overdue ' . $overdue . ' d, already reminded in the last 24 h — skipped' . "\n";
            continue;
        }

        $msg = WaTemplates::compose(WA_REM_PURPOSE, ['agent_id' => $id], $actor);

        // Same "can this go out?" rule as admin/api/wa-send.php.
        $sidKey             = (string) $msg['contentSidKey'];
        $contentSid         = $sidKey !== '' ? Settings::getString($sidKey, '') : '';
        $templateConfigured = $contentSid !== '' && $msg['templateVars'] !== [];
        $sessionOpen        = WaTemplates::inSessionWindow($msg['intl']);
        if (!($templateConfigured || $sessionOpen || $driver === 'cloud_api')) {
            $held++;
            echo 'agent #' . $id . ' ' . $who . ': overdue ' . $overdue . ' d, HELD — no approved template (' . $sidKey . ') and no open 24 h chat with +' . $msg['intl'] . "\n";
            continue;
        }

        $meta = [
            'purpose'        => WA_REM_PURPOSE,
            'admin_id'       => 0,
            'agent_admin_id' => $id,
            'media_url'      => $msg['mediaUrl'],
            'media_type'     => $msg['mediaType'],
        ];
        if ($templateConfigured) {
            $meta['content_sid'] = $contentSid;
        }
        $res = Notify::whatsapp(
            $msg['to'],
            $msg['text'],
            $msg['mediaUrl'],
            $msg['hint'],
            $templateConfigured ? $msg['templateVars'] : [],
            null,
            $meta
        );

        if ($res === true) {
            $sent++;
            echo 'agent #' . $id . ' ' . $who . ': overdue ' . $overdue . ' d, SENT to +' . $msg['intl'] . "\n";
            Logger::audit('wa.send', 'message', WA_REM_PURPOSE . ':agent:' . $id, null,
                ['to' => $msg['intl'], 'purpose' => WA_REM_PURPOSE, 'result' => 'sent', 'overdue_days' => $overdue],
                'sent by cron/wa-reminders.php');
        } else {
            // Notify::whatsapp() already logged the refusal (message_logs
            // 'failed' + logs/whatsapp); the row also stops a retry before tomorrow.
            $failed++;
            echo 'agent #' . $id . ' ' . $who . ': overdue ' . $overdue . ' d, FAILED — provider refused the send (see logs/, channel whatsapp)' . "\n";
        }
    } catch (Throwable $e) {
        // One bad agent (no number on file, a composer refusal, a DB hiccup)
        // must never stop the batch.
        $failed++;
        echo 'agent #' . $id . ' ' . $who . ': ERROR ' . $e->getMessage() . "\n";
        Logger::error('wa-reminders threw: ' . $e->getMessage(), ['agent' => $id], 'whatsapp');
    }
}

cron_done([
    'agents'   => count($agents),
    'due'      => $due,
    'sent'     => $sent,
    'held'     => $held,
    'recent'   => $recent,
    'failed'   => $failed,
    'min_days' => $minDays,
]);
