<?php
/**
 * cron/health-delivery.php — the delivery sentinel.
 *
 * Recommended: every 10 minutes (the crontab line is in docs/DEPLOY.md — it
 * cannot be written here, because a cron star-slash sequence closes this
 * comment block).
 *   crontab:   /usr/bin/php /var/www/shreehariglobal.in/public_html/cron/health-delivery.php
 *   or by URL: https://www.shreehariglobal.in/cron/health-delivery.php?token=CRON_TOKEN
 *
 * WHY THIS EXISTS
 * On 8 September 2026 nineteen of the last fifty WhatsApp messages had
 * failed with 63112 — Meta had disabled the WhatsApp Business Account — and
 * nothing in the system said so. The Twilio console still showed the sender
 * "online" (its view is stale; only message OUTCOMES tell the truth), the
 * admin panel showed a green "sent" tick because a Twilio 2xx was being read
 * as delivered, and the only place the failure existed was a log file. Every
 * one of those passengers simply never got their ticket.
 *
 * So this job reads the delivery ledger every ten minutes, sorts failures
 * into FAULT CLASSES, and writes one incident per class in the owner's own
 * language with the exact steps to fix it — including, loudest of all, the
 * Meta business-verification checklist when the WABA is the problem.
 *
 * IT ONLY OBSERVES. It sends nothing, retries nothing, touches no booking
 * and never calls create(). cron/whatsapp-retry.php does the resending; this
 * job's entire job is to make sure a human knows.
 *
 * TRUTH RULE: a Twilio 2xx means ACCEPTED, not delivered. Only rows that
 * api/twilio-status.php has moved to 'failed' (or that failed at the API
 * call itself) count as failures here, and only 'sent' rows that a callback
 * has confirmed count as successes.
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/health.php';

/** How far back a "is it broken right now?" question looks. */
const WINDOW_HOURS = 24;

$since = date('Y-m-d H:i:s', strtotime('-' . WINDOW_HOURS . ' hours'));

$rows = Database::fetchAll(
    "SELECT id, booking_id, channel, status, to_number, error, provider_ref, created_at
       FROM message_logs
      WHERE created_at >= :since AND channel = 'whatsapp'
      ORDER BY id DESC
      LIMIT 2000",
    ['since' => $since]
);

$total   = count($rows);
$failed  = 0;
$sent    = 0;
$classes = [];   // code => ['n' => int, 'ids' => list<int>]

foreach ($rows as $r) {
    $status = (string) $r['status'];
    if ($status === 'sent') {
        $sent++;
        continue;
    }
    if ($status !== 'failed') {
        continue;   // skipped/queued are not faults
    }
    $failed++;

    /* The error column is a SENTENCE, not a code column, and not every
       failure carries a provider code at all: the two most common rows on
       this system are written by our own notifier before Twilio is ever
       called ("no WhatsApp API configured", "sender paused after an
       account-level refusal"). Classifying on the code alone therefore
       filed 1,261 of 1,261 real failures as "unrecognised", which is a
       sentinel that tells the owner nothing. Text first, code second, and
       anything still unmatched lands in 'other' rather than being dropped —
       an unexplained failure is still a failure. */
    $err  = (string) ($r['error'] ?? '');
    $code = 'other';
    if (stripos($err, 'no whatsapp api configured') !== false) {
        $code = 'unconfigured';
    } elseif (stripos($err, 'sender paused') !== false) {
        $code = 'paused';
    } elseif (preg_match('/\b(6\d{4}|2000\d)\b/', $err, $m) === 1) {
        $code = $m[1];
    }

    if (!isset($classes[$code])) {
        $classes[$code] = ['n' => 0, 'ids' => []];
    }
    $classes[$code]['n']++;
    if (count($classes[$code]['ids']) < 20 && !empty($r['booking_id'])) {
        $classes[$code]['ids'][] = (int) $r['booking_id'];
    }
}

/**
 * What each fault class means, and what the owner has to actually do.
 *
 * Written for the person who owns the business, not the person who wrote the
 * code: an incident that says "63112" and stops is the same as no incident.
 *
 * @var array<string, array{severity:string, title:string, detail:string, fix:string}>
 */
const FAULTS = [
    '63112' => [
        'severity' => Health::CRITICAL,
        'title'    => 'Meta has DISABLED the WhatsApp Business Account — no ticket can be delivered',
        'detail'   => 'Every WhatsApp ticket is being rejected by Meta, not by Twilio. Twilio may still show the '
                    . 'sender as "online" — that view is stale, and only the message outcomes tell the truth. '
                    . 'Until this is cleared, passengers get no ticket on WhatsApp at all. The retry job holds the '
                    . 'backlog and will deliver it BY ITSELF the moment the account is restored, so nothing is lost '
                    . '— but nothing arrives either.',
        'fix'      => "This can only be fixed by the business owner, in Meta's own console:\n"
                    . "1. Open business.facebook.com -> Security Centre (Business Settings -> Security Centre).\n"
                    . "2. Read the restriction notice: it names the policy and gives a Request Review button.\n"
                    . "3. Complete business verification if it is not green (needs a registration document\n"
                    . "   and a matching public phone/website).\n"
                    . "4. Submit the review, then wait — Meta takes days, not minutes.\n"
                    . "5. Meanwhile: every ticket desk should send the click-to-chat link by hand, and the\n"
                    . "   ticket PNG can be downloaded from the booking and sent from a personal WhatsApp.",
    ],
    '63016' => [
        'severity' => Health::CRITICAL,
        'title'    => 'No approved WhatsApp template — business-initiated messages are refused',
        'detail'   => 'WhatsApp only allows a business to start a conversation using a template Meta has approved. '
                    . 'The ticket message is being sent without one (or with one still pending), so it is rejected. '
                    . 'A passenger who messages US first can still be replied to for 24 hours.',
        'fix'      => "1. Twilio Console -> Messaging -> Content Template Builder: check the ticket template's status.\n"
                    . "2. 'Pending' for days usually means the WhatsApp Business Account itself is restricted —\n"
                    . "   check the Meta Security Centre first (see the WABA incident if one is open).\n"
                    . "3. Confirm the code sends the template variables: a Resend that passes none fails with this\n"
                    . "   same code even when the template IS approved.",
    ],
    '63015' => [
        'severity' => Health::WARN,
        'title'    => 'WhatsApp sandbox rules are being hit',
        'detail'   => 'The sandbox only messages numbers that have joined it. This is expected on a sandbox sender '
                    . 'and means the production sender is not in use.',
        'fix'      => 'Move to an approved production WhatsApp sender, or have each test number send the join code once.',
    ],
    '20003' => [
        'severity' => Health::CRITICAL,
        'title'    => 'Twilio refused the account — every channel is down',
        'detail'   => 'Twilio is rejecting the credentials or the account itself. This affects WhatsApp AND SMS, so '
                    . 'no passenger is being reached on any channel.',
        'fix'      => "1. Twilio Console: does it say the compliance profile (Trust Hub KYC) is not approved? Finish it.\n"
                    . "2. Otherwise the Account SID or Auth Token is wrong — re-copy both (the SID starts with AC).\n"
                    . "3. Check the account balance has not run out.",
    ],
    /* Not provider codes — states our own notifier records before it ever
       reaches Twilio. They are the most common rows in the ledger and the
       most actionable, so they are first-class fault classes here. */
    'unconfigured' => [
        'severity' => Health::CRITICAL,
        'title'    => 'WhatsApp is not configured — no ticket is being sent at all',
        'detail'   => 'The notifier has no Twilio WhatsApp credentials, so every ticket falls back to a '
                    . 'click-to-chat link that somebody has to press by hand. Passengers are only getting their '
                    . 'ticket when a human remembers to send it.',
        'fix'      => "Admin -> Settings -> Notifications: fill in twilio_account_sid, twilio_auth_token and the\n"
                    . "WhatsApp sender number, then use the Test button. (On a dev machine this incident is\n"
                    . 'expected and can be ignored.)',
    ],
    'paused' => [
        'severity' => Health::CRITICAL,
        'title'    => 'The WhatsApp sender is PAUSED after an account-level refusal',
        'detail'   => 'Twilio refused the account itself, so the sender breaker opened and is holding sends for '
                    . '15 minutes at a time to avoid burning the account further. Every ticket is falling back to '
                    . 'a click-to-chat link in the meantime. The pause is a symptom — the account is the fault.',
        'fix'      => "1. Check whether a Twilio account incident (20003) or a Meta WABA incident (63112) is also\n"
                    . "   open here — that is the real cause.\n"
                    . "2. Fix that, then the breaker clears by itself and the retry job drains the backlog.\n"
                    . '3. Admin -> Settings -> Notifications has a Resume button if you want it back immediately.',
    ],
    '21610' => [
        'severity' => Health::INFO,
        'title'    => 'Some passengers have blocked messages from this number',
        'detail'   => 'These recipients opted out. This is their choice, not a fault — but their tickets need '
                    . 'another route.',
        'fix'      => 'Send those passengers their ticket by click-to-chat or hand them the printed PNG at the desk.',
    ],
];

$opened = 0;
$closed = 0;

foreach (FAULTS as $code => $f) {
    $key = 'delivery.' . $code;
    if (isset($classes[$code])) {
        Health::open(
            'delivery',
            $key,
            $f['severity'],
            $f['title'] . ' (' . $classes[$code]['n'] . ' in the last ' . WINDOW_HOURS . 'h)',
            $f['detail'],
            $f['fix'],
            $classes[$code]['ids'],
        );
        $opened++;
    } else {
        if (Health::resolve($key)) {
            $closed++;
        }
    }
}

/* Failures we could not classify. Worth ONE card so they are not invisible,
   but deliberately quiet: an unclassified failure is usually a transient. */
if (isset($classes['other']) && $classes['other']['n'] >= 3) {
    Health::open(
        'delivery',
        'delivery.other',
        Health::WARN,
        $classes['other']['n'] . ' WhatsApp message(s) failed for an unrecognised reason',
        'These failures do not carry one of the known Twilio/Meta error codes. They may be transient network '
            . 'errors, or a new failure mode worth naming.',
        "Open the booking(s) listed and press Resend to see the live error, or read logs/<today>.log for the\n"
            . '"WhatsApp delivery FAILED" lines.',
        $classes['other']['ids'],
    );
    $opened++;
} else {
    $closed += Health::resolve('delivery.other') ? 1 : 0;
}

/* ---------------------------------------------------------------------
 *  Are the status callbacks arriving at all?
 *
 *  This is the failure that hides every other failure. If Twilio cannot
 *  reach api/twilio-status.php, every row stays 'sent' forever, the admin
 *  panel shows a green tick for messages that were never delivered, and
 *  this very sentinel goes quiet — it would be reading a ledger that can
 *  no longer record a failure.
 * ------------------------------------------------------------------- */
$withRef = (int) Database::scalar(
    "SELECT COUNT(*) FROM message_logs
      WHERE channel = 'whatsapp' AND created_at >= :since AND provider_ref <> ''",
    ['since' => $since],
    0
);
$settled = (int) Database::scalar(
    "SELECT COUNT(*) FROM message_logs
      WHERE channel = 'whatsapp' AND created_at >= :since AND status = 'failed'",
    ['since' => $since],
    0
);

/* Only meaningful once there is real traffic: on a quiet day 5 accepted
   messages and 0 callbacks is a quiet day, not an outage. */
if ($withRef >= 20 && $settled === 0 && $failed === 0) {
    Health::open(
        'delivery',
        'delivery.no_callbacks',
        Health::WARN,
        'No delivery confirmations are coming back from Twilio',
        'Every message in the last ' . WINDOW_HOURS . ' hours was ACCEPTED by Twilio and not one has been '
            . 'confirmed delivered or failed since. A Twilio 2xx means accepted, not delivered — so right now the '
            . 'system genuinely does not know whether any passenger received their ticket, and the green ticks in '
            . 'the admin panel cannot be trusted.',
        "1. Twilio Console -> the WhatsApp sender -> StatusCallback URL must be:\n"
            . "   https://www.shreehariglobal.in/api/twilio-status.php\n"
            . "2. Check that URL is reachable from outside (a firewall rule or a stale nginx vhost will silently\n"
            . "   swallow it).\n"
            . '3. Look for "twilio-status" lines in logs/<today>.log — none at all means the callbacks are not arriving.',
    );
    $opened++;
} else {
    $closed += Health::resolve('delivery.no_callbacks') ? 1 : 0;
}

/* ---------------------------------------------------------------------
 *  Tickets that gave up: a desk task, not a log line.
 * ------------------------------------------------------------------- */
$stranded = Database::fetchAll(
    "SELECT DISTINCT b.id
       FROM bookings b
       JOIN message_logs m ON m.booking_id = b.id AND m.channel = 'whatsapp'
      WHERE b.status = 'confirmed'
        AND m.created_at >= :since
        AND m.status = 'failed'
        AND NOT EXISTS (
              SELECT 1 FROM message_logs ok
               WHERE ok.booking_id = b.id AND ok.channel = 'whatsapp' AND ok.status = 'sent'
                 AND ok.created_at > m.created_at
            )
      LIMIT 50",
    ['since' => $since]
);

if ($stranded !== []) {
    Health::open(
        'delivery',
        'delivery.stranded',
        Health::WARN,
        count($stranded) . ' confirmed passenger(s) have no ticket on their phone',
        'These bookings are paid and confirmed, but every WhatsApp attempt failed and none has since succeeded. '
            . 'They are the people who will ring the office.',
        'Open each booking and use the click-to-chat link, or hand them the ticket PNG. The retry job will also '
            . 'try again by itself every 15 minutes once the sender works.',
        array_map(static fn(array $r): int => (int) $r['id'], $stranded),
    );
    $opened++;
} else {
    $closed += Health::resolve('delivery.stranded') ? 1 : 0;
}

cron_done([
    'window_h'  => WINDOW_HOURS,
    'messages'  => $total,
    'sent'      => $sent,
    'failed'    => $failed,
    'classes'   => array_map(static fn(array $c): int => $c['n'], $classes),
    'incidents' => $opened,
    'resolved'  => $closed,
    'stranded'  => count($stranded),
]);
