<?php
/**
 * admin/staff-file.php?id=<id>&f=<slot>&e=<expiry>&s=<sig> — streams one
 * file of a staff application (22 Oct 2026).
 *
 * WHY A SCRIPT: an ID card, a licence or a marksheet is never a link under
 * /uploads/ (nginx denies /uploads/staff/ and the names are random). The
 * only door is this page, and it needs ALL of:
 *   - a signed-in office user who may see KYC in full (super-admin or
 *     manager, the same rule as Admin -> Agreements),
 *   - a signature made for THIS user, this file and an expiry at most
 *     15 minutes away (StaffApps::fileUrl) — a copied link dies quickly and
 *     works for nobody else,
 *   - a stored path that is really inside uploads/staff/ (StaffApps::absPath).
 * The type is read from the bytes, the answer is never cached, and every
 * view is written to the audit trail.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/staffapplications.php';
$admin = admin_boot('drivers.view');

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};

$adminId = (int) ($admin['id'] ?? 0);
$id      = (int) ($_GET['id'] ?? 0);
$slot    = (string) ($_GET['f'] ?? '');
$exp     = (int) ($_GET['e'] ?? 0);
$sig     = (string) ($_GET['s'] ?? '');

if (!(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager')) {
    $deny(403, 'Only a super-admin or a manager may open staff documents.');
}
if ($id <= 0 || $exp < time() || $exp > time() + 3600 || !hash_equals(StaffApps::fileSig($id, $slot, $exp, $adminId), $sig)) {
    $deny(403, 'This link has expired — open the application again.');
}
$col = StaffApps::slotColumn($slot);
$app = $col !== null && StaffApps::available() ? StaffApps::find($id) : null;
$abs = $app !== null && !empty($app[$col]) ? StaffApps::absPath((string) $app[$col]) : null;
if ($abs === null) {
    $deny(404, 'No file on record.');
}
if (!empty($_GET['t']) && $slot === 'photo') {
    $t = (string) preg_replace('/\.(?:jpe?g|png)$/i', '', $abs) . '-t.jpg';
    $abs = is_file($t) ? $t : $abs;
}

$mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: '');
$inline = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
$ext = $inline[$mime] ?? 'bin';
if ($ext === 'bin') {
    $mime = 'application/octet-stream';
}

if (empty($_GET['t'])) {
    Logger::audit('staff_app.file_viewed', 'staff_application', StaffApps::refFor($id), null,
        ['file' => $slot, 'mime' => $mime, 'size' => (int) filesize($abs)], 'Staff document viewed');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Content-Disposition: ' . ($ext === 'bin' ? 'attachment' : 'inline') . '; filename="' . StaffApps::refFor($id) . '-' . $slot . '.' . $ext . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
