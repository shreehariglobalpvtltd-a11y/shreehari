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

require_once INCLUDE_PATH . '/holdrelease.php';

/* 1 + 2. Every unpaid HOLD frees itself (op5, 25 Nov 2026): expired checkout
   seat locks, then pending bookings never paid within their window. The
   sweep that lived here moved, unchanged, into HoldRelease::expireStale()
   (same SQL, same guarded UPDATE, same Seats::releaseBooking +
   releaseCoupon + booking.expired) so a test can drive it; it adds one
   audit row per release and the optional hold_expiry_notice_on message.
   A booking with a UTR / screenshot, COD and every confirmed sale are
   never touched - those wait for a person. */
$locksFreed = HoldRelease::sweepLocks();
$sweep      = HoldRelease::expireStale(500);
$expired    = $sweep['expired'];

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

cron_done(['locksFreed' => $locksFreed, 'bookingsExpired' => $expired, 'holdNotices' => $sweep['noticed'], 'codCancelled' => $codCancel]);
