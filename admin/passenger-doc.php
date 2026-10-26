<?php
/**
 * admin/passenger-doc.php?id=<passenger_documents.id>[&dl=1] — stream ONE
 * passenger photo / ID document (17 Sep 2026).
 *
 * These are identity documents. nginx serves /uploads/ as static bytes to
 * anyone who knows the path, so a document is never linked by its path
 * anywhere — this script is the only reader. It requires a signed-in staff
 * session holding bookings.view (the same door as the ticket page) and, for
 * a counter agent, that the booking was sold by them
 * (bookings.sold_by_admin_id = self). A foreign document answers 404, not
 * 403: confirming the row exists is itself a disclosure.
 *
 * Mirrors admin/screenshot.php (payment proofs) but with a per-document id,
 * an inline / attachment switch (?dl=1) and a readable download name
 * (<PNR>-<kind>-<seat>.<ext>) so a file saved by the desk still says whose
 * it is.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/passengerdocs.php';
$admin = admin_boot('bookings.view');

$id  = (int) ($_GET['id'] ?? 0);
$doc = $id > 0 ? PassengerDocs::get($id) : null;

// Same agent-scope disclosure guard as booking-view.php: not found, not forbidden.
$scopeId = Auth::bookingScopeAdminId();
if ($doc !== null && $scopeId !== null && (int) ($doc['sold_by_admin_id'] ?? 0) !== $scopeId) {
    Logger::warning('Agent tried to open a passenger document on a booking they did not sell', [
        'admin' => $admin['username'] ?? '',
        'doc'   => $id,
    ]);
    $doc = null;
}

if ($doc === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('No such document.');
}

try {
    $path = PassengerDocs::absolutePath($doc);
} catch (Throwable $e) {
    $path = '';
}
if ($path === '' || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The file is no longer available.');
}

// Only the types the store accepts are ever announced; anything else is
// served as opaque bytes so a browser never sniffs it into something live.
$mime = (string) ($doc['mime_type'] ?? '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    $mime = 'application/octet-stream';
}
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim(
    (string) ($doc['pnr'] ?? 'passenger') . '-' . (string) ($doc['kind'] ?? 'doc') . '-' . (string) ($doc['seat_no'] ?? ''),
    '-'
)) . ($ext !== '' ? '.' . $ext : '');
$disposition = ((string) ($_GET['dl'] ?? '') === '1') ? 'attachment' : 'inline';

if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
}
readfile($path);
exit;
