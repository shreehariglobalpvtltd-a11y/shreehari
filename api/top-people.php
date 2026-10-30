<?php
/**
 * /api/top-people.php — the home page's Top 3 staff, agents and partner companies
 * (23 Oct 2026, owner: "beautifully display Top 3 Staff, Top 3 Agents, and Top 3
 * Partners ... with real photos, names, roles, approved contact details, and rankings").
 *
 * GET -> {"ok":true,"staff":[...],"agent":[...],"partner":[...]}
 *        each person: {name, role, area, photo, phone, tel, wa, metric}, best first.
 *
 * The ranking and every field come from AgentApplications::top() and StaffApps::top():
 * only approved people the office shows on the website, only the contact each one
 * agreed to show, a figure only when it is real (passengers sold in 90 days).
 *
 * 31 Oct 2026 (owner: "form bharera verified admin le dinchha, home page ma photo
 * dekhiyos"): AgentPro members on the website (ApWeb::list - approved / KYC verified,
 * with a photo, ranked the same way) come first, then the older lists, one card per
 * person (ApWeb::merge: same last 10 phone digits or same name). Cached 2 minutes so
 * a new approval shows soon. An AgentPro failure only drops the AgentPro part.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/agentpro-web.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('This endpoint only answers GET.', 405);
    }
    Security::requireRateLimit('top_people', Security::clientIp(), 60, 60);

    $clean = static function (array $list): array {
        $out = [];
        foreach (array_slice($list, 0, 3) as $p) {
            $out[] = [
                'name'   => (string) ($p['name'] ?? ''),
                'role'   => (string) ($p['role'] ?? ''),
                'area'   => (string) ($p['area'] ?? ''),
                'photo'  => (string) ($p['photo'] ?? ''),
                'phone'  => (string) ($p['phone'] ?? ''),
                'tel'    => (string) ($p['tel'] ?? ''),
                'wa'     => (string) ($p['wa'] ?? ''),
                'metric' => (string) ($p['metric'] ?? ''),
            ];
        }
        return $out;
    };
    $safe = static function (callable $f): array {
        try {
            return $f();
        } catch (Throwable $e) {
            Logger::warning('Top people: ' . $e->getMessage());
            return [];
        }
    };

    // AgentPro first, then the older list (6 of them, so a duplicate dropped still leaves 3).
    $merged = static function (string $kind, callable $old) use ($safe): array {
        $ap = $safe(static fn(): array => ApWeb::list($kind, 3));
        $older = $safe($old);
        try {
            return ApWeb::merge($ap, $older, 3);
        } catch (Throwable $e) {
            Logger::warning('Top people (merge): ' . $e->getMessage());
            return $older;
        }
    };

    Response::$publicCacheSeconds = 120;
    Response::json([
        'ok'      => true,
        'staff'   => $clean($merged('staff', static fn(): array => StaffApps::top(6))),
        'agent'   => $clean($merged('agent', static fn(): array => AgentApplications::top('agent', 6))),
        'partner' => $clean($merged('partner', static fn(): array => AgentApplications::top('partner', 6))),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
