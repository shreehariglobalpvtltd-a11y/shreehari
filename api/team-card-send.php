<?php
/**
 * api/team-card-send.php — Send / Resend ID & Visiting Cards via WhatsApp Cloud API.
 *
 * Requirements:
 *  - Automated WhatsApp Cloud API delivery of ID and visiting cards
 *  - Prevent duplicate sends
 *  - Admin Retry / Resend
 *  - Manual fallback data when auto delivery is unconfigured or failed
 */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/carddelivery.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();

    $admin = Auth::admin();
    if ($admin === null || !(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager' || Auth::can('staff.manage'))) {
        Response::error('Only authorized administrators may dispatch digital ID cards.', 403);
    }

    $ref   = strtoupper(trim((string) Response::field('ref', '')));
    $force = (bool) Response::field('force', false);

    if ($ref === '') {
        Response::error('Missing card reference.', 400);
    }

    $res = CardDelivery::send($ref, $force);

    Response::json([
        'ok'     => $res['ok'],
        'status' => $res['status'],
        'detail' => $res['detail'],
        'manual' => $res['manual'],
    ]);
} catch (Throwable $e) {
    Logger::error('team-card-send error: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
