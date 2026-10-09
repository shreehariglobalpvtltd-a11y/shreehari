<?php
/**
 * admin/screenshot.php?id=<bookingId>[&sid=<screenshotId>][&w=<thumb px>]
 * — stream a payment screenshot.
 *
 * Uploads live outside the web root's reach (PHP execution is killed
 * there and, in production, the folder can sit below public_html). Only
 * a signed-in staff member with payments.view may look at a proof.
 *
 * 9 Oct 2026 (anti-fake): &sid= opens ANY screenshot of the booking (the
 * queue's lightbox shows them all - only the latest could be seen before),
 * still scoped to that booking id. &w=160..480 returns a small JPEG preview
 * drawn with GD, for the queue's thumbnails (a phone loading 20 full
 * screenshots was the slowest thing on the page). Desk isolation applies
 * here too: a counter clerk cannot open another desk's proof by id.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('payments.view');

$bookingId = (int) ($_GET['id'] ?? 0);
$shotId    = (int) ($_GET['sid'] ?? 0);
$thumbW    = (int) ($_GET['w'] ?? 0);

if (!CounterDesk::bookingVisible($bookingId)) {
    http_response_code(404);
    exit('No screenshot on file.');
}

$shot = $shotId > 0
    ? Database::fetch(
        'SELECT id, file_path, mime_type FROM payment_screenshots WHERE id = :s AND booking_id = :b LIMIT 1',
        ['s' => $shotId, 'b' => $bookingId]
      )
    : Database::fetch(
        'SELECT id, file_path, mime_type FROM payment_screenshots WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
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

/* Small preview for the queue. A file is never changed after upload, so the
   preview may be cached privately by this browser. Falls back to the full
   file whenever GD cannot read it (a PDF proof, an odd format). */
if ($thumbW > 0 && str_starts_with($mime, 'image/') && function_exists('imagecreatefromstring')) {
    $thumbW = max(120, min(480, $thumbW));
    $info = @getimagesize($path);
    if ($info !== false && $info[0] > 0 && $info[0] * $info[1] <= 40000000) {
        $raw = @file_get_contents($path);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($src !== false) {
            $w = min($thumbW, imagesx($src));
            $h = max(1, (int) round(imagesy($src) * $w / max(1, imagesx($src))));
            $h = min($h, $w * 3);   // a very tall capture is cropped to its top part
            $dst = imagecreatetruecolor($w, $h);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            $srcH = (int) round($h * imagesx($src) / $w);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), min(imagesy($src), $srcH));
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            imagejpeg($dst, null, 72);
            imagedestroy($dst);
            imagedestroy($src);
            exit;
        }
    }
}

Response::inline($path, $mime);
