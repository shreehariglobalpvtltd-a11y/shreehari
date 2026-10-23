<?php
/**
 * =====================================================================
 *  includes/navlive.php - SHARI NAV live (Phase 2, 22 Oct 2026).
 *
 *  Owner: "share trip progress like Google, live S Hari buses on the map,
 *  road reports like Waze - free, on our own VPS, no Firebase".
 *
 *    nav_shares   a rider's live trip (follow link = token)
 *    nav_reports  road reports (accident, landslide, border queue ...)
 *    nav_sync     a signed-in customer's saved places (later)
 *
 *  Push: the endpoints write the table, then publish() drops one UDP
 *  datagram on 127.0.0.1:8091. The hub (live/shg-live.php, systemd
 *  shg-live) fans it out to the phones listening on wss://…/live/.
 *  The datagram is signed with the key in /var/lib/shg-live/key, so only
 *  this box can publish. No hub, no key, no WebSocket - the pages fall
 *  back to /api/nav-live.php (long-poll), which reads the same tables.
 *
 *  Channels:  share:<token>   bus   rep
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/etaalerts.php';

final class NavLive
{
    public const HUB_UDP   = 'udp://127.0.0.1:8091';
    public const KEY_FILE  = '/var/lib/shg-live/key';
    public const SHARE_H   = 4;          /* a share stops by itself after 4 h */
    public const NEAR_KM   = 30.0;       /* reports shown around the rider */
    public const BUS_CACHE = 12;         /* seconds one bus snapshot is reused */

    /** kind => [minutes it lives, emoji] (owner's list, 21 Oct 2026) */
    public const KINDS = [
        'accident'  => [60, '💥'],
        'police'    => [45, '👮'],
        'jam'       => [30, '🚗'],
        'roadwork'  => [7 * 1440, '🚧'],
        'closed'    => [12 * 60, '⛔'],
        'flood'     => [6 * 60, '🌊'],
        'landslide' => [24 * 60, '⛰️'],
        'pothole'   => [30 * 1440, '🕳️'],
        'animal'    => [20, '🐄'],
        'fog'       => [3 * 60, '🌫️'],
        'border'    => [2 * 60, '🛂'],
    ];

    /* ---------------------------------------------------------------
     *  Push
     * ------------------------------------------------------------- */

    private static ?string $key = null;

    public static function key(): string
    {
        if (self::$key === null) {
            $k = @file_get_contents(self::KEY_FILE);
            self::$key = is_string($k) ? trim($k) : '';
        }
        return self::$key;
    }

    /** Fire and forget: a hub that is down never slows or breaks the caller. */
    public static function publish(string $channel, array $data): void
    {
        $key = self::key();
        if ($key === '') {
            return;
        }
        $json = json_encode(['ch' => $channel, 'd' => $data, 't' => (int) round(microtime(true) * 1000)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 60000) {
            return;
        }
        $s = @stream_socket_client(self::HUB_UDP, $errno, $errstr, 0.2);
        if ($s === false) {
            return;
        }
        @fwrite($s, hash_hmac('sha256', $json, $key) . "\n" . $json);
        @fclose($s);
    }

    /* ---------------------------------------------------------------
     *  Small helpers
     * ------------------------------------------------------------- */

    public static function ms(?string $sqlTime): ?int
    {
        return $sqlTime === null || $sqlTime === '' ? null : (int) strtotime($sqlTime) * 1000;
    }

    public static function geohash(float $lat, float $lng, int $len = 6): string
    {
        $b32 = '0123456789bcdefghjkmnpqrstuvwxyz';
        $la = [-90.0, 90.0]; $lo = [-180.0, 180.0];
        $out = ''; $bit = 0; $ch = 0; $even = true;
        while (strlen($out) < $len) {
            if ($even) { $mid = ($lo[0] + $lo[1]) / 2; if ($lng >= $mid) { $ch = ($ch << 1) | 1; $lo[0] = $mid; } else { $ch <<= 1; $lo[1] = $mid; } }
            else       { $mid = ($la[0] + $la[1]) / 2; if ($lat >= $mid) { $ch = ($ch << 1) | 1; $la[0] = $mid; } else { $ch <<= 1; $la[1] = $mid; } }
            $even = !$even;
            if (++$bit === 5) { $out .= $b32[$ch]; $bit = 0; $ch = 0; }
        }
        return $out;
    }

    /**
     * Who is reporting / voting, without knowing who it is: the phone sends a
     * random id it made (X-SHG-Dev, 32 hex), we keep only a keyed hash.
     */
    public static function device(): string
    {
        $dev = strtolower((string) ($_SERVER['HTTP_X_SHG_DEV'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $dev) !== 1) {
            $dev = 'ip:' . Security::clientIp();
        }
        return hash_hmac('sha256', $dev, defined('APP_KEY') ? (string) APP_KEY : 'shg-nav');
    }

    public static function inArea(float $lat, float $lng): bool
    {
        return $lat > 5 && $lat < 40 && $lng > 60 && $lng < 100;
    }

    /* ---------------------------------------------------------------
     *  Shares
     * ------------------------------------------------------------- */

    public static function share(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        return Database::fetch('SELECT * FROM nav_shares WHERE token = :t LIMIT 1', ['t' => $token]);
    }

    /** What a follower may see. Never the secret. */
    public static function shareOut(array $r, bool $withRoute = false): array
    {
        $status = (string) $r['status'];
        if ($status === 'live' && strtotime((string) $r['expires_at']) < time()) {
            $status = 'ended';
        }
        $out = [
            'dest'    => ['name' => (string) $r['dest_name'], 'lat' => (float) $r['dest_lat'], 'lng' => (float) $r['dest_lng']],
            'mode'    => (string) $r['mode'],
            'pos'     => $r['last_lat'] === null ? null : [
                'lat' => (float) $r['last_lat'], 'lng' => (float) $r['last_lng'],
                'kmh' => $r['last_speed'] === null ? null : (float) $r['last_speed'],
                'hd'  => $r['last_heading'] === null ? null : (int) $r['last_heading'],
            ],
            'left'    => $r['left_m'] === null ? null : (int) $r['left_m'],
            'eta'     => self::ms($r['eta_at']),
            'status'  => $status,
            'updated' => self::ms($r['updated_at']),
            'expires' => self::ms($r['expires_at']),
        ];
        if ($withRoute) {
            $out['route'] = (string) ($r['route'] ?? '');
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     *  Reports
     * ------------------------------------------------------------- */

    public static function reportOut(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'kind' => (string) $r['kind'],
            'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
            'hd' => $r['heading'] === null ? null : (int) $r['heading'],
            'note' => (string) $r['note'], 'at' => self::ms($r['created_at']), 'exp' => self::ms($r['expires_at']),
            'up' => (int) $r['up'], 'down' => (int) $r['down'],
            'status' => strtotime((string) $r['expires_at']) < time() ? 'expired' : (string) $r['status'],
        ];
    }

    /** Active reports within NEAR_KM of a point (bounding box, then exact). */
    public static function reportsNear(float $lat, float $lng, float $km = self::NEAR_KM): array
    {
        $dLat = $km / 111.0;
        $dLng = $km / max(1.0, 111.0 * cos(deg2rad($lat)));
        $rows = Database::fetchAll(
            "SELECT * FROM nav_reports
              WHERE status = 'active' AND expires_at > :now
                AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2
              ORDER BY created_at DESC LIMIT 200",
            ['now' => date('Y-m-d H:i:s'), 'la1' => $lat - $dLat, 'la2' => $lat + $dLat, 'lo1' => $lng - $dLng, 'lo2' => $lng + $dLng]
        );
        $out = [];
        foreach ($rows as $r) {
            if (EtaAlerts::haversineKm($lat, $lng, (float) $r['lat'], (float) $r['lng']) <= $km) {
                $out[] = self::reportOut($r);
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     *  S Hari coaches on the road now
     * ------------------------------------------------------------- */

    /**
     * Every coach with a usable position: where it is, where it is going,
     * its next boarding stop and minutes to it. The same honesty rules as the
     * track page (EtaAlerts::fixForTrip): no fresh, accurate fix = not shown.
     */
    public static function buses(?int $now = null): array
    {
        $now = $now ?? time();
        $file = sys_get_temp_dir() . '/shg-navlive-bus.json';
        if (is_file($file) && $now - (int) filemtime($file) < self::BUS_CACHE) {
            $c = json_decode((string) @file_get_contents($file), true);
            if (is_array($c)) {
                return $c;
            }
        }
        $out = [];
        $routeEnds = [];
        $cands = EtaAlerts::candidates($now);
        /* 23 Oct 2026: a coach the driver app is running with a fresh fix stays on the public map for its whole
           30-36 h run - candidates() alone stops at departure + 16 h, so long-haul coaches vanished mid-trip. */
        try {
            require_once __DIR__ . '/livetrips.php';
            $have = array_fill_keys(array_map(static fn (array $c): int => (int) $c['id'], $cands), true);
            foreach (LiveTrips::runningWithFix($now) as $lt) {
                if (!isset($have[(int) $lt['id']])) {
                    unset($lt['fix']);
                    $cands[] = $lt;
                    $have[(int) $lt['id']] = true;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('NavLive: running trips not merged: ' . $e->getMessage(), [], 'nav');
        }
        /* the coach's number plate (22 Oct 2026, owner: "gaadi number wise") */
        $plates = [];
        if ($cands !== []) {
            try {
                $ids = array_map(static fn (array $c): int => (int) $c['id'], $cands);
                foreach (Database::fetchAll(
                    'SELECT s.id, b.bus_number, b.bus_name FROM schedules s JOIN buses b ON b.id = s.bus_id WHERE s.id IN (' . implode(',', $ids) . ')'
                ) as $r) {
                    $plates[(int) $r['id']] = [(string) $r['bus_number'], (string) $r['bus_name']];
                }
            } catch (Throwable $e) {
                $plates = [];
            }
        }
        foreach ($cands as $c) {
            [$fix, , $age] = EtaAlerts::fixForTrip((int) $c['id'], (int) $c['route_id'], $now);
            if ($fix === null) {
                continue;
            }
            $etas = EtaAlerts::stopEtas((int) $c['route_id'], $fix, $now);
            $next = $etas !== [] ? reset($etas) : null;
            /* how far along from -> to (0-100), from the route's own first and
               last surveyed stop: the home card's moving coach. Null = unknown. */
            $pct = null;
            $ends = $routeEnds[(int) $c['route_id']] ??= self::routeEnds((int) $c['route_id']);
            if ($ends !== null) {
                $a = EtaAlerts::haversineKm($ends[0], $ends[1], (float) $fix['lat'], (float) $fix['lng']);
                $b = EtaAlerts::haversineKm((float) $fix['lat'], (float) $fix['lng'], $ends[2], $ends[3]);
                $pct = $a + $b > 1 ? (int) round($a / ($a + $b) * 100) : null;
            }
            $out[] = [
                'id'    => (int) $c['id'],
                'from'  => (string) $c['from_city'],
                'to'    => (string) $c['to_city'],
                'dep'   => substr((string) $c['dep_time'], 0, 5),
                'bus'   => $plates[(int) $c['id']][0] ?? '',
                'busName' => $plates[(int) $c['id']][1] ?? '',
                'lat'   => round((float) $fix['lat'], 5),
                'lng'   => round((float) $fix['lng'], 5),
                'kmh'   => $fix['kmh'] === null ? null : (int) round((float) $fix['kmh']),
                'brg'   => isset($fix['brg']) && $fix['brg'] !== null ? (int) $fix['brg'] : null,
                'pct'   => $pct,
                'age'   => $age === null ? null : (int) $age,
                'next'  => $next,
            ];
        }
        $snap = ['t' => $now * 1000, 'buses' => $out];
        @file_put_contents($file . '.tmp', json_encode($snap, JSON_UNESCAPED_UNICODE));
        @rename($file . '.tmp', $file);
        return $snap;
    }

    /**
     * [lat, lng] of a route's first and last surveyed boarding stop, or null.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private static function routeEnds(int $routeId): ?array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT latitude, longitude FROM route_stops
                  WHERE route_id = :r AND is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
                  ORDER BY sort_order, id',
                ['r' => $routeId]
            );
        } catch (Throwable $e) {
            return null;
        }
        if (count($rows) < 2) {
            return null;
        }
        $f = $rows[0];
        $l = $rows[count($rows) - 1];
        return [(float) $f['latitude'], (float) $f['longitude'], (float) $l['latitude'], (float) $l['longitude']];
    }

    /* ---------------------------------------------------------------
     *  Device sync (api/nav-sync.php)
     * ------------------------------------------------------------- */

    public const SYNC_MAX_ITEMS = 400;
    public const SYNC_LIST_KEEP = 100;      /* trip:* and visit:* are append-only lists */
    public const SYNC_TOMB_DAYS = 30;

    /**
     * Merge what a device sent into the stored items: per key the newer t
     * wins; tombstones go after 30 days; a clear:<list> drops that list's
     * older entries; each list keeps its newest 100. Pure, so it is tested.
     *
     * @param array<string, array<string,mixed>> $items stored
     * @param array<string, mixed>               $in    sent by the device
     * @return array<string, array<string,mixed>>
     */
    public static function syncMerge(array $items, array $in, int $nowMs): array
    {
        foreach ($in as $k => $it) {
            $k = (string) $k;
            if (preg_match('/^[a-z]{2,8}(:[A-Za-z0-9.,:_-]{1,80})?$/', $k) !== 1 || !is_array($it) || !is_numeric($it['t'] ?? null)) {
                continue;
            }
            $t = (float) $it['t'];
            if ($t < $nowMs - 5 * 365 * 86400000.0 || $t > $nowMs + 86400000.0) {
                continue;                                    /* a phone with a wild clock */
            }
            if (!isset($items[$k]) || $t > (float) ($items[$k]['t'] ?? 0)) {
                $items[$k] = !empty($it['del']) ? ['t' => $t, 'del' => true] : ['t' => $t, 'v' => $it['v'] ?? null];
            }
        }
        $old = $nowMs - self::SYNC_TOMB_DAYS * 86400000.0;
        $lists = [];
        foreach ($items as $k => $it) {
            $pre = strstr($k, ':', true);
            if (!empty($it['del']) && (float) $it['t'] < $old) {
                unset($items[$k]);
            } elseif ($pre === 'trip' || $pre === 'visit') {
                $clear = $items['clear:' . $pre]['t'] ?? null;
                if ($clear !== null && (float) $it['t'] <= (float) $clear) {
                    unset($items[$k]);
                } else {
                    $lists[$pre][$k] = (float) $it['t'];
                }
            }
        }
        foreach ($lists as $keys) {
            arsort($keys);
            foreach (array_slice(array_keys($keys), self::SYNC_LIST_KEEP) as $k) {
                unset($items[$k]);
            }
        }
        return $items;
    }

    /* ---------------------------------------------------------------
     *  Housekeeping (cron/nav-live.php, hourly)
     * ------------------------------------------------------------- */

    public static function purge(): array
    {
        $cut = date('Y-m-d H:i:s', time() - 86400);
        return [
            'shares'  => Database::run('DELETE FROM nav_shares WHERE updated_at < :c OR expires_at < :c2', ['c' => $cut, 'c2' => $cut]),
            'reports' => Database::run('DELETE FROM nav_reports WHERE expires_at < :c', ['c' => $cut]),
        ];
    }
}
