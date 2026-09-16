<?php
/**
 * cron/alerts.php — operational nudges to the admin (recommended: every 30 min).
 *
 *   · Low-seat alert: an upcoming departure has ≤ N seats left
 *     (one WhatsApp per schedule, ever — claimed in automation_log).
 *   · Pending-approval alert: payment proof uploaded but not reviewed for
 *     more than M minutes (one WhatsApp per booking, ever).
 *
 * Toggles live in Admin → Settings → Automation and are checked BEFORE
 * each claim (a claim taken while the toggle is off would permanently
 * eat that alert — the tripEventEnabled contract).
 *
 *   crontab (every 30 minutes):
 *     curl -s "https://shreehariglobal.in/cron/alerts.php?token=CRON_TOKEN"
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/notify.php';

// Claims live in automation_log; without the migration this cron would
// re-send every alert every 30 minutes, so it declines to run instead.
try {
    Database::query('SELECT 1 FROM automation_log LIMIT 1');
} catch (Throwable $e) {
    cron_done([
        'skipped' => 'automation_log missing',
        'hint'    => 'Run database/upgrade-2026-08-automation.sql, then this cron works.',
    ]);
    exit(0);
}

Settings::flush();

$out = [];

/* Both alert kinds go to admin WhatsApp only. If that channel is off or has
   no number, there is nowhere to deliver — bail BEFORE claiming anything, or
   every one-per-schedule/booking claim would be burned while the channel is
   down and the alert could never fire once it came back (the claim-before-gate
   trap). This mirrors the send-time gate inside Notify::lowSeatAlert. */
if (!Notify::adminWhatsappReady()) {
    cron_done(['skipped' => 'admin WhatsApp channel off or unconfigured']);
    exit(0);
}

/* =====================================================================
 *  A. Low remaining seats on upcoming departures (today .. +2 days)
 * ===================================================================== */
if (!Settings::getBool('low_seat_alert_enabled', true)) {
    $out['lowSeat'] = 'off';
} else {
    $threshold = max(1, Settings::getInt('low_seat_threshold', 10));
    $sent      = 0;

    try {
        $rows = Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, r.from_city, r.to_city
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.status = 'scheduled'
                AND s.travel_date >= CURDATE()
                AND s.travel_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY)"
        );

        foreach ($rows as $s) {
            // Sellable remaining, not total − booked: staff berths (L5/L6, 1A)
            // and admin seat_blocks are unsellable and never appear in
            // booking_seats, so a raw subtraction overstates availability and
            // the alert fires late (or never on a partly-blocked coach).
            // Seats::availability() does the set arithmetic customers see.
            $remaining = (int) Seats::availability(
                (int) $s['route_id'], (string) $s['travel_date'], 'sharing'
            )['availableCount'];
            if ($remaining > $threshold || $remaining < 0) {
                continue;
            }
            // One alert per schedule, ever — the claim IS the dedupe.
            if (!EventBus::claim('low-seat:' . (int) $s['id'], 'seats.low')) {
                continue;
            }
            EventBus::emit('seats.low', [
                'schedule_id' => (int) $s['id'],
                'route'       => $s['from_city'] . ' → ' . $s['to_city'],
                'date'        => (string) $s['travel_date'],
                'remaining'   => $remaining,
                'threshold'   => $threshold,
            ]);
            $sent++;
        }
    } catch (Throwable $e) {
        Logger::error('Low-seat sweep failed', ['e' => $e->getMessage()], 'automation');
    }

    $out['lowSeat'] = $sent;
}

/* =====================================================================
 *  B. Payment proofs waiting too long for review
 *     (booking pending + expiry clock stopped = proof exists, we owe
 *      the customer a decision — see holdForAdminReview)
 * ===================================================================== */
if (!Settings::getBool('pending_approval_alert_enabled', true)) {
    $out['pendingReview'] = 'off';
} else {
    $minutes = max(5, Settings::getInt('approval_reminder_minutes', 30));
    $items   = [];

    try {
        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.total_amount,
                    TIMESTAMPDIFF(MINUTE, p.updated_at, NOW()) AS waited
               FROM bookings b
               JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
              WHERE b.status = 'pending'
                AND b.expires_at IS NULL
                AND p.status = 'pending'
                AND p.updated_at < DATE_SUB(NOW(), INTERVAL " . $minutes . " MINUTE)
              ORDER BY p.updated_at ASC
              LIMIT 50"
        );

        foreach ($rows as $r) {
            // One reminder per booking, ever — approve/reject ends the story.
            if (!EventBus::claim('pending-alert:' . (int) $r['id'], 'booking.pending_review')) {
                continue;
            }
            $items[] = [
                'pnr'     => (string) $r['pnr'],
                'amount'  => (float) $r['total_amount'],
                'minutes' => (int) $r['waited'],
            ];
        }

        if ($items !== []) {
            Notify::pendingApprovalAlert($items);
        }
    } catch (Throwable $e) {
        Logger::error('Pending-review sweep failed', ['e' => $e->getMessage()], 'automation');
    }

    $out['pendingReview'] = count($items);
}

cron_done($out);
