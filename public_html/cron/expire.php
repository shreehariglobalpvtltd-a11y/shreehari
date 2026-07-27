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
$stale = Database::fetchAll(
    "SELECT id, pnr FROM bookings
      WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()
      LIMIT 500"
);

$expired = 0;
foreach ($stale as $b) {
    try {
        Database::transaction(static function () use ($b, &$expired): void {
            Seats::releaseBooking((int) $b['id']);          // free the seats
            Database::update('bookings',
                ['status' => 'expired', 'cancel_reason' => 'Payment window elapsed'],
                'id = :id AND status = :s',
                ['id' => (int) $b['id'], 's' => 'pending']
            );
            $expired++;
        });
    } catch (Throwable $e) {
        Logger::error('expire booking failed', ['pnr' => $b['pnr'], 'err' => $e->getMessage()]);
    }
}

cron_done(['locksFreed' => $locksFreed, 'bookingsExpired' => $expired]);
