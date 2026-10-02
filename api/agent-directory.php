<?php
/**
 * GET /api/agent-directory.php — the public list of S Hari Global agents
 * and partners, for /agreements/ (27 Sep 2026).
 *
 *   200 {"ok":true,"count":N,"items":[{"name":"...","kind":"agent"|"partner",
 *        "area":"...","phone":"98•••••123" | full,"verified":bool,"signed":bool}]}
 *
 * Who is on it: approved applications, and active agent accounts the office
 * has left listed (admin_profiles.public_listing). The phone is FULL only
 * when that person agreed (application public_ok / admin public_phone),
 * otherwise the first 2 and last 3 digits. Nothing else ever leaves here —
 * no ID number, address, bank detail, email, photo, or any status beyond the
 * two positive badges. Built key by key in AgentApplications::directory()
 * and again below, so a new column can never leak by accident.
 *
 * Identical for every visitor, so it may be cached for 5 minutes.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('agent_directory', Security::clientIp(), 60, 60);

    $items = [];
    foreach (AgentApplications::directory() as $r) {
        $items[] = [
            'name'     => (string) $r['name'],
            'kind'     => $r['kind'] === 'partner' ? 'partner' : 'agent',
            'area'     => (string) $r['area'],
            'phone'    => (string) $r['phone'],
            'verified' => (bool) $r['verified'],
            'signed'   => (bool) $r['signed'],
        ];
    }

    Response::$publicCacheSeconds = 300;
    Response::json(['ok' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    Response::serverError($e);
}
