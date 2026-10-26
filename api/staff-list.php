<?php
/**
 * GET /api/staff-list.php — "Our team" on the home page (22 Oct 2026).
 *
 *   200 {"ok":true,"count":N,"roles":["driver",…],"items":[{"name":"…","role":"driver",
 *        "roleEn":"Driver","roleNe":"चालक","district":"…","photo":"/api/staff-photo.php?k=…",
 *        "phone":"+977 98…","tel":"+97798…"}]}
 *
 * Who is on it: approved applications the office left on the website
 * (show_on_website). Only the fields Settings 'staff_public_fields' allows
 * are filled — the rest come back as '' (StaffApps::directory builds the
 * row key by key). Never an ID number, address, bank detail, date of birth
 * or any status. Identical for every visitor, so it is cached for 60 s.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/staffapplications.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('staff_list', Security::clientIp(), 60, 60);

    $items = StaffApps::directory();
    $roles = [];
    foreach ($items as $it) {
        if ($it['role'] !== '' && !in_array($it['role'], $roles, true)) {
            $roles[] = $it['role'];
        }
    }
    usort($roles, static fn($a, $b) => array_search($a, StaffApps::ROLES, true) <=> array_search($b, StaffApps::ROLES, true));

    Response::$publicCacheSeconds = 60;
    Response::json(['ok' => true, 'count' => count($items), 'roles' => $roles, 'items' => $items]);
} catch (Throwable $e) {
    Response::serverError($e);
}
