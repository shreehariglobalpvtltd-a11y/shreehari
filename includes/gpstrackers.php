<?php
/**
 * =====================================================================
 *  includes/gpstrackers.php - track a bus by its NUMBER (22 Oct 2026).
 *  (database/upgrade-2026-10-22-gps-trackers.sql, api/gps.php,
 *   admin/gps-trackers.php, tests/gps-trackers-test.php)
 *
 *  Owner: "mobile ya GPS device trace garera gaadi number wise assign
 *  garna milos". The driver app (driver/) needs a driver to sign in and
 *  press START TRIP. A tracker needs nobody: it belongs to the BUS
 *  (buses.bus_number) and keeps sending.
 *
 *    phone     the free Traccar Client app, device identifier = a code made
 *              here (SHG + 13 characters, no 0/O/1/I). The code itself is
 *              the secret - long and random, so no key is needed.
 *    hardware  a GPS box (IMEI) on a Traccar server whose forward URL sends
 *              every fix here. An IMEI is printed on the box and guessable,
 *              so such a fix counts only with &key=<forward key>.
 *
 *  Protocol: OsmAnd (what Traccar Client 6-8 and a Traccar server forward
 *  URL speak) - id, lat, lon, timestamp, speed in KNOTS, bearing, accuracy,
 *  batt - plus the JSON body of Traccar Client 9 and of a Traccar server
 *  "forward.type json".
 *
 *  Every fix: the tracker row remembers it (last seen, place, battery);
 *  then the departure this bus runs now (schedules.bus_id, leaving at most
 *  LEAD_SEC from now, the most recent one) gets it through LiveTrips - the
 *  same live_trips row the driver app writes, so the track page, the "bus
 *  is near" WhatsApp and the SHARI NAV map need no change. No departure =
 *  the bus is parked: only the tracker row moves.
 *
 *  Switch: gps_trackers_on (kill switch, default on).
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/livetrips.php';

final class GpsTrackers
{
    public const UID_PREFIX   = 'SHG';
    public const UID_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I: read out over the phone
    public const UID_RANDOM   = 13;
    /* 'gps_forward_key' would be printed in plain text on Admin -> Settings
       for every manager; a *_token name is write-only there and masked in
       the audit log (Settings::isSecretKey). */
    public const KEY_SETTING  = 'gps_forward_token';
    public const KNOT_KMH     = 1.852;
    public const LEAD_SEC     = 3600;          // a departure within the hour already counts: the bus waits for it
    public const MAX_BODY     = 256 * 1024;    // bytes of a JSON body (a batch of fixes)
    public const PUBLIC_URL   = 'https://www.shreehariglobal.in';

    private static ?bool $ready = null;

    public static function enabled(): bool
    {
        return Settings::getBool('gps_trackers_on', true);
    }

    /** Is the migration on this database? Pages and the endpoint ask first. */
    public static function tableReady(): bool
    {
        if (self::$ready === null) {
            try {
                Database::fetch('SELECT id, last_fix_at FROM gps_trackers LIMIT 1');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** For tests: forget the cached tableReady() answer. */
    public static function resetCache(): void
    {
        self::$ready = null;
    }

    /* =================================================================
     *  Pure helpers - no database (tests/gps-trackers-test.php)
     * ================================================================= */

    public static function generateUid(): string
    {
        $abc = self::UID_ALPHABET;
        $out = self::UID_PREFIX;
        for ($i = 0; $i < self::UID_RANDOM; $i++) {
            $out .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $out;
    }

    /** A code made by generateUid() (after normaliseUid). */
    public static function isGeneratedUid(string $uid): bool
    {
        return preg_match('/^' . self::UID_PREFIX . '[A-HJ-NP-Z2-9]{' . self::UID_RANDOM . '}$/', $uid) === 1;
    }

    /** Trimmed and upper-cased device id, or '' when it is not one. */
    public static function normaliseUid(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $uid = strtoupper(trim((string) $raw));
        return preg_match('/^[A-Z0-9._:-]{4,64}$/', $uid) === 1 ? $uid : '';
    }

    /** Only a long random code of ours stands on its own; an IMEI or a typed id needs the key. */
    public static function needsKey(string $uid): bool
    {
        return !self::isGeneratedUid($uid);
    }

    public static function keyOk(string $uid, string $given, string $expected): bool
    {
        if (!self::needsKey($uid)) {
            return true;
        }
        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /** The device id in any of the three request shapes (raw, not normalised). */
    public static function uidOf(array $q): mixed
    {
        if (is_array($q['device'] ?? null)) {
            return $q['device']['uniqueId'] ?? '';
        }
        return $q['id'] ?? $q['deviceid'] ?? $q['device_id'] ?? '';
    }

    /**
     * A fix time: unix seconds, unix MILLIseconds or a date string (ISO
     * 8601; one without a zone is UTC, as GPS time is). Null = none / junk.
     */
    public static function parseTime(mixed $v): ?int
    {
        if ($v === null || is_bool($v) || is_array($v)) {
            return null;
        }
        if (is_numeric($v)) {
            $f = (float) $v;
            if ($f > 1e11) {
                $f /= 1000;   // milliseconds
            }
            return $f >= 1e9 && $f < 4.2e9 ? (int) floor($f) : null;
        }
        $s = trim((string) $v);
        if ($s === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $s) !== 1) {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $s) !== 1) {
            $s .= ' UTC';
        }
        $ts = strtotime($s);
        return $ts !== false && $ts >= 1000000000 ? $ts : null;
    }

    /**
     * One request, any of the three shapes, as fixes.
     *
     *   OsmAnd query / form   id, lat, lon, timestamp, speed (knots), bearing, accuracy, batt, valid
     *   Traccar Client 9      {device_id, location: {timestamp, coords: {latitude, longitude,
     *                          speed (m/s), heading, accuracy}, battery: {level 0..1}}} - or a list
     *   Traccar forward json  {device: {uniqueId}, position: {latitude, longitude, speed (knots),
     *                          course, accuracy, fixTime, valid, attributes: {batteryLevel}}}
     *
     * 'bad' is set when the request cannot be a position report at all (400).
     * A fix the device itself calls invalid is dropped without being bad.
     *
     * @return array{uid:string, key:string, fixes:list<array{ts:?int,lat:float,lng:float,kmh:?float,brg:?float,acc:?float}>, batt:?int, bad:string}
     */
    public static function parseOsmAnd(array $q): array
    {
        $out = ['uid' => self::normaliseUid(self::uidOf($q)), 'key' => is_scalar($q['key'] ?? null) ? trim((string) $q['key']) : '',
                'fixes' => [], 'batt' => null, 'bad' => ''];
        if ($out['uid'] === '') {
            $out['bad'] = 'device id';
            return $out;
        }

        $num   = static fn(mixed $v): ?float => is_numeric($v) && is_finite((float) $v) ? (float) $v : null;
        $nonNeg = static fn(?float $v): ?float => $v !== null && $v >= 0 ? $v : null;
        $pct   = static fn(?float $v): ?int => $v === null || $v < 0 ? null : (int) round(min(100.0, $v));
        $raw   = [];   // [lat, lng, ts, kmh, brg, acc, valid]

        if (is_array($q['location'] ?? null)) {
            // Traccar Client 9: one location, or a list of them
            $locs = array_is_list($q['location']) ? $q['location'] : [$q['location']];
            foreach (array_slice($locs, 0, LiveTrips::MAX_BATCH) as $l) {
                if (!is_array($l) || !is_array($l['coords'] ?? null)) {
                    $out['bad'] = 'location';
                    return $out;
                }
                $c = $l['coords'];
                $speed = $nonNeg($num($c['speed'] ?? null));
                $raw[] = [$c['latitude'] ?? null, $c['longitude'] ?? null, $l['timestamp'] ?? null,
                          $speed !== null ? $speed * 3.6 : null, $nonNeg($num($c['heading'] ?? null)),
                          $nonNeg($num($c['accuracy'] ?? null)), true];
                if (is_array($l['battery'] ?? null) && ($lv = $num($l['battery']['level'] ?? null)) !== null) {
                    $out['batt'] = $pct($lv <= 1.0 ? $lv * 100 : $lv);
                }
            }
        } elseif (is_array($q['position'] ?? null)) {
            // a Traccar server with forward.type json
            $p = $q['position'];
            $speed = $nonNeg($num($p['speed'] ?? null));
            $valid = !array_key_exists('valid', $p) || ($p['valid'] !== false && $p['valid'] !== 'false' && $p['valid'] !== 0 && $p['valid'] !== '0');
            $raw[] = [$p['latitude'] ?? null, $p['longitude'] ?? null, $p['fixTime'] ?? $p['deviceTime'] ?? null,
                      $speed !== null ? $speed * self::KNOT_KMH : null, $nonNeg($num($p['course'] ?? null)),
                      $nonNeg($num($p['accuracy'] ?? null)), $valid];
            if (is_array($p['attributes'] ?? null)) {
                $out['batt'] = $pct($num($p['attributes']['batteryLevel'] ?? null));
            }
        } else {
            // OsmAnd: query string or form
            $lat = $q['lat'] ?? null;
            $lng = $q['lon'] ?? $q['lng'] ?? null;
            if (($lat === null || $lng === null) && is_string($q['location'] ?? null) && str_contains($q['location'], ',')) {
                [$lat, $lng] = array_map('trim', explode(',', $q['location'], 2));
            }
            $speed = $nonNeg($num($q['speed'] ?? null));
            $v     = strtolower(is_scalar($q['valid'] ?? null) ? (string) $q['valid'] : '');
            // GPSLogger reports metres/second; OsmAnd / Traccar forward uses knots.
            $speedFactor = ($q['source'] ?? '') === 'gpslogger' ? 3.6 : self::KNOT_KMH;
            $raw[] = [$lat, $lng, $q['timestamp'] ?? $q['time'] ?? null,
                      $speed !== null ? $speed * $speedFactor : null,
                      $nonNeg($num($q['bearing'] ?? $q['heading'] ?? $q['course'] ?? null)),
                      $nonNeg($num($q['accuracy'] ?? null)), !in_array($v, ['false', '0'], true)];
            $out['batt'] = $pct($num($q['batt'] ?? $q['battery'] ?? null));
        }

        if ($raw === []) {
            $out['bad'] = 'no position';
            return $out;
        }
        foreach ($raw as [$lat, $lng, $ts, $kmh, $brg, $acc, $valid]) {
            $la = $num($lat);
            $lo = $num($lng);
            if ($la === null || $lo === null || abs($la) > 90 || abs($lo) > 180) {
                $out['bad'] = 'position';
                $out['fixes'] = [];
                return $out;
            }
            if (!$valid) {
                continue;
            }
            $out['fixes'][] = ['ts' => self::parseTime($ts), 'lat' => $la, 'lng' => $lo,
                'kmh' => $kmh !== null ? round($kmh, 2) : null, 'brg' => $brg, 'acc' => $acc];
        }
        return $out;
    }

    /**
     * Fixes as LiveTrips points. seq = the fix's unix second, so a fix the
     * device re-sends after a dead zone is stored once (UNIQUE trip_id, seq);
     * no time = now; a clock running ahead = now.
     *
     * @param list<array<string,mixed>> $fixes
     * @return list<array{seq:int,t:int,lat:float,lng:float,kmh:?float,brg:?float,acc:?float}>
     */
    public static function toPoints(array $fixes, int $now): array
    {
        $out = [];
        foreach ($fixes as $f) {
            $ts = $f['ts'] ?? null;
            if (!is_int($ts) || $ts > $now + 120) {
                $ts = $now;
            }
            $out[$ts] = ['seq' => $ts, 't' => $ts * 1000, 'lat' => (float) $f['lat'], 'lng' => (float) $f['lng'],
                         'kmh' => $f['kmh'] ?? null, 'brg' => $f['brg'] ?? null, 'acc' => $f['acc'] ?? null];
        }
        ksort($out);
        return array_values($out);
    }

    /** Payload-free health metadata: never coordinates, device ids or keys. */
    public static function fixHealth(array $points, int $lastTs, int $now): array
    {
        $latest = null;
        foreach ($points as $pt) {
            if ($latest === null || $pt['t'] > $latest['t']) { $latest = $pt; }
        }
        $out = ['receivedAt' => $now * 1000, 'reportedAt' => null, 'accuracyM' => null, 'reason' => 'no_fix'];
        if ($latest === null) { return $out; }
        $out['reportedAt'] = $latest['t'];
        $out['accuracyM'] = $latest['acc'] ?? null;
        $clean = LiveTrips::cleanPoint($latest, $now);
        $out['reason'] = $clean === null ? 'invalid_fix'
            : (($clean['acc'] !== null && $clean['acc'] > LiveTrips::MOVE_MAX_ACC_M) ? 'poor_accuracy'
            : ($clean['ts'] <= $lastTs ? 'old_fix' : ($now - $clean['ts'] > 120 ? 'stale_report' : 'accepted')));
        return $out;
    }

    public static function health(int $trackerId): ?array
    {
        $p = sys_get_temp_dir() . '/shg-gps-health-' . $trackerId . '.json';
        $v = is_file($p) ? json_decode((string) @file_get_contents($p), true) : null;
        return is_array($v) ? $v : null;
    }

    /* =================================================================
     *  The forward key (hardware through a Traccar server)
     * ================================================================= */

    public static function forwardKey(): string
    {
        $k = Settings::getString(self::KEY_SETTING, '');
        return strlen($k) >= 16 ? $k : self::rotateForwardKey();
    }

    /** A new key: every Traccar server must get the new forward URL. */
    public static function rotateForwardKey(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $k   = '';
        for ($i = 0; $i < 24; $i++) {
            $k .= $abc[random_int(0, strlen($abc) - 1)];
        }
        Settings::set(self::KEY_SETTING, $k, 'string', 'automation');
        return $k;
    }

    /* =================================================================
     *  Trackers (admin/gps-trackers.php)
     * ================================================================= */

    public static function findByUid(string $uid): ?array
    {
        return Database::fetch('SELECT * FROM gps_trackers WHERE device_uid = :u LIMIT 1', ['u' => $uid]);
    }

    /** @return list<array<string,mixed>> */
    public static function forBus(int $busId): array
    {
        return Database::fetchAll('SELECT * FROM gps_trackers WHERE bus_id = :b ORDER BY is_active DESC, id', ['b' => $busId]);
    }

    /**
     * Active buses (and any inactive one that still has a tracker), each
     * with its trackers - ordered by bus number.
     *
     * @return list<array<string,mixed>>
     */
    public static function busesWithTrackers(): array
    {
        $buses = Database::fetchAll(
            'SELECT b.id, b.bus_number, b.bus_name, b.is_active FROM buses b
              WHERE b.is_active = 1 OR EXISTS (SELECT 1 FROM gps_trackers g WHERE g.bus_id = b.id)
              ORDER BY b.is_active DESC, b.bus_number'
        );
        $byBus = [];
        foreach (Database::fetchAll('SELECT * FROM gps_trackers ORDER BY is_active DESC, id') as $t) {
            $byBus[(int) $t['bus_id']][] = $t;
        }
        foreach ($buses as &$b) {
            $b['trackers'] = $byBus[(int) $b['id']] ?? [];
        }
        unset($b);
        return $buses;
    }

    /**
     * @return array<string,mixed> the new row
     * @throws RuntimeException with a message for the office
     */
    public static function add(int $busId, string $label, string $kind, string $uidRaw): array
    {
        $bus = Database::fetch('SELECT id, bus_number FROM buses WHERE id = :i', ['i' => $busId]);
        if ($bus === null) {
            throw new RuntimeException('Choose a bus.');
        }
        $kind  = $kind === 'hardware' ? 'hardware' : 'phone';
        $label = trim(mb_substr($label, 0, 80));
        $uid   = trim($uidRaw) === '' ? '' : self::normaliseUid($uidRaw);
        if (trim($uidRaw) !== '' && $uid === '') {
            throw new RuntimeException('The device id may only have letters, digits and . _ : - (4 to 64 of them).');
        }
        if ($kind === 'phone' && $uid !== '' && !self::isGeneratedUid($uid)) {
            throw new RuntimeException('A phone uses a code made here: leave the device id empty (or press New code).');
        }
        if ($uid === '') {
            for ($i = 0; $i < 5 && ($uid === '' || self::findByUid($uid) !== null); $i++) {
                $uid = self::generateUid();
            }
        }
        $other = self::findByUid($uid);
        if ($other !== null) {
            $num = (string) Database::scalar('SELECT bus_number FROM buses WHERE id = :i', ['i' => (int) $other['bus_id']], '?');
            throw new RuntimeException('That device id is already on bus ' . $num . '.');
        }
        $id = Database::insert('gps_trackers', [
            'bus_id'     => $busId,
            'label'      => $label !== '' ? $label : ($kind === 'phone' ? 'Phone' : 'GPS device'),
            'device_uid' => $uid,
            'kind'       => $kind,
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (array) Database::fetch('SELECT * FROM gps_trackers WHERE id = :i', ['i' => $id]);
    }

    public static function setActive(int $id, bool $on): void
    {
        Database::update('gps_trackers', ['is_active' => $on ? 1 : 0, 'revoked_at' => $on ? null : date('Y-m-d H:i:s')],
            'id = :id', ['id' => $id]);
    }

    public static function remove(int $id): void
    {
        Database::delete('gps_trackers', 'id = :id', ['id' => $id]);
    }

    /* =================================================================
     *  Which departure is each bus running now?
     * ================================================================= */

    /**
     * Bus id => the LiveTrips::choosable() row of the departure it runs now:
     * leaving at most LEAD_SEC from now, the most recent one (a coach does
     * one trip at a time).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function tripsByBus(int $now, ?int $onlyBus = null): array
    {
        $cands = [];
        foreach (LiveTrips::choosable($now) as $c) {
            if ($c['depTs'] <= $now + self::LEAD_SEC) {
                $cands[$c['scheduleId']] = $c;
            }
        }
        if ($cands === []) {
            return [];
        }
        $ph = [];
        $params = [];
        foreach (array_keys($cands) as $i => $sid) {
            $ph[] = ':s' . $i;
            $params['s' . $i] = $sid;
        }
        $sql = 'SELECT id, bus_id FROM schedules WHERE bus_id IS NOT NULL AND id IN (' . implode(',', $ph) . ')';
        if ($onlyBus !== null) {
            $sql .= ' AND bus_id = :bus';
            $params['bus'] = $onlyBus;
        }
        $out = [];
        foreach (Database::fetchAll($sql, $params) as $r) {
            $b = (int) $r['bus_id'];
            $c = $cands[(int) $r['id']] + ['busId' => $b];
            if (!isset($out[$b]) || $c['depTs'] > $out[$b]['depTs']) {
                $out[$b] = $c;
            }
        }
        return $out;
    }

    public static function tripForBus(int $busId, int $now): ?array
    {
        return self::tripsByBus($now, $busId)[$busId] ?? null;
    }

    /* =================================================================
     *  One request from a tracker
     * ================================================================= */

    /**
     * @param array<string,mixed> $q query + form + JSON body, merged
     * @return array{ok:bool, code:int, trip:?int, moved:bool, error:string}
     *         code 200 / 400 bad request / 403 unknown, switched off or wrong key
     */
    public static function ingestOsmAnd(array $q, ?int $now = null): array
    {
        $now = $now ?? time();
        $res = static fn(int $code, string $err = '', ?int $trip = null, bool $moved = false): array =>
            ['ok' => $code === 200, 'code' => $code, 'trip' => $trip, 'moved' => $moved, 'error' => $err];

        $p = self::parseOsmAnd($q);
        if ($p['bad'] !== '') {
            return $res(400, 'bad ' . $p['bad']);
        }
        $t = self::findByUid($p['uid']);
        if ($t === null || (int) $t['is_active'] !== 1 || $t['revoked_at'] !== null) {
            return $res(403, 'unknown or switched-off device');
        }
        if (!self::keyOk($p['uid'], $p['key'], self::needsKey($p['uid']) ? self::forwardKey() : '')) {
            return $res(403, 'key');
        }

        $points = self::toPoints($p['fixes'], $now);
        $busId  = (int) $t['bus_id'];

        // the tracker's own row: alive now, and its newest usable fix
        $upd    = ['last_seen' => date('Y-m-d H:i:s', $now)];
        $lastTs = $t['last_fix_at'] !== null ? (int) strtotime((string) $t['last_fix_at']) : 0;
        $newest = null;
        foreach ($points as $pt) {
            $c = LiveTrips::cleanPoint($pt, $now);
            if ($c !== null && ($c['acc'] === null || $c['acc'] <= LiveTrips::MOVE_MAX_ACC_M) && $c['ts'] > $lastTs && ($newest === null || $c['ts'] >= $newest['ts'])) {
                $newest = $c;
            }
        }
        // Keep only the last report's quality metadata for the private admin status.
        $healthPath = sys_get_temp_dir() . '/shg-gps-health-' . (int) $t['id'] . '.json';
        @file_put_contents($healthPath, json_encode(self::fixHealth($points, $lastTs, $now)), LOCK_EX);
        @chmod($healthPath, 0600);
        if ($p['batt'] !== null) {
            $upd['last_batt'] = $p['batt'];
        }
        Database::update('gps_trackers', $upd, 'id = :id', ['id' => (int) $t['id']]);
        // Delayed concurrent requests must never overwrite a newer GPS fix.
        if ($newest !== null) {
            Database::query('UPDATE gps_trackers SET last_fix_at=:fix, last_lat=:lat, last_lng=:lng, last_speed=:speed
                WHERE id=:id AND (last_fix_at IS NULL OR last_fix_at < :older)',
                ['fix' => date('Y-m-d H:i:s', $newest['ts']), 'lat' => $newest['lat'], 'lng' => $newest['lng'],
                 'speed' => $newest['kmh'], 'id' => (int) $t['id'], 'older' => date('Y-m-d H:i:s', $newest['ts'])]);
        }

        if ($points === [] || !LiveTrips::available()) {
            return $res(200);
        }
        $run = self::tripForBus($busId, $now);
        if ($run === null) {
            return $res(200);   // parked: no departure of this bus is on now
        }
        try {
            $trip = LiveTrips::startForBus((int) $run['scheduleId'], $busId, $now);
        } catch (RuntimeException $e) {
            return $res(200);
        }
        if ((string) $trip['status'] !== 'running') {
            return $res(200);   // the driver ended this trip: it stays ended
        }
        $r = LiveTrips::ingest($trip, $points, $now, true);
        return $res(200, '', (int) $run['scheduleId'], $r['moved']);
    }
}
