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
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/staffapplications.php';

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

    Response::$publicCacheSeconds = 300;
    Response::json([
        'ok'      => true,
        'staff'   => $clean($safe(static fn(): array => StaffApps::top(3))),
        'agent'   => $clean($safe(static fn(): array => AgentApplications::top('agent', 3))),
        'partner' => $clean($safe(static fn(): array => AgentApplications::top('partner', 3))),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
