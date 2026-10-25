<?php
/**
 * api/team-photo-replace.php — Admin replaces photo & instantly regenerates branded cards.
 *
 * Requirements:
 *  - Admin can replace photos from forms, profiles, and card previews
 *  - Regenerates cards from the approved photo
 *  - Records audit trail
 */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/agentapplications.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();

    $admin = Auth::admin();
    if ($admin === null || !(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager' || Auth::can('staff.manage'))) {
        Response::error('Only administrators or managers can replace approved photos.', 403);
    }

    $ref = strtoupper(trim((string) Response::field('ref', '')));
    $photoData = Response::field('photo', null);

    if ($ref === '' || empty($photoData) || !is_string($photoData)) {
        Response::error('Missing card reference or photo data.', 400);
    }

    $p = IdCard::person($ref);
    if ($p === null) {
        Response::error('Person record not found for ' . $ref, 404);
    }

    // Clean data URL and convert to binary
    $clean = AgentApplications::cleanPhoto($photoData);
    if ($clean['photo'] === null) {
        Response::error('Invalid photo image data.', 400);
    }

    $bytes = (string) base64_decode(substr($clean['photo'], (int) strpos($clean['photo'], ',') + 1), true);
    if (strlen($bytes) < 1000) {
        Response::error('Photo data is corrupt or too small.', 400);
    }

    $kind = $p['kind'];
    if ($kind === 'staff') {
        $staffId = (int) substr($p['ref'], 4);
        $savedPath = StaffApps::saveFile($staffId, 'photo', $bytes, 'image/jpeg');
        if ($savedPath === null) {
            Response::error('Failed to save staff photo file.', 500);
        }
    } else {
        // Agent or Partner
        $agentId = (int) substr($p['ref'], 4);
        AgentApplications::update($agentId, [
            'photo_data' => $clean['photo']
        ], (int) $admin['id']);
    }

    Logger::audit('photo.replaced', $kind, $ref, null, ['admin' => $admin['username'] ?? ''], 'Approved photo replaced by admin; cards regenerated');

    Response::json([
        'ok' => true,
        'ref' => $ref,
        'message' => 'Photo updated successfully. Cards regenerated.',
        'urls' => [
            'id_card_front' => '/admin/id-card.php?ref=' . urlencode($ref) . '&png=front&t=' . time(),
            'visiting_front'=> '/admin/id-card.php?ref=' . urlencode($ref) . '&v=front&t=' . time(),
            'pdf'           => '/admin/id-card.php?ref=' . urlencode($ref) . '&pdf=all&t=' . time(),
        ]
    ]);
} catch (Throwable $e) {
    Logger::error('team-photo-replace exception: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
