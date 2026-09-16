<?php
/**
 * cron/whatsapp-retry.php — re-send tickets whose WhatsApp never arrived.
 *
 * Recommended: every 15 minutes.
 *   crontab:   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/whatsapp-retry.php
 *   or by URL: https://www.shreehariglobal.in/cron/whatsapp-retry.php?token=CRON_TOKEN
 *
 * Why this exists: a ticket send used to be fire-and-forget. When the sender
 * was refusing — a Twilio trial account, a pending Trust Hub KYC, or Meta
 * disabling the WhatsApp Business Account (63112) — the passenger simply never
 * got their ticket, and nothing anywhere knew. The only recovery was a human
 * opening each booking and pressing Resend, with nothing to tell them which.
 *
 * Now Notify::whatsapp() writes a message_logs row for every outcome and
 * api/twilio-status.php flips it to 'failed' when WhatsApp rejects it
 * asynchronously. This job walks those rows and tries again, so the backlog
 * drains BY ITSELF the moment the sender starts working. That is the whole
 * point: the owner fixes the account, and yesterday's passengers get their
 * tickets without anyone touching the admin panel.
 *
 * Deliberately conservative:
 *  - only CONFIRMED bookings whose journey has not already left,
 *  - only when the newest WhatsApp attempt for that booking FAILED,
 *  - at most MAX_TRIES attempts per booking, ever,
 *  - nothing at all while the sender breaker is paused (a paused sender means
 *    the account is refusing everything, so retrying just burns the cap),
 *  - a small batch per run, so one bad day cannot stall the cron.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';

const MAX_TRIES  = 6;    // total WhatsApp attempts per booking before giving up
const BATCH_SIZE = 25;   // bookings per run

/**
 * Report and STOP. cron_done() only prints — it returns, so using it alone as
 * an early exit lets the job run on regardless. That is not theoretical: the
 * first version of this file printed "skipped: sender-level failure" and then
 * cheerfully retried anyway, spending the try budget it had just decided to
 * protect.
 */
function retry_stop(array $result): never
{
    cron_done($result);
    exit;
}

// A paused sender means Twilio is refusing the whole ACCOUNT. Retrying now
// would spend the per-booking cap on a sender we already know is down.
$pause = Settings::getString('whatsapp_driver', 'click_to_chat') === 'twilio'
    ? Notify::twilioPause()
    : null;
if ($pause !== null) {
    retry_stop([
        'skipped' => 'sender paused',
        'until'   => date('H:i', (int) $pause['until']),
        'code'    => $pause['code'] ?? '',
    ]);
}

if (!Settings::getBool('whatsapp_notify_customer', true)) {
    retry_stop(['skipped' => 'whatsapp_notify_customer is off']);
}

/* The Twilio Sandbox for WhatsApp only delivers to phones that first sent the
   account's "join <phrase>" message from their own WhatsApp — every other
   recipient answers 63015 (channel not found). A production ticket retry
   against a sandbox sender is therefore futile for every real customer, and
   would silently burn the per-booking MAX_TRIES cap on the very passengers
   this job exists to rescue. Skip until the owner switches back to a real
   WhatsApp business sender; the moment they do, the backlog drains as usual. */
$fromNow = trim(Settings::getString('twilio_whatsapp_from', ''));
if (str_contains($fromNow, '14155238886')) {
    retry_stop([
        'skipped' => 'sender is the Twilio WhatsApp Sandbox — every non-joined recipient fails 63015',
        'from'    => $fromNow,
    ]);
}

/* Do not spend the per-booking try budget while the SENDER is the thing that
   is broken. 63112 (Meta disabled the WhatsApp Business Account) and 63016 (no
   approved template for a business-initiated message) fail identically for
   every passenger, and — unlike a 20003 — they arrive asynchronously, so the
   account breaker never trips and nothing else stops this loop. Without this
   guard a disabled WABA would burn all MAX_TRIES on every upcoming booking
   within a couple of hours and permanently give up on exactly the passengers
   this job exists to rescue, right while the owner is still fixing Meta. */
$lastFail = Database::fetch(
    "SELECT error FROM message_logs
      WHERE channel = 'whatsapp' AND status = 'failed' AND error IS NOT NULL
      ORDER BY id DESC LIMIT 1"
);
if ($lastFail !== null) {
    foreach (['63112', '63016', '63007', '20003'] as $senderCode) {
        if (str_contains((string) $lastFail['error'], $senderCode)) {
            retry_stop([
                'skipped' => 'sender-level failure, not the passengers',
                'code'    => $senderCode,
                'detail'  => mb_substr((string) $lastFail['error'], 0, 120),
            ]);
        }
    }
}

/* Bookings whose NEWEST whatsapp row failed. Joining message_logs on its own
   newest id per booking is what makes this idempotent: as soon as a retry
   succeeds it writes a 'sent' row, which becomes the newest, and the booking
   drops out of this query on the next run. */
$due = Database::fetchAll(
    "SELECT b.id, b.pnr, b.contact_phone,
            (SELECT COUNT(*) FROM message_logs t
              WHERE t.booking_id = b.id AND t.channel = 'whatsapp') AS tries
       FROM bookings b
       JOIN message_logs m
         ON m.id = (SELECT m2.id FROM message_logs m2
                     WHERE m2.booking_id = b.id AND m2.channel = 'whatsapp'
                     ORDER BY m2.id DESC LIMIT 1)
      WHERE b.status = 'confirmed'
        AND m.status = 'failed'
        AND EXISTS (SELECT 1 FROM booking_legs bl
                     WHERE bl.booking_id = b.id AND bl.travel_date >= CURDATE())
      HAVING tries < :max
      ORDER BY b.id DESC
      LIMIT " . BATCH_SIZE,
    ['max' => MAX_TRIES]
);

$sent = 0;
$stillFailing = 0;

foreach ($due as $row) {
    $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => (int) $row['id']]);
    if ($booking === null) {
        continue;
    }

    try {
        // resendTicketWhatsApp() carries the same {{1}}..{{7}} template
        // variables as the original send, so it works on a template-only
        // sender instead of falling to the free-form branch.
        $res = Notify::resendTicketWhatsApp($booking);
        if (!empty($res['ok'])) {
            $sent++;
        } else {
            $stillFailing++;
        }
    } catch (Throwable $e) {
        // One bad booking must never stop the batch.
        $stillFailing++;
        Logger::error('WhatsApp retry threw: ' . $e->getMessage(), [
            'booking' => $row['id'], 'pnr' => $row['pnr'],
        ], 'whatsapp');
    }
}

// Passengers this job has given up on — surfaced so they can be called.
$exhausted = (int) Database::scalar(
    "SELECT COUNT(*)
       FROM bookings b
       JOIN message_logs m
         ON m.id = (SELECT m2.id FROM message_logs m2
                     WHERE m2.booking_id = b.id AND m2.channel = 'whatsapp'
                     ORDER BY m2.id DESC LIMIT 1)
      WHERE b.status = 'confirmed'
        AND m.status = 'failed'
        AND EXISTS (SELECT 1 FROM booking_legs bl
                     WHERE bl.booking_id = b.id AND bl.travel_date >= CURDATE())
        AND (SELECT COUNT(*) FROM message_logs t
              WHERE t.booking_id = b.id AND t.channel = 'whatsapp') >= :max",
    ['max' => MAX_TRIES],
    0
);

if ($exhausted > 0) {
    Logger::warning('WhatsApp retry gave up on ' . $exhausted . ' upcoming booking(s)', [
        'max_tries' => MAX_TRIES,
    ], 'whatsapp');
}

cron_done([
    'due'        => count($due),
    'sent'       => $sent,
    'failed'     => $stillFailing,
    'exhausted'  => $exhausted,
]);
