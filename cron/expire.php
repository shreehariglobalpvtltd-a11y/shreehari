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

// 3. One-time WhatsApp ticket codes (includes/wachat.php): expired unused
//    codes, and spent ones after a day. Guarded so a database where the
//    2026-09-26 migration has not run yet still expires bookings above.
$waCodes = 0;
try {
    require_once INCLUDE_PATH . '/wachat.php';
    $waCodes = WaChat::expire();
} catch (Throwable $e) {
    Logger::warning('wa_chat_tokens cleanup skipped: ' . $e->getMessage());
}

cron_done(['locksFreed' => $locksFreed, 'bookingsExpired' => $expired, 'waCodesCleared' => $waCodes]);
