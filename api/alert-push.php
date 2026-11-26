<?php
/**
 * =====================================================================
 *  /api/alert-push.php — "Ring on this phone" for staff (26 Nov 2026, fx1)
 *
 *  GET                                  -> { enabled, publicKey }
 *  POST { action:'subscribe', ask:1, subscription:{endpoint, keys:{p256dh, auth}} }
 *       the person TAPPED "Ring on this phone": binds this handset's push
 *       endpoint to the signed-in staff member (push_subscriptions.admin_id)
 *       and switches the ring on for it (alert_push_optin).
 *  POST { action:'subscribe', subscription } (no ask - the panel's quiet sync)
 *       only a handset that ALREADY rings is moved to whoever is signed in now
 *       (a shared counter phone). A handset that merely allowed notifications
 *       for Office chat is left exactly as it is -> { id: 0, ring: false }.
 *  POST { action:'unsubscribe', endpoint }
 *       "Stop ringing on this phone": only the ring is switched off; the
 *       subscription itself (Office chat push) is kept -> { stopped }.
 *  POST { action:'status', endpoint }   -> { ring } does this handset ring for me?
 *
 *  Until now the only way to subscribe a staff phone was a card inside Office
 *  chat, behind the chat's own switch. The ring has its own door, its own
 *  switch (alert_push_on) and its own opt-in, so turning chat push off does not
 *  silence the ring - and allowing chat push never starts the ring.
 *
 *  Signed-in staff who can see the panel's bookings or payments; a view-only
 *  account is refused. CSRF on every POST. The customer door (api/push.php)
 *  is separate and never sets admin_id.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/webpush.php';
require_once INCLUDE_PATH . '/alertbus.php';

try {
    $admin = Auth::admin();
    if ($admin === null) {
        Response::unauthorized();
    }
    if ((string) ($admin['role'] ?? '') === 'viewer' || (!Auth::can('bookings.view') && !Auth::can('payments.view'))) {
        Response::forbidden();
    }
    $me    = (int) ($admin['id'] ?? 0);
    $ready = AlertBus::on() && Settings::getBool('alert_push_on', true) && WebPush::enabled();

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        Response::success(['enabled' => $ready, 'publicKey' => $ready ? WebPush::publicKey() : '']);
    }

    Security::requireCsrf();
    if (!Security::rateLimit('alert_push', 'admin:' . $me, 20, 60)) {
        Response::error('धेरै पटक थिचियो, एकछिन पर्खनुहोस् · Too many requests.', 429);
    }
    $action = Security::clean((string) Response::field('action', ''), 20);

    if ($action === 'unsubscribe') {
        $ep      = trim((string) Response::field('endpoint', ''));
        $stopped = $ep !== '' && AlertBus::pushOptOut($ep, $me);
        if ($stopped) {
            Logger::audit('alerts.push_off', 'admin', (string) $me);
        }
        Response::success(['stopped' => $stopped], 'यो फोनमा घण्टी बन्द भयो · This phone will not ring');
    }

    if ($action === 'status') {
        $ep = trim((string) Response::field('endpoint', ''));
        Response::success(['ring' => $ep !== '' && AlertBus::phoneRings($ep, $me)]);
    }

    if ($action === 'subscribe') {
        if (!$ready) {
            Response::error('यो सर्भरमा फोन घण्टी बन्द छ · Phone ring is switched off on this server.', 503);
        }
        $sub = WebPush::normaliseSubscription(Response::field('subscription'));
        if ($sub === null) {
            Response::invalid(['subscription' => 'ब्राउजरले मान्य push subscription पठाएन · The browser did not send a valid push subscription.']);
        }
        /* Only a tap on "Ring on this phone" (ask) switches a handset on. The panel's quiet sync
           (no ask) moves a handset that already rings to whoever is signed in now, and leaves
           every other handset alone - allowing Office chat push never starts the ring. */
        $ask = !empty(Response::field('ask', 0));
        if (!$ask && !AlertBus::phoneRings($sub['endpoint'])) {
            Response::success(['id' => 0, 'ring' => false]);
        }
        $had = AlertBus::phoneRings($sub['endpoint'], $me);
        if (!AlertBus::pushOptIn($sub['endpoint'], $me, $ask)) {       // before the bind: nothing is changed when it cannot ring
            Response::error('फोन घण्टी खोल्न सकिएन · Phone ring could not be switched on (run database/upgrade-2026-11-26-fx1-alertbus.sql).', 503);
        }
        $id = WebPush::subscribe($sub, [
            'admin_id' => $me,
            'lang'     => 'ne',
            'ua'       => Security::userAgent(160),
        ]);
        if (!$had) {                              // only a NEW ringing phone is worth an audit line
            Logger::audit('alerts.push_on', 'admin', (string) $me, null, ['subscription' => $id, 'asked' => $ask]);
        }
        Response::success(['id' => $id, 'ring' => true], 'यो फोनमा घण्टी बज्छ · This phone will ring');
    }

    Response::invalid(['action' => 'थाहा नभएको काम · Unknown action.']);
} catch (Throwable $e) {
    Response::serverError($e);
}
