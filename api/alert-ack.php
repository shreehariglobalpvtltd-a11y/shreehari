<?php
/**
 * =====================================================================
 *  /api/alert-ack.php — "Seen" (26 Nov 2026, fx1)
 *
 *  POST { id }            one alert
 *  POST { ids: [..] }     the alerts the card was showing (max 20)
 *  POST { all: 1 }        every open ring this person can see
 *
 *  Two doors:
 *    1. the open panel: signed-in staff + X-CSRF-Token
 *    2. the notification's Seen button (service worker, no page, no session):
 *       { id, admin, t [, all] } where t = AlertBus::ackToken(id, admin, all) -
 *       a signature made with APP_KEY that was sent inside that person's own
 *       push. It can only mark alerts seen; it opens nothing and signs nobody
 *       in. "all" is part of the signature and works only while that alert is
 *       still ringing. `ids` is ignored at this door: a token answers only the
 *       one alert it was signed for.
 *
 *  The office's first Seen wins and stops the ring for everybody; later ones
 *  are a quiet success. An agent's Seen (and a counter's on anything but a new
 *  ticket) is personal - quiet for them, the office still hears it. A viewer,
 *  or someone the alert is not for, is refused.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/alertbus.php';

try {
    Security::requirePost();

    $id    = (int) Response::field('id', 0);
    $all   = !empty(Response::field('all', 0));
    $token = (string) Response::field('t', '');
    $ids   = Response::field('ids', []);
    $ids   = is_array($ids) ? array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0))), 0, 20) : [];

    $admin = Auth::admin();
    $via   = 'panel';
    if ($admin !== null && Security::verifyCsrf()) {
        $who = ['id' => (int) $admin['id'], 'role' => (string) ($admin['role'] ?? '')];
    } elseif ($token !== '') {
        Security::requireRateLimit('alert_ack_t', Security::clientIp(), 120, 60);
        $aid = (int) Response::field('admin', 0);
        if (!AlertBus::checkAckToken($id, $aid, $token, $all)) {
            Response::forbidden('यो लिङ्क मान्य छैन · This link is not valid.');
        }
        /* The signature names ONE alert (`id`). A list sent along with it is not signed and is
           dropped - otherwise any old notification's token could mark every later alert seen. */
        $ids = [];
        /* "Seen all" from a merged notification only while that alert is still ringing - an old
           notification kept in the tray can never silence rings that come days later. */
        if ($all && !AlertBus::isOpen($id)) {
            $all = false;
        }
        $row = AlertBus::adminById($aid);
        if ($row === null) {
            Response::forbidden('यो खाता सक्रिय छैन · This account is not active.');
        }
        $who = ['id' => (int) $row['id'], 'role' => (string) $row['role']];
        $via = 'push';
    } elseif ($admin !== null) {
        Response::error('सुरक्षा टोकन सकियो, पेज रिफ्रेस गर्नुहोस् · Security token expired. Please refresh the page and try again.', 419);
    } else {
        Response::unauthorized();
    }

    if ($who['role'] === 'viewer') {
        Response::forbidden('हेर्ने मात्र खाताले "देखेँ" थिच्न मिल्दैन · A view-only account cannot mark alerts seen.');
    }
    if (!Security::rateLimit('alert_ack', 'admin:' . $who['id'], 60, 60)) {
        Response::error('धेरै पटक थिचियो, एकछिन पर्खनुहोस् · Too many requests.', 429);
    }

    if ($all) {
        /* fx4: "Seen all" covers alerts up to the one shown - the signed alert of a push, or the newest
           ring the panel had on screen (upto). Never later alerts, never a driver SOS by itself. */
        $upTo = $via === 'push' ? $id : max(0, (int) Response::field('upto', 0));
        if ($upTo <= 0) {
            Response::invalid(['upto' => 'कुन सूचनासम्म? · Seen up to which alert?']);
        }
        Response::success(['acked' => AlertBus::ackAll($who, $via, null, $upTo)]);
    }
    if ($ids === [] && $id > 0) {
        $ids = [$id];
    }
    if ($ids === []) {
        Response::invalid(['id' => 'कुन सूचना? · Which alert?']);
    }
    $n = 0;
    $refused = 0;
    foreach ($ids as $one) {
        $r = AlertBus::ack($one, $who, $via);
        if ($r['ok']) {
            $n += $r['first'] ? 1 : 0;
        } elseif (($r['why'] ?? '') === 'not yours') {
            $refused++;
        }
    }
    if ($refused > 0 && $refused === count($ids)) {
        Response::forbidden('यो सूचना तपाईंको खाताको लागि होइन · That alert is not for your account.');
    }
    Response::success(['acked' => $n]);
} catch (Throwable $e) {
    Response::serverError($e);
}
