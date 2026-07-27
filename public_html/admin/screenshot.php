<?php
/**
 * admin/screenshot.php?id=<bookingId> — stream a payment screenshot.
 *
 * Uploads live outside the web root's reach (PHP execution is killed
 * there and, in production, the folder can sit below public_html). Only
 * a signed-in staff member with payments.view may look at a proof.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('payments.view');

$bookingId = (int) ($_GET['id'] ?? 0);

$shot = Database::fetch(
    'SELECT file_path, mime_type FROM payment_screenshots WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
    ['b' => $bookingId]
);

if ($shot === null) {
    http_response_code(404);
    exit('No screenshot on file.');
}

$path = UPLOAD_PATH . '/' . ltrim((string) $shot['file_path'], '/');
if (!is_file($path)) {
    http_response_code(404);
    exit('The file is no longer available.');
}

$mime = (string) ($shot['mime_type'] ?: 'application/octet-stream');
Response::inline($path, $mime);
