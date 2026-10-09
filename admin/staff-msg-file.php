<?php
/**
 * admin/staff-msg-file.php?m=<message id> — streams one Office chat
 * attachment (photo, PDF, voice note). 9 Oct 2026.
 *
 * WHY A SCRIPT AND NOT A LINK: everything under /uploads/ is served by nginx
 * to anyone who knows the path, and a chat photo can be a passenger's ID or a
 * payment screenshot. So the files live under uploads/staff-msg/ (denied in
 * both vhosts, deploy/nginx-add-deny-rules.sh) and are NEVER linked as
 * /uploads/…; the only way to read one is through this page, which checks
 * StaffMsg::mayOpen() on the message's thread (the office: any; an agent: own
 * thread and own ticket; a counter: own thread, desk isolation), refuses any
 * stored path outside staff-msg/, reads the real type from the bytes and
 * streams it privately. Byte ranges are answered so a voice note can seek
 * (Safari will not play audio without them). A foreign or unknown message is
 * a 404 — the response never confirms what exists.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/staffmsg.php';
$admin = admin_boot('bookings.view');

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};

if (!StaffMsg::canUse()) {
    $deny(404, 'Not found.');
}
$msgId = (int) ($_GET['m'] ?? 0);
$row   = $msgId > 0
    ? Database::fetch('SELECT id, thread_id, attach_path, attach_mime FROM staff_messages WHERE id = :m', ['m' => $msgId])
    : null;
$t = $row !== null ? StaffMsg::threadById((int) $row['thread_id']) : null;
if ($row === null || $t === null || !StaffMsg::mayOpen($t)) {
    $deny(404, 'Not found.');
}
$abs = StaffMsg::attachAbs((string) ($row['attach_path'] ?? ''));
if ($abs === null) {
    $deny(404, 'Not found.');
}
// Nothing else needs the session — release it so the chat's poll is never queued behind a download.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$mime = '';
try {
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($abs);
} catch (Throwable $e) {
    $mime = '';
}
$map = StaffMsg::MIME[$mime] ?? null;
if ($map === null) {
    $mime = 'application/octet-stream';
    $ext  = 'bin';
} else {
    $ext = $map[1];
    // A voice note recorded as a WebM / MP4 container is played as audio.
    if ($map[0] === 'voice') {
        $mime = match ($ext) { 'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', default => $mime };
    }
}
$size  = (int) filesize($abs);
$start = 0;
$end   = $size - 1;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                       // the last N bytes
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        $end   = $m[2] !== '' ? min($end, (int) $m[2]) : $end;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline') . '; filename="office-chat-' . $msgId . '.' . $ext . '"');
// Files never change (random names) — a short PRIVATE cache keeps scrolling smooth.
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');

$fh = fopen($abs, 'rb');
if ($fh === false) {
    $deny(404, 'Not found.');
}
if ($start > 0) {
    fseek($fh, $start);
}
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, (int) min(65536, $left));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
exit;
