<?php
/** Legacy tracker compatibility: use the existing verified applicant flow. */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requireRateLimit('app_status_track', Security::clientIp(), 30, 60);
    // A reference or partial phone is not identity proof. A letter-only query
    // must never become phone LIKE '%%' and reveal an unrelated application.
    // The active form uses WaVerify + /api/my-application.php; approved
    // applicants use the existing team portal. No parallel authentication.
    Response::error('पहिले आफ्नो नम्बर प्रमाणित गरेर "मेरो आवेदन" खोल्नुहोस् · Verify your number in My application, or sign in to the team portal.', 403, [
        'need' => 'verify',
        'application_url' => '/agreements/apply.html',
        'team_url' => '/team/',
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
