<?php
/**
 * cron/wa-fail-alert.php — tell the office, one WhatsApp per failure, when a
 * passenger's WhatsApp did not go through (30 Sep 2026, owner's request).
 *
 * Recommended: every 5 minutes.
 *   crontab:   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/wa-fail-alert.php
 *   or by URL: https://www.shreehariglobal.in/cron/wa-fail-alert.php?token=CRON_TOKEN
 *
 * Why a cron and not a line inside Notify::whatsapp(): a send fails in two
 * ways. Some fail on the spot (Notify::whatsapp() writes a 'failed' row),
 * but most are refused LATER, when api/twilio-status.php, whatsapp/webhook.php
 * or whatsapp/gupshup-webhook.php flip an accepted row to 'failed'. Reading
 * message_logs catches both in one place, and keeps the webhooks fast.
 *
 * For every booking whose WhatsApp failed, the office number (admin_whatsapp,
 * the Mehsana desk 9104801507 by default) receives:
 *   - the PNR and the mobile number that failed,
 *   - a short reason ("number not on WhatsApp", ...),
 *   - the ticket link, and a one-tap wa.me link that opens the passenger's
 *     chat with the ticket already typed, so the desk can send it by hand.
 *
 * Deliberately quiet:
 *  - at most ONE alert per booking per ALERT_GAP_H hours, however many times
 *    cron/whatsapp-retry.php tries and fails again in between,
 *  - a booking whose newer WhatsApp already went through is skipped,
 *  - the alerts themselves (purpose=wa_fail_alert) and other office notes are
 *    never reported, so a broken sender cannot make this job page itself,
 *  - the very first run only remembers today's backlog and sends nothing, so
 *    turning the job on does not flood the office phone with old failures,
 *  - off switch: Settings wa_fail_alert_on (default on) and the usual
 *    whatsapp_notify_admin.
 *
 * If the office alert itself cannot go out on WhatsApp (the sender is the
 * thing that is broken), the same lines are emailed to admin_email instead,
 * once per run. Admin → Tickets to hand over (admin/wa-pending.php) still
 * lists every waiting passenger either way.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';

const ALERT_GAP_H = 24;                        // one alert per booking per this many hours
const ALERT_BATCH = 20;                        // alerts per run, so a bad day cannot flood the phone
const ALERT_KV    = 'wa_fail_alert.alerted';   // kv_store: {booking_id: [alerted_at, message_log_id]}
const ALERT_PURPOSE = 'wa_fail_alert';

if (!Settings::getBool('wa_fail_alert_on', true)) {
    cron_done(['skipped' => 'wa_fail_alert_on is off']);
    exit;
}
if (!Settings::getBool('whatsapp_notify_admin', true)) {
    cron_done(['skipped' => 'whatsapp_notify_admin is off']);
    exit;
}
$adminPhone = trim(Settings::getString('admin_whatsapp', Settings::officePhone()));
if ($adminPhone === '') {
    cron_done(['skipped' => 'no admin_whatsapp number']);
    exit;
}
$adminDigits = preg_replace('/\D/', '', $adminPhone) ?? '';

/* The `purpose` column arrives with upgrade-2026-09-wa-templates.sql. Before
   it exists we cannot tell an office note from a ticket, so rows sent to the
   office number itself are excluded by number below as well. */
$hasPurpose = (int) Database::scalar(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'purpose'",
    [], 0
) > 0;
$purposeSql = $hasPurpose
    ? " AND (m.purpose IS NULL OR m.purpose NOT IN ('" . ALERT_PURPOSE . "', 'admin_note'))"
    : '';

/* The newest failed WhatsApp row per confirmed booking, from the last
   ALERT_GAP_H hours. Only bookings: a ticket link needs a PNR. */
$failed = Database::fetchAll(
    "SELECT m.booking_id, MAX(m.id) AS log_id
       FROM message_logs m
       JOIN bookings b ON b.id = m.booking_id
      WHERE m.channel = 'whatsapp'
        AND m.status = 'failed'
        AND m.booking_id IS NOT NULL
        AND m.created_at >= NOW() - INTERVAL " . ALERT_GAP_H . " HOUR
        AND b.status = 'confirmed'" . $purposeSql . "
      GROUP BY m.booking_id
      ORDER BY log_id ASC"
);

/* ---- remembered alerts ------------------------------------------------ */
$raw = Database::scalar(
    "SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1",
    ['k' => ALERT_KV], null
);
$firstRun = !is_string($raw) || $raw === '';
$alerted  = $firstRun ? [] : (json_decode($raw, true) ?: []);

$saveAlerted = static function (array $map): void {
    // Forget anything older than the gap — the map stays small forever.
    $cut = time() - ALERT_GAP_H * 3600;
    $map = array_filter($map, static fn ($v): bool => is_array($v) && (int) ($v[0] ?? 0) >= $cut);
    $json = json_encode($map, JSON_UNESCAPED_SLASHES);
    Database::run(
        "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'wa-fail-alert')
         ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'wa-fail-alert'",
        ['k' => ALERT_KV, 'v' => $json, 'v2' => $json]
    );
};

if ($firstRun) {
    // Remember today's backlog without paging anyone about it.
    $now = time();
    foreach ($failed as $f) {
        $alerted[(string) $f['booking_id']] = [$now, (int) $f['log_id']];
    }
    $saveAlerted($alerted);
    cron_done(['first_run' => true, 'remembered' => count($failed)]);
    exit;
}

/** A short, plain reason the desk can act on. */
function wa_fail_reason(string $error): string
{
    foreach (['63024', '63003', '131026'] as $c) {
        if (str_contains($error, $c)) {
            return 'number is not on WhatsApp - please call';
        }
    }
    foreach (['21211', '21614'] as $c) {
        if (str_contains($error, $c)) {
            return 'not a valid mobile number';
        }
    }
    if (str_contains($error, '131042')) {
        return 'WhatsApp billing problem (Meta)';
    }
    if (str_contains($error, '63112')) {
        return 'Meta disabled the WhatsApp Business Account';
    }
    if (str_contains($error, 'click-to-chat')) {
        return 'WhatsApp sender not working right now';
    }
    $error = trim($error);
    return $error === '' ? 'unknown' : mb_substr($error, 0, 90);
}

$sent     = 0;
$emailed  = [];
$skipped  = 0;
$senderDown = false;

foreach ($failed as $f) {
    if ($sent + count($emailed) >= ALERT_BATCH) {
        break;
    }
    $bid   = (int) $f['booking_id'];
    $logId = (int) $f['log_id'];
    $prev  = $alerted[(string) $bid] ?? null;

    // Already told the office about this booking inside the gap.
    if (is_array($prev) && ((int) ($prev[1] ?? 0) >= $logId || (int) ($prev[0] ?? 0) > time() - ALERT_GAP_H * 3600)) {
        $skipped++;
        continue;
    }

    $row = Database::fetch(
        'SELECT m.to_number, m.error, b.id, b.pnr, b.contact_phone
           FROM message_logs m JOIN bookings b ON b.id = m.booking_id
          WHERE m.id = :id',
        ['id' => $logId]
    );
    if ($row === null) {
        continue;
    }
    $failedNumber = (string) ($row['to_number'] ?: $row['contact_phone']);
    if ($adminDigits !== '' && str_ends_with(preg_replace('/\D/', '', $failedNumber) ?? '', substr($adminDigits, -10))) {
        continue;   // a message to the office itself — never page the office about the office
    }

    // A newer WhatsApp to this booking already went through: nothing to do.
    if (Database::exists(
        "SELECT 1 FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' AND status = 'sent' AND id > :id LIMIT 1",
        ['b' => $bid, 'id' => $logId]
    )) {
        $alerted[(string) $bid] = [time(), $logId];
        $skipped++;
        continue;
    }

    $pnr       = (string) $row['pnr'];
    $ticketUrl = Ticket::imageUrl($pnr);
    $handText  = 'तपाईंको टिकट (बुकिङ नं. ' . $pnr . '): ' . $ticketUrl;
    $lines = [
        '⚠️ WhatsApp पठाउन सकिएन',
        'PNR: ' . $pnr,
        'Mobile: ' . $failedNumber,
        'Reason: ' . wa_fail_reason((string) ($row['error'] ?? '')),
        'Ticket: ' . $ticketUrl,
        'Send by hand: ' . whatsappLink($failedNumber, $handText),
    ];
    $text = implode("\n", $lines);

    $ok = false;
    if (!$senderDown) {
        try {
            /* No booking id on purpose: admin/wa-pending.php and the retry
               cron read "the newest WhatsApp row for this booking", and a
               'sent' office alert must never look like the passenger's
               ticket went through. */
            $ok = Notify::whatsapp($adminPhone, $text, null, null, [], null, ['purpose' => ALERT_PURPOSE]) === true;
        } catch (Throwable $e) {
            Logger::warning('wa-fail-alert: office alert threw: ' . $e->getMessage(), ['pnr' => $pnr], 'whatsapp');
        }
        // The office alert failed too — the sender is down. Stop hammering it;
        // the rest of this run goes by email.
        $senderDown = !$ok;
    }

    if ($ok) {
        $sent++;
    } else {
        $emailed[] = $text;
    }
    $alerted[(string) $bid] = [time(), $logId];
}

$saveAlerted($alerted);

if ($emailed !== []) {
    $adminEmail = Settings::getString('admin_email', Settings::getString('company_email', ''));
    if ($adminEmail !== '') {
        $subject = 'WhatsApp failed for ' . count($emailed) . ' passenger(s)';
        $html = Notify::wrapEmail($subject,
            '<p>These WhatsApp messages did not reach the passenger, and the office WhatsApp alert could not be sent either.</p>'
            . '<pre style="font-family:monospace;font-size:13px;white-space:pre-wrap">' . e(implode("\n\n", $emailed)) . '</pre>'
            . '<p><a href="' . e(appUrl('admin/wa-pending.php')) . '">Open Tickets to hand over</a></p>');
        Notify::email($adminEmail, $subject, $html, implode("\n\n", $emailed));
    } else {
        Logger::warning('wa-fail-alert: office WhatsApp down and no admin_email set — ' . count($emailed) . ' alert(s) not delivered', [], 'whatsapp');
    }
}

cron_done([
    'failed_bookings' => count($failed),
    'alerted'         => $sent,
    'emailed'         => count($emailed),
    'skipped'         => $skipped,
]);
