<?php
/**
 * admin/scan-page.php?type=agent|staff&id=<id>&p=<page>[&t=1] — one page of
 * the hand-filled paper form an applicant photographed and sent with the
 * application (scan-back, 30 Oct 2026). The pages live in the row as data:
 * URIs (scan_doc, upgrade-2026-10-24-scan-autofill.sql); the office pages
 * show thumbnails from here instead of carrying megabytes of base64.
 *
 * The paper carries ID numbers, dates of birth and bank details, so a
 * super-admin or a manager only — the same people who see the ID photo.
 * t=1 is the thumbnail on the application page and is not logged; opening
 * a page full size is.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/formscan.php';

$type  = (string) ($_GET['type'] ?? '') === 'staff' ? 'staff' : 'agent';
$admin = admin_boot($type === 'staff' ? 'drivers.view' : 'customers.view');   // the doors of the two application pages

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};

if (!(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager')) {
    $deny(403, 'Only a super-admin or a manager may open a scanned application form.');
}

$id = (int) ($_GET['id'] ?? 0);
$p  = (int) ($_GET['p'] ?? 0);
if ($type === 'staff') {
    require_once INCLUDE_PATH . '/staffapplications.php';
    $row = $id > 0 && StaffApps::scanReady() ? StaffApps::find($id) : null;
    $ref = StaffApps::refFor($id);
} else {
    require_once INCLUDE_PATH . '/agentapplications.php';
    $row = $id > 0 && AgentApplications::scanReady() ? AgentApplications::find($id) : null;
    $ref = (string) ($row['ref'] ?? '');
}
$pages = $row !== null ? FormScan::pages($row) : [];
if (!isset($pages[$p]) || preg_match('#^data:(image/(jpeg|png|webp));base64,#', $pages[$p], $m) !== 1) {
    $deny(404, 'No scanned page on record.');
}
$bytes = base64_decode(substr($pages[$p], strlen($m[0])), true);
if ($bytes === false || @getimagesizefromstring($bytes) === false) {
    $deny(404, 'No scanned page on record.');
}

if (empty($_GET['t'])) {
    Logger::audit($type === 'staff' ? 'staff_app.scan_viewed' : 'agreement_app.scan_viewed',
        $type === 'staff' ? 'staff_application' : 'agent_application', $ref, null, ['page' => $p + 1], 'Scanned paper form viewed');
}

header('Content-Type: ' . $m[1]);
header('Content-Length: ' . (string) strlen($bytes));
header('Content-Disposition: inline; filename="' . ($ref !== '' ? $ref : 'application') . '-scan-' . ($p + 1) . '.' . ($m[2] === 'jpeg' ? 'jpg' : $m[2]) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $bytes;
