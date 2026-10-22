<?php
/**
 * /api/nav-share.php - live trip share for SHARI NAV (Phase 2, 22 Oct 2026).
 *
 * Google "share trip progress" / Apple "share ETA", on our own server.
 *
 *   POST {action:'start', dest:{name,lat,lng}, mode, route, left, eta}
 *        -> {token, secret, url, expires}   (url = navigate.html?follow=<token>)
 *   POST {action:'pos', token, secret, lat, lng, kmh, hd, left, eta, arrived, route?}
 *        every ~3 s while navigating; publishes to channel share:<token>
 *   POST {action:'stop', token, secret}
 *   GET  ?token=<32 hex>  -> what the follow page shows (never the secret)
 *
 * No login and no CSRF: nothing here rides on a cookie. Only the phone that
 * started a share holds its secret (128-bit, only its sha256 is stored),
 * and the follow token is 128-bit too. A share stops by itself after 4 h.
 * route = Google encoded polyline (precision 5) of the remaining route.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/navlive.php';

header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Security::requireRateLimit('nav_share_get', Security::clientIp(), 120, 60);
        $row = NavLive::share((string) ($_GET['token'] ?? ''));
        if ($row === null) {
            Response::notFound('This trip is no longer shared.');
        }
        Response::success(NavLive::shareOut($row, true));
    }

    Security::requirePost();
    $action = (string) Response::field('action', '');
    $num = static function (string $k, float $min, float $max): ?float {
        $v = Response::field($k, null);
        return is_numeric($v) && (float) $v >= $min && (float) $v <= $max ? (float) $v : null;
    };
    $route = static function (): ?string {
        $r = Response::field('route', null);
        return is_string($r) && $r !== '' && strlen($r) <= 120000 && preg_match('/^[\x21-\x7e]+$/', $r) === 1 ? $r : null;
    };
    $etaAt = static function () use ($num): ?string {
        $e = $num('eta', 1.0e12, 4.0e12);                      /* ms since 1970 */
        return $e === null ? null : date('Y-m-d H:i:s', (int) round($e / 1000));
    };

    if ($action === 'start') {
        Security::requireRateLimit('nav_share_new', Security::clientIp(), 20, 3600);
        $dest = Response::field('dest', []);
        $lat = is_array($dest) && is_numeric($dest['lat'] ?? null) ? (float) $dest['lat'] : null;
        $lng = is_array($dest) && is_numeric($dest['lng'] ?? null) ? (float) $dest['lng'] : null;
        if ($lat === null || $lng === null || !NavLive::inArea($lat, $lng)) {
            Response::invalid(['dest' => 'Pick a destination in India or Nepal.']);
        }
        $mode = (string) Response::field('mode', 'car');
        $mode = in_array($mode, ['car', 'bus', 'bike', 'walk', 'scooter'], true) ? $mode : 'car';
        $token = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(16));
        $now = time();
        $left = $num('left', 0, 5.0e6);
        Database::insert('nav_shares', [
            'token' => $token, 'secret_hash' => hash('sha256', $secret),
            'dest_name' => mb_substr(Security::clean((string) ($dest['name'] ?? ''), 200), 0, 120),
            'dest_lat' => round($lat, 7), 'dest_lng' => round($lng, 7), 'mode' => $mode, 'route' => $route(),
            'left_m' => $left === null ? null : (int) $left, 'eta_at' => $etaAt(), 'status' => 'live',
            'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + NavLive::SHARE_H * 3600),
        ]);
        Response::success([
            'token' => $token, 'secret' => $secret,
            'url' => 'https://www.shreehariglobal.in/navigate.html?follow=' . $token,
            'expires' => ($now + NavLive::SHARE_H * 3600) * 1000,
        ]);
    }

    /* pos / stop: the phone that started it, proved by the secret */
    $token = (string) Response::field('token', '');
    $row = NavLive::share($token);
    $secret = (string) Response::field('secret', '');
    if ($row === null || preg_match('/^[a-f0-9]{32}$/', $secret) !== 1 || !hash_equals((string) $row['secret_hash'], hash('sha256', $secret))) {
        Response::notFound('This trip is no longer shared.');
    }
    Security::requireRateLimit('nav_share_pos', $token, 90, 60);
    if ((string) $row['status'] !== 'live' || strtotime((string) $row['expires_at']) < time()) {
        Response::success(['status' => 'ended']);
    }

    if ($action === 'stop') {
        Database::update('nav_shares', ['status' => 'stopped', 'updated_at' => date('Y-m-d H:i:s')], 'token = :t', ['t' => $token]);
    } elseif ($action === 'pos') {
        $lat = $num('lat', 5, 40);
        $lng = $num('lng', 60, 100);
        if ($lat === null || $lng === null) {
            Response::invalid(['lat' => 'No position.']);
        }
        $kmh = $num('kmh', 0, 300);
        $hd = $num('hd', 0, 360);
        $left = $num('left', 0, 5.0e6);
        $set = [
            'last_lat' => round($lat, 6), 'last_lng' => round($lng, 6),
            'last_speed' => $kmh === null ? null : round($kmh, 1), 'last_heading' => $hd === null ? null : (int) round($hd),
            'left_m' => $left === null ? null : (int) $left, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (($e = $etaAt()) !== null) {
            $set['eta_at'] = $e;
        }
        if (($r = $route()) !== null) {
            $set['route'] = $r;
        }
        if ((bool) Response::field('arrived', false)) {
            $set['status'] = 'arrived';
        }
        Database::update('nav_shares', $set, 'token = :t', ['t' => $token]);
    } else {
        Response::invalid(['action' => 'Unknown action.']);
    }

    $row = NavLive::share($token);
    $out = NavLive::shareOut($row, isset($set['route']));
    NavLive::publish('share:' . $token, $out);
    Response::success(['status' => $out['status']]);
} catch (Throwable $e) {
    Response::serverError($e);
}
