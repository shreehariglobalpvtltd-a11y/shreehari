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
 *
 * 31 Oct 2026: AgentPro staff on the website (ApWeb::staffDirectory - approved
 * / KYC verified, with a photo; role = the AgentPro role key such as counter /
 * manager / driver, roleEn = the designation, loc = the branch, the
 * visiting-card number) come first, then the list above without anyone already
 * there (ApWeb::merge: same last 10 phone digits or same name). An AgentPro
 * failure only drops the AgentPro part.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/agentpro-web.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('staff_list', Security::clientIp(), 60, 60);

    $items = StaffApps::directory();
    try {
        $items = ApWeb::merge(ApWeb::staffDirectory(), $items);
    } catch (Throwable $e) {
        Logger::warning('Staff list (AgentPro): ' . $e->getMessage());
    }
    $roles = [];
    foreach ($items as $it) {
        if ($it['role'] !== '' && !in_array($it['role'], $roles, true)) {
            $roles[] = $it['role'];
        }
    }
    // the older form's roles in their order, then the AgentPro roles (counter, manager …)
    $pos = static fn(string $r): int => ($i = array_search($r, StaffApps::ROLES, true)) === false ? 99 : (int) $i;
    usort($roles, static fn($a, $b) => $pos((string) $a) <=> $pos((string) $b));

    Response::$publicCacheSeconds = 60;
    Response::json(['ok' => true, 'count' => count($items), 'roles' => $roles, 'items' => $items]);
} catch (Throwable $e) {
    Response::serverError($e);
}
