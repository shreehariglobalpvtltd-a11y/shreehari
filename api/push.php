<?php
/**
 * =====================================================================
 *  /api/push.php — Web Push subscriptions for the installed app (13 Sep 2026)
 *
 *  GET  ?action=key                → { enabled, key }   the VAPID public key
 *  POST action=subscribe           → { id }             { subscription, pnr?, k?, lang? }
 *  POST action=unsubscribe         → { ok }             { endpoint }
 *  POST action=resubscribe         → { id }             { oldEndpoint, subscription }   (from sw.js, no CSRF)
 *  POST action=status              → { registered, phone }  { endpoint }
 *  POST action=test                → { sent }           sends a test push to the caller's own endpoint
 *
 *  WHO MAY SUBSCRIBE
 *  A subscription is tied to a PHONE (the passenger's mobile) so that a
 *  delay on any of that number's tickets reaches this device. The phone
 *  comes from the signed-in customer session (the sale signs the passenger
 *  in), or — for a ticket opened from a keyed WhatsApp link — from the
 *  booking whose download key was presented. Anonymous callers get a 401,
 *  so nobody can attach their phone to somebody else's ticket.
 *
 *  resubscribe is the one CSRF-free action: it is posted by the service
 *  worker (which has no page token) and only ever succeeds when the OLD
 *  endpoint already exists in the store — an unguessable 200+ character
 *  URL that only the browser holding it knows.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/webpush.php';

try {
    $action = Security::clean((string) Response::field('action', 'key'), 20);

    if ($action === 'key') {
        Response::$publicCacheSeconds = 300;
        Response::success([
            'enabled' => WebPush::enabled(),
            'key'     => WebPush::enabled() ? WebPush::publicKey() : '',
        ]);
    }

    Security::requirePost();
    Security::requireRateLimit('push', Security::clientIp(), 40, 300);

    if ($action === 'resubscribe') {
        $old = trim((string) Response::field('oldEndpoint', ''));
        $sub = WebPush::normaliseSubscription(Response::field('subscription'));
        if ($old === '' || $sub === null) {
            Response::invalid(['subscription' => 'A valid subscription is required.']);
        }
        $id = WebPush::resubscribe($old, $sub);
        if ($id === null) {
            Response::notFound('Unknown subscription.');
        }
        Response::success(['id' => $id]);
    }

    Security::requireCsrf();

    if (!WebPush::enabled()) {
        Response::error('Push notifications are not enabled on this server.', 503);
    }

    if ($action === 'unsubscribe') {
        $endpoint = trim((string) Response::field('endpoint', ''));
        if ($endpoint === '') {
            Response::invalid(['endpoint' => 'Endpoint is required.']);
        }
        Response::success(['ok' => WebPush::unsubscribe($endpoint)]);
    }

    if ($action === 'status') {
        $endpoint = trim((string) Response::field('endpoint', ''));
        $row = $endpoint !== ''
            ? Database::fetch('SELECT phone, is_active, booking_id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1($endpoint)])
            : null;
        Response::success([
            'registered' => $row !== null && (int) $row['is_active'] === 1,
            'phone'      => $row !== null ? maskPhone((string) $row['phone']) : '',
        ]);
    }

    /* ---- identity: who does this device belong to? ------------------- */
    $user  = Auth::user();
    $userId = $user !== null ? (int) ($user['id'] ?? 0) : 0;
    /* 8 Oct 2026: alerts go to every booking of the device's phone, so the
       phone only names this device after the WhatsApp code - a name + mobile
       sign-in is never proof (owner rule). Unverified: per-ticket only. */
    $phone = '';
    if ($user !== null) {
        if (!class_exists('CustomerProfile')) {
            require_once __DIR__ . '/../includes/customerprofile.php';
        }
        if ($userId > 0 && CustomerProfile::verifiedUserId() === $userId) {
            $phone = normalisePhone((string) ($user['phone'] ?? ''));
        }
    }

    $bookingId = null;
    $pnr = strtoupper(Security::clean((string) Response::field('pnr', ''), 40));
    $key = Security::clean((string) Response::field('k', ''), 64);
    if ($pnr !== '' && Security::isValidPnr($pnr)) {
        $booking = BookingService::findByPnr($pnr);
        if ($booking !== null) {
            $bPhone = normalisePhone((string) ($booking['contact_phone'] ?? ''));
            $owns   = Auth::customerMayOpen($booking)
                   || ($key !== '' && Ticket::checkDownloadToken($pnr, $key));
            if ($owns) {
                // This ticket only - a keyed link or this browser's own sale
                // does not hand over the alerts of the phone's other tickets.
                $bookingId = (int) $booking['id'];
            }
        }
    }

    if ($phone === '' && $bookingId === null) {
        Response::error('Sign in with your mobile number first · पहिले साइन इन गर्नुहोस्', 401);
    }

    if ($action === 'subscribe') {
        $sub = WebPush::normaliseSubscription(Response::field('subscription'));
        if ($sub === null) {
            Response::invalid(['subscription' => 'The browser did not send a valid push subscription.']);
        }
        $lang = Security::clean((string) Response::field('lang', 'ne'), 5);
        $id = WebPush::subscribe($sub, [
            'phone'      => $phone,
            'user_id'    => $userId > 0 ? $userId : null,
            'booking_id' => $bookingId,
            'lang'       => in_array($lang, ['en', 'hi', 'ne', 'gu'], true) ? $lang : 'ne',
            'ua'         => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        Logger::info('push subscribed', ['id' => $id, 'booking' => $bookingId, 'phone' => maskPhone($phone)]);
        Response::success(['id' => $id, 'phone' => maskPhone($phone)], 'Alerts on · सूचना खुल्यो');
    }

    if ($action === 'test') {
        $endpoint = trim((string) Response::field('endpoint', ''));
        $row = $endpoint !== ''
            ? Database::fetch('SELECT * FROM push_subscriptions WHERE endpoint_hash = :h AND is_active = 1', ['h' => sha1($endpoint)])
            : null;
        if ($row === null) {
            Response::notFound('This device is not subscribed yet.');
        }
        // Only the device's own phone (or office staff) may ring it.
        if (normalisePhone((string) $row['phone']) !== $phone && !Auth::can('dashboard.view')) {
            Response::forbidden('Not your device.');
        }
        $company = Settings::getString('company_name', APP_NAME);
        $r = WebPush::send($row, [
            'title' => '🔔 ' . $company . ' — test',
            'body'  => 'Push alerts are working on this phone · यो फोनमा सूचना चल्छ 🙏',
            'url'   => rtrim(APP_URL, '/') . '/#/my',
            'tag'   => 'shg-test',
            'event' => 'test',
        ], 600, 'normal');
        Response::success(['sent' => $r['ok'], 'http' => $r['http'], 'error' => $r['ok'] ? '' : $r['error']]);
    }

    Response::invalid(['action' => 'Unknown action.']);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
