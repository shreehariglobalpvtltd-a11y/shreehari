<?php
/**
 * admin/pending-count.php — tiny JSON poll target for the sidebar badge
 * and new-payment chime (see admin_poll_js() in _guard.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('payments.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pending = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");

echo json_encode(['pending' => $pending]);
