<?php
/**
 * /api/nav-reports.php - road reports for SHARI NAV (Phase 2, 22 Oct 2026).
 *
 * Waze / Google / Apple incident reports, from our own riders:
 *   GET  ?lat=..&lng=..                    active reports within 30 km
 *   POST {action:'add', kind, lat, lng, hd?, note?}   X-SHG-Dev: <32 hex>
 *   POST {action:'vote', id, v:'up'|'down'}            X-SHG-Dev: <32 hex>
 *
 * Kinds and how long they live: NavLive::KINDS. A second report of the same
 * kind within 150 m in the last 20 min counts as a 👍 on the first one. 👍
 * keeps a report alive longer, two 👎 remove it. 10 reports an hour per
 * phone. Nothing personal is stored: reporter_hash is a keyed hash of a
 * random id the phone made up. Every change is pushed on channel "rep".
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/navlive.php';

header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Security::requireRateLimit('nav_rep_get', Security::clientIp(), 60, 60);
        $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || !NavLive::inArea((float) $lat, (float) $lng)) {
            Response::invalid(['lat' => 'Outside the service area.']);
        }
        $ttl = [];
        foreach (NavLive::KINDS as $k => $v) {
            $ttl[$k] = $v[0];
        }
        Response::success(['reports' => NavLive::reportsNear((float) $lat, (float) $lng), 'ttl' => $ttl, 'now' => time() * 1000]);
    }

    Security::requirePost();
    $dev = NavLive::device();
    $action = (string) Response::field('action', '');

    if ($action === 'add') {
        Security::requireRateLimit('nav_rep_add', $dev, 10, 3600);
        Security::requireRateLimit('nav_rep_add_ip', Security::clientIp(), 40, 3600);
        $kind = (string) Response::field('kind', '');
        $lat = Response::field('lat', null);
        $lng = Response::field('lng', null);
        if (!isset(NavLive::KINDS[$kind]) || !is_numeric($lat) || !is_numeric($lng) || !NavLive::inArea((float) $lat, (float) $lng)) {
            Response::invalid(['kind' => 'Pick what you see on the road.']);
        }
        $lat = round((float) $lat, 6);
        $lng = round((float) $lng, 6);
        $hd = Response::field('hd', null);
        $hd = is_numeric($hd) && (float) $hd >= 0 && (float) $hd <= 360 ? (int) round((float) $hd) : null;
        $note = mb_substr(trim(strip_tags((string) Response::field('note', ''))), 0, 140);
        $ttl = NavLive::KINDS[$kind][0];
        $now = time();

        /* the same thing, a moment ago, a few steps away: one report, one more 👍 */
        $near = Database::fetchAll(
            "SELECT * FROM nav_reports WHERE kind = :k AND status = 'active' AND expires_at > :n AND created_at > :since
                AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2 LIMIT 20",
            ['k' => $kind, 'n' => date('Y-m-d H:i:s', $now), 'since' => date('Y-m-d H:i:s', $now - 1200),
             'la1' => $lat - 0.002, 'la2' => $lat + 0.002, 'lo1' => $lng - 0.002, 'lo2' => $lng + 0.002]
        );
        foreach ($near as $r) {
            if (EtaAlerts::haversineKm($lat, $lng, (float) $r['lat'], (float) $r['lng']) <= 0.15) {
                if ((string) $r['reporter_hash'] !== $dev) {
                    Database::run(
                        'UPDATE nav_reports SET up = up + 1, updated_at = :u, expires_at = GREATEST(expires_at, :e) WHERE id = :id',
                        ['u' => date('Y-m-d H:i:s', $now), 'e' => date('Y-m-d H:i:s', $now + $ttl * 60), 'id' => (int) $r['id']]
                    );
                }
                $r = Database::fetch('SELECT * FROM nav_reports WHERE id = :id', ['id' => (int) $r['id']]);
                $out = NavLive::reportOut($r);
                NavLive::publish('rep', ['op' => 'upd', 'r' => $out]);
                Response::success(['report' => $out, 'merged' => true]);
            }
        }

        $id = Database::insert('nav_reports', [
            'kind' => $kind, 'lat' => $lat, 'lng' => $lng, 'geohash6' => NavLive::geohash($lat, $lng), 'heading' => $hd,
            'note' => $note, 'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
            'expires_at' => date('Y-m-d H:i:s', $now + $ttl * 60), 'up' => 0, 'down' => 0, 'reporter_hash' => $dev, 'status' => 'active',
        ]);
        $out = NavLive::reportOut(Database::fetch('SELECT * FROM nav_reports WHERE id = :id', ['id' => $id]));
        NavLive::publish('rep', ['op' => 'add', 'r' => $out]);
        Response::success(['report' => $out, 'merged' => false]);
    }

    if ($action === 'vote') {
        $id = (int) Response::field('id', 0);
        $v = (string) Response::field('v', '');
        $r = $id > 0 ? Database::fetch("SELECT * FROM nav_reports WHERE id = :id AND status = 'active'", ['id' => $id]) : null;
        if ($r === null || !in_array($v, ['up', 'down'], true)) {
            Response::notFound('This report is already gone.');
        }
        if ((string) $r['reporter_hash'] === $dev) {
            Response::error('You reported this one.', 409);
        }
        if (!Security::rateLimit('nav_vote_' . $id, $dev, 1, 86400)) {
            Response::error('You already answered for this one.', 409);
        }
        Security::requireRateLimit('nav_vote_ip', Security::clientIp(), 60, 3600);
        $now = time();
        if ($v === 'up') {
            $ttl = NavLive::KINDS[(string) $r['kind']][0] ?? 60;
            Database::run(
                'UPDATE nav_reports SET up = up + 1, updated_at = :u, expires_at = GREATEST(expires_at, :e) WHERE id = :id',
                ['u' => date('Y-m-d H:i:s', $now), 'e' => date('Y-m-d H:i:s', $now + (int) ceil($ttl / 2) * 60), 'id' => $id]
            );
        } else {
            Database::run(
                /* status first: MariaDB assigns left to right, so `down` here is still the old count */
                "UPDATE nav_reports SET status = IF(down + 1 >= 2, 'removed', status), down = down + 1, updated_at = :u WHERE id = :id",
                ['u' => date('Y-m-d H:i:s', $now), 'id' => $id]
            );
        }
        $out = NavLive::reportOut(Database::fetch('SELECT * FROM nav_reports WHERE id = :id', ['id' => $id]));
        NavLive::publish('rep', ['op' => $out['status'] === 'active' ? 'upd' : 'del', 'r' => $out]);
        Response::success(['report' => $out]);
    }

    Response::invalid(['action' => 'Unknown action.']);
} catch (Throwable $e) {
    Response::serverError($e);
}
