<?php
/**
 * admin/pending-count.php — tiny JSON poll target for the sidebar badge,
 * the new-payment chime and the bookings-list "N new bookings" pill
 * (see admin_poll_js() in _guard.php).
 *
 * 3 Sep 2026: opened to bookings.view so a counter agent's "My Bookings"
 * gets the live pill too. The answer is scoped: an agent's maxBooking is
 * the newest sale THEY made (their list shows nothing else), and `pending`
 * is only reported to roles that can act on payments — everyone else gets
 * 0, which keeps the chime/badge logic inert.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pending = Auth::can('payments.view')
    ? (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")
    : 0;

// Highest booking id right now — the bookings-list live pill compares this to
// the max id it saw at page load to count NEW bookings without reloading the
// whole list. Cheap PK MAX(), no scan. Scoped for counter agents.
$scopeId    = Auth::bookingScopeAdminId();
$maxBooking = $scopeId !== null
    ? (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM bookings WHERE sold_by_admin_id = :s', ['s' => $scopeId], 0)
    : (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM bookings');

echo json_encode(['pending' => $pending, 'maxBooking' => $maxBooking]);
