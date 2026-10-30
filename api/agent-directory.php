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
 * otherwise the first 2 and last 3 digits. 19 Oct 2026: plus the
 * office-typed public address, the photo URL (only when the office allowed
 * the photo) and a WhatsApp number (only with the full phone). Nothing else ever leaves here —
 * no ID number, home address, bank detail, email, or any status beyond the
 * two positive badges. Built key by key in AgentApplications::directory()
 * and again below, so a new column can never leak by accident.
 *
 * 31 Oct 2026: AgentPro agents and agencies on the website (ApWeb::directory -
 * approved / KYC verified, with a photo; the visiting-card number, the branch,
 * the /api/ap-photo.php face; addr and logo stay empty) come first, then the
 * list above without anyone already there (ApWeb::merge: same last 10 phone
 * digits or same name). An AgentPro failure only drops the AgentPro part.
 *
 * Identical for every visitor, so it may be cached for 2 minutes (a new
 * approval shows soon).
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/agentpro-web.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('agent_directory', Security::clientIp(), 60, 60);

    $rows = AgentApplications::directory();
    try {
        $rows = ApWeb::merge(ApWeb::directory(), $rows);
    } catch (Throwable $e) {
        Logger::warning('Agent directory (AgentPro): ' . $e->getMessage());
    }

    // Both lists go through the same key-by-key builder.
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'name'     => (string) $r['name'],
            'kind'     => $r['kind'] === 'partner' ? 'partner' : 'agent',
            'area'     => (string) $r['area'],
            'phone'    => (string) $r['phone'],
            'verified' => (bool) $r['verified'],
            'signed'   => (bool) $r['signed'],
            // 19 Oct 2026: the office-typed shop / office address, the photo
            // URL (only when the office allowed it) and the WhatsApp number
            // (only when the full phone may be shown).
            'addr'     => (string) ($r['addr'] ?? ''),
            'photo'    => (string) ($r['photo'] ?? ''),
            // 22 Oct 2026: a partner company's logo (partners only)
            'logo'     => (string) ($r['logo'] ?? ''),
            'wa'       => (string) ($r['wa'] ?? ''),
        ];
    }

    Response::$publicCacheSeconds = 120;
    Response::json(['ok' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    Response::serverError($e);
}
