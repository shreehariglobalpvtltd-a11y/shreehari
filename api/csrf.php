<?php
/**
 * api/csrf.php — hand the page its CURRENT session CSRF token.
 *
 * The app shell carries the token in SHG_BOOT at render time. Since the
 * perf pass (11 Sep 2026) the service worker may open a returning visitor
 * on the last-good cached shell when the network is slow, and a PHP
 * session can also simply expire while a tab sits open all day at a
 * counter — in both cases the first POST comes back 419. shgApi now asks
 * here for the live token and retries once, instead of telling the
 * passenger to refresh the page mid-booking.
 *
 * Safe to expose on GET: the token is bound to THIS session cookie and
 * the JSON cannot be read cross-origin.
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requireRateLimit('csrf_refresh', Security::clientIp(), 30, 300);
    header('Cache-Control: no-store');
    Response::success(['csrf' => Security::csrfToken()]);
} catch (Throwable $e) {
    Response::serverError($e);
}
