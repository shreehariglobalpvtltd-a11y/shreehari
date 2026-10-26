<?php
/**
 * cron/expire.php — release stale seat holds and abandoned bookings.
 *
 * Recommended: every 5 minutes.
 *   cPanel cron:  /usr/local/bin/php /home/USER/public_html/cron/expire.php
 *   or by URL:    https://yourdomain.com/cron/expire.php?token=YOUR_CRON_TOKEN
 */
declare(strict_types=1);
require __DIR__ . '/_cron.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

// 1. Expired seat holds (the 30-minute lock window).
$locksFreed = Seats::expireLocks();

// 2. Pending bookings that were never paid within their window.
/* Never sweep a booking the customer has already paid for. BookingService
   clears expires_at the moment proof arrives, so this NOT EXISTS pair is
   belt-and-braces -- it also protects rows whose clock was set before that
   fix existed, and any future path that forgets to stop the clock. A paid
   passenger losing their seat to a cron job is not a recoverable error. */
$stale = Database::fetchAll(
    "SELECT b.id, b.pnr FROM bookings b
      WHERE b.status = 'pending'
        AND b.expires_at IS NOT NULL
        AND b.expires_at < NOW()
        AND NOT EXISTS (
              SELECT 1 FROM payments p
               WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number, '')) <> ''
            )
        AND NOT EXISTS (
              SELECT 1 FROM payment_screenshots s WHERE s.booking_id = b.id
            )
      LIMIT 500"
);

$expired = 0;
foreach ($stale as $b) {
    try {
        // The SELECT ran outside any transaction, so a row can be approved
        // (pending->confirmed) or have proof land (expires_at NULLed) in the
        // gap before we reach it. Re-assert the FULL expirable condition in
        // the guarded UPDATE and release seats ONLY when it actually matched
        // one row — otherwise we would delete the seats out from under a
        // just-confirmed, paid passenger. rowCount is the authority here, not
        // the stale SELECT.
        $didExpire = Database::transaction(static function () use ($b): bool {
            $hit = Database::update('bookings',
                ['status' => 'expired', 'cancel_reason' => 'Payment window elapsed'],
                'id = :id AND status = :s AND expires_at IS NOT NULL AND expires_at < NOW()',
                ['id' => (int) $b['id'], 's' => 'pending']
            );
            if ($hit < 1) {
                return false;                                // no longer expirable — leave it alone
            }
            Seats::releaseBooking((int) $b['id']);          // free the seats
            BookingService::releaseCoupon((int) $b['id']);  // an unpaid booking gives its offer use back
            return true;
        });
        if ($didExpire) {
            $expired++;
            // Post-commit journal only (no messages) — never inside the closure,
            // or a rolled-back expiry would still have "happened" in the log.
            EventBus::emit('booking.expired', ['booking' => ['id' => (int) $b['id'], 'pnr' => (string) $b['pnr']]]);
        }
    } catch (Throwable $e) {
        Logger::error('expire booking failed', ['pnr' => $b['pnr'], 'err' => $e->getMessage()]);
    }
}

/* 3. SmartBook v5 (15 Oct 2026): unpaid pay-at-boarding (Cash) tickets N
   minutes before their own boarding time. OFF unless the office sets
   cod_autocancel_minutes > 0 (Admin -> Settings): the cash is normally
   paid AT boarding, so the owner decides whether this is ever wanted.
   Customer-made web tickets only (a desk sale is the desk's), never one
   with any verified payment, and through BookingService::cancel() so the
   seats, the bell and the passenger message are the normal ones. */
$codCut    = Settings::getInt('cod_autocancel_minutes', 0);
$codCancel = 0;
if ($codCut > 0) {
    $late = Database::fetchAll(
        "SELECT b.pnr FROM bookings b
           JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
           JOIN schedules s ON s.id = l.schedule_id JOIN routes r ON r.id = s.route_id
          WHERE b.status = 'confirmed' AND b.is_cod = 1 AND b.source = 'web' AND b.sold_by_admin_id IS NULL
            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified')
            AND TIMESTAMP(l.travel_date, COALESCE(l.boarding_time, s.dep_time_override, r.dep_time)) - INTERVAL :m MINUTE < NOW()
            AND TIMESTAMP(l.travel_date, COALESCE(l.boarding_time, s.dep_time_override, r.dep_time)) > NOW() - INTERVAL 6 HOUR
          LIMIT 100",
        ['m' => $codCut]
    );
    foreach ($late as $b) {
        try {
            BookingService::cancel((string) $b['pnr'], 'Cash not paid ' . $codCut . ' min before boarding (auto)', false, 'auto');
            $codCancel++;
        } catch (Throwable $e) {
            Logger::error('cod auto-cancel failed', ['pnr' => $b['pnr'], 'err' => $e->getMessage()]);
        }
    }
}

cron_done(['locksFreed' => $locksFreed, 'bookingsExpired' => $expired, 'codCancelled' => $codCancel]);
