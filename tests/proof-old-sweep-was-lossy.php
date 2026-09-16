<?php
/**
 * Demonstrates the bug the paid-pending fix closes, by running the OLD
 * cron/expire.php query and the NEW one against the same paid booking.
 *
 * OLD:  ...WHERE status='pending' AND expires_at < NOW()          -> matches
 * NEW:  ...AND NOT EXISTS(utr) AND NOT EXISTS(screenshot)         -> no match
 *
 * A match means the sweep would have expired the booking and released the
 * seats of a passenger who had already paid.
 *
 *   php -c .claude/php-dev.ini tests/proof-old-sweep-was-lossy.php
 *
 * Read-only apart from one throwaway booking it creates and deletes.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const TD    = '2099-11-12';
const PHONE = '9100000772';

$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone = '" . PHONE . "'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules',    'travel_date = :d', ['d' => TD]);
};
$cleanup();

$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");

$b = BookingService::create([
    'routeId'    => (int) $route['id'],
    'travelDate' => TD,
    'seats'      => ['L21'],
    'passengers' => [['name' => 'Paid Passenger', 'age' => 30, 'gender' => 'Male']],
    'contact'    => ['phone' => PHONE],
    'bookingMode' => 'sharing',
    'paymentMethod' => 'upi',
    'isCod'      => false,
    'boarding'   => '',
]);

BookingService::submitPaymentProof($b['pnr'], [
    'utr' => '9988776655443', 'payerName' => 'Paid Passenger', 'method' => 'upi',
]);

// Put a stale clock back on, exactly as the pre-fix code would have left it.
Database::query("UPDATE bookings SET expires_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE pnr = :p",
    ['p' => $b['pnr']]);

$old = Database::fetchAll(
    "SELECT id, pnr FROM bookings
      WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()
        AND pnr = :p",
    ['p' => $b['pnr']]
);

$new = Database::fetchAll(
    "SELECT b.id, b.pnr FROM bookings b
      WHERE b.status = 'pending'
        AND b.expires_at IS NOT NULL
        AND b.expires_at < NOW()
        AND NOT EXISTS (SELECT 1 FROM payments p
                         WHERE p.booking_id = b.id AND TRIM(COALESCE(p.utr_number,'')) <> '')
        AND NOT EXISTS (SELECT 1 FROM payment_screenshots s WHERE s.booking_id = b.id)
        AND b.pnr = :p",
    ['p' => $b['pnr']]
);

echo "\nBooking {$b['pnr']} — customer paid, UTR 9988776655443, admin has not looked.\n\n";
printf("  OLD cron query matched it : %s  %s\n", count($old) ? 'YES' : 'no',
    count($old) ? '<-- seats would have been released, money already sent' : '');
printf("  NEW cron query matches it : %s  %s\n", count($new) ? 'YES' : 'no',
    count($new) ? '' : '<-- protected, stays pending for the admin');

$verdict = (count($old) === 1 && count($new) === 0);
echo "\n  " . ($verdict ? "\033[32mBUG CONFIRMED AND FIXED\033[0m" : "\033[31mINCONCLUSIVE\033[0m") . "\n\n";

$cleanup();
exit($verdict ? 0 : 1);
