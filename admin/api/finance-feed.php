<?php
/**
 * admin/api/finance-feed.php — the read-only website feed for SHG Finance
 * Master (served at /admin/finance.php).
 *
 *   GET /admin/api/finance-feed.php?part=meta
 *   GET …?part=agents
 *   GET …?part=schedules&from=Y-m-d&to=Y-m-d&afterId=&limit=
 *   GET …?part=bookings&since=Y-m-d H:i:s&afterId=&limit=
 *   GET …?part=booking_ids&afterId=&limit=
 *   GET …?part=ledger|paper|deposits|loans&afterId=&limit=
 *   GET …?part=shifts&since=&afterId=&limit=
 *   GET …?part=daybook&from=Y-m-d&to=Y-m-d
 *
 * Same door as the app itself: a signed-in admin holding reports.view
 * (superadmin, manager, accountant) — and never a counter agent, even one
 * granted reports.view by hand, because the feed is company-wide. It only
 * reads: every query lives in includes/financefeed.php (FinanceFeed), which
 * sends no personal data — see the header there for what is left out.
 *
 * Answers {ok:true, part, feedVersion, generatedAt, tz, dbTimeZone, rows,
 * next, count} or {ok:false, error, code} with the real HTTP status
 * (401 signed out, 403 role, 405 not GET, 422 bad parameter, 429 too fast).
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('reports.view');
require_once INCLUDE_PATH . '/financefeed.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Company-wide money is not for a counter agent, whatever extra grants they hold.
if (Auth::isCounterAgent()) {
    Response::forbidden('The finance feed is not available to counter agents.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    Response::error('Use GET — the finance feed is read-only.', 405);
}

if (!Security::rateLimit('finance_feed', 'admin:' . (int) ($admin['id'] ?? 0), 240, 60)) {
    Response::error('Too many finance feed requests. Please wait a minute and try again.', 429);
}

try {
    $params = FinanceFeed::params($_GET);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

if ($params['part'] === 'meta') {
    // One line per sync (meta is always fetched first), not one per page.
    Logger::audit('finance.feed_sync', 'finance', '', null, null, 'Finance Master pulled the website feed');
}

Response::json(FinanceFeed::run($params, $admin));
