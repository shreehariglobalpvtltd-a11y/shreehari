<?php
/**
 * =====================================================================
 *  POST /api/complaint.php — the Help bot's "Raise complaint" (23 Sep 2026).
 *
 *  Request  { name?, phone, pnr?, category?, message, lang? }
 *  Response { ticketId, waSent, waLink }
 *
 *  Behind the `complaints_on` switch: while it is off this answers 503 and
 *  the bot falls back to api/enquiry.php (source='complaint'), exactly the
 *  path it used before this file existed. See includes/complaints.php.
 * ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/complaints.php';

try {
    Security::requirePost();
    Security::requireCsrf();
    Security::requireRateLimit('complaint_create', Security::clientIp(), 6, 600);

    if (!Complaints::enabled()) {
        Response::error('The complaint desk is not switched on.', 503);
    }

    try {
        $out = Complaints::file([
            'name'     => Response::field('name', ''),
            'phone'    => Response::field('phone', ''),
            'pnr'      => Response::field('pnr', ''),
            'category' => Response::field('category', 'other'),
            'message'  => Response::field('message', ''),
            'lang'     => Response::field('lang', 'en'),
        ], Security::clientIp(), Security::userAgent(255));
    } catch (InvalidArgumentException $bad) {
        Response::invalid([$bad->getMessage() => $bad->getMessage() === 'phone' ? 'Enter a valid mobile number.' : 'Tell us what happened.']);
    }

    Response::success([
        'ticketId' => $out['ticketId'],
        'waSent'   => $out['waSent'],
        'waLink'   => $out['waLink'],
    ], 'Complaint ' . $out['ticketId'] . ' registered.');
} catch (Throwable $e) {
    Response::serverError($e);
}
