<?php
/**
 * =====================================================================
 *  admin/api/push.php — staff phone push for Office chat (9 Oct 2026)
 *
 *  POST {action:'subscribe', subscription:{endpoint, keys:{p256dh, auth}}, lang}
 *       binds this handset's push endpoint to the signed-in staff member.
 *       One endpoint = one row (push_subscriptions.endpoint_hash), so on a
 *       shared counter phone the row simply re-binds to whoever signed in
 *       last; the customer side of the row (phone / booking) is untouched.
 *  POST {action:'unsubscribe', endpoint}   sign-out on this handset: stop
 *       pushing this person's chat here (admin_id NULL, row kept).
 *  GET  ?action=status                     {ok, enabled, publicKey}
 *
 *  Gates: signed-in staff (bookings.view) who may use Office chat; CSRF on
 *  every POST. The customer endpoint (api/push.php) is a different door — it
 *  needs a customer identity and never sets admin_id.
 * =====================================================================
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/staffmsg.php';
require_once INCLUDE_PATH . '/webpush.php';
$admin = admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pOut(array $p, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($p, JSON_UNESCAPED_SLASHES);
    exit;
}

$me = (int) ($admin['id'] ?? 0);
if (!StaffMsg::canUse()) {
    pOut(['ok' => false, 'error' => 'Office chat is not available for your role.'], 403);
}
$ready = StaffMsg::pushReady() && WebPush::enabled() && Settings::getBool('staffmsg_push_on', true);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    pOut(['ok' => true, 'enabled' => $ready, 'publicKey' => $ready ? WebPush::publicKey() : '']);
}
if (!Security::verifyCsrf()) {
    pOut(['ok' => false, 'error' => 'Session expired — reload the page.'], 419);
}
if (!Security::rateLimit('staff_push', 'admin:' . $me, 20, 60)) {
    pOut(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$in     = Response::input();
$action = (string) ($in['action'] ?? '');

if ($action === 'unsubscribe') {
    $ep = trim((string) ($in['endpoint'] ?? ''));
    $ok = $ep !== '' && StaffMsg::pushReady() && WebPush::unbindAdmin($ep, $me);
    pOut(['ok' => true, 'unbound' => $ok]);
}

if ($action === 'subscribe') {
    if (!$ready) {
        pOut(['ok' => false, 'error' => 'Push is not available on this server.'], 503);
    }
    $sub = WebPush::normaliseSubscription($in['subscription'] ?? null);
    if ($sub === null) {
        pOut(['ok' => false, 'error' => 'That subscription is not valid.'], 422);
    }
    $lang = (string) ($in['lang'] ?? 'ne');
    $id = WebPush::subscribe($sub, [
        'admin_id' => $me,
        'lang'     => in_array($lang, ['ne', 'en', 'hi'], true) ? $lang : 'ne',
        'ua'       => Security::userAgent(160),
    ]);
    Logger::audit('staffmsg.push_on', 'admin', (string) $me, null, ['subscription' => $id]);
    pOut(['ok' => true, 'id' => $id]);
}

pOut(['ok' => false, 'error' => 'Unknown action.'], 400);
