<?php
/**
 * upgrade-2026-10-04-wa-hi-queue.php — queue the tickets of buses that have
 * not left yet, so those passengers get theirs the moment they say "hi".
 *
 * Run AFTER database/upgrade-2026-10-04-wa-hi-queue.sql:
 *   php database/upgrade-2026-10-04-wa-hi-queue.php            (VPS, as www-data)
 *
 * Confirmed bookings made in the last 7 days whose bus has not gone yet get a
 * WAITING row for the customer, the second number and the clerk who sold it.
 * Nothing is SENT here - a send happens only when that number writes to the
 * AiSensy number (or someone presses "Send now" on Admin -> WhatsApp tickets).
 * Idempotent: an existing row is left alone.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waqueue.php';

if (!WaQueue::ready()) {
    fwrite(STDERR, "wa_ticket_queue missing - run the .sql first.\n");
    exit(1);
}

$rows = Database::fetchAll(
    "SELECT b.* FROM bookings b
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
      WHERE b.status IN ('confirmed','completed')
        AND b.created_at >= NOW() - INTERVAL 7 DAY
        AND l.travel_date >= CURDATE()
      ORDER BY b.id"
);
$made = 0;
foreach ($rows as $b) {
    foreach (WaQueue::recipients($b) as [$phone, $role]) {
        $before = (int) Database::scalar('SELECT COUNT(*) FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => (int) $b['id'], 'p' => $phone], 0);
        if ($before === 0 && WaQueue::enqueue((int) $b['id'], (string) $b['pnr'], $phone, $role) > 0) {
            $made++;
        }
    }
}
echo 'bookings: ' . count($rows) . ", waiting rows added: {$made} (nothing sent)\n";
