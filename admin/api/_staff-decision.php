<?php
/**
 * admin/api/_staff-decision.php — the shared body of the three office JSON
 * endpoints for staff applications (22 Oct 2026, the owner's spec):
 *
 *   POST /admin/api/staff-approve.php   {id | ref}
 *   POST /admin/api/staff-reject.php    {id | ref, reason}   reason: 5+ characters, sent to the applicant
 *   POST /admin/api/staff-suspend.php   {id | ref, reason}
 *
 *   JSON or form body, X-CSRF-Token header (or the shg_csrf field).
 *   200 {"ok":true,"ref":"STF-000012","status":"approved","wa":true,"waLink":null}
 *   4xx {"ok":false,"error":"…"}
 *
 * The same StaffApps::decide() as the buttons on Admin -> Staff applications:
 * the audit trail and the WhatsApp to the applicant happen there. Needs a
 * signed-in office user with staff.manage (super-admin, manager, operations).
 * Not an endpoint by itself: each wrapper sets STAFF_DECISION first.
 */
declare(strict_types=1);

if (!defined('STAFF_DECISION')) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/staffapplications.php';
$admin = admin_boot('drivers.view');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    if (!(Auth::isSuperadmin() || Auth::can('staff.manage'))) {
        Response::error('Only a super-admin, a manager or operations can decide staff applications.', 403);
    }
    if (!StaffApps::available()) {
        Response::error('Run database/upgrade-2026-10-22-staff-applications.sql first.', 503);
    }
    $id = (int) Response::field('id', 0);
    if ($id <= 0) {
        $id = StaffApps::idFromRef((string) Response::field('ref', ''));
    }
    if ($id <= 0) {
        Response::invalid(['id' => 'required'], 'Which application? Send its id or STF number.');
    }
    $r = StaffApps::decide($id, (string) STAFF_DECISION, (int) ($admin['id'] ?? 0), ['reason' => (string) Response::field('reason', '')]);
    Response::json(['ok' => true, 'ref' => StaffApps::refFor($id)] + $r);
} catch (StaffAppError $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::serverError($e);
}
