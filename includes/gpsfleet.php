<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/gpstrackers.php';

/** Read-only device health. Credentials never leave the configuration page. */
final class GpsFleet
{
    /** Public bus GPS is separately opted in; no booking/trip is fabricated for a map test. */
    public static function publicPoints(array $devices, int $now): array
    {
        $byBus = [];
        foreach ($devices as $d) {
            if (empty($d['public']) || $d['state'] !== 'live' || !is_numeric($d['fixAt'] ?? null)
                || !is_numeric($d['seenAt'] ?? null) || !is_numeric($d['lat'] ?? null) || !is_numeric($d['lng'] ?? null)) { continue; }
            $age = $now - (int) floor($d['fixAt'] / 1000);
            $seenAge = $now - (int) floor($d['seenAt'] / 1000);
            if ($age < -120 || $age > 120 || $seenAge < -120 || $seenAge > 120
                || $d['lat'] < 6 || $d['lat'] > 37.5 || $d['lng'] < 68 || $d['lng'] > 98) { continue; }
            $bus = (int) $d['busId'];
            if (isset($byBus[$bus]) && $byBus[$bus]['fixAt'] >= $d['fixAt']) { continue; }
            $health = $d['diagnostic'] ?? null;
            $acc = is_array($health) && ($health['reportedAt'] ?? null) === $d['fixAt'] ? ($health['accuracyM'] ?? null) : null;
            $byBus[$bus] = ['id' => -$bus, 'bus' => $d['bus'], 'busName' => $d['name'], 'trackingOnly' => true,
                'from' => '', 'to' => '', 'dep' => '', 'lat' => (float) $d['lat'], 'lng' => (float) $d['lng'],
                'kmh' => $d['kmh'], 'brg' => null, 'pct' => null, 'next' => null,
                'fixAt' => (int) $d['fixAt'], 'age' => max(0, $age), 'acc' => $acc];
        }
        return array_values($byBus);
    }

    public static function state(array $r, int $now, bool $enabled): string
    {
        if (!$enabled || !(int) ($r['is_active'] ?? 0)) { return 'disabled'; }
        if (empty($r['last_seen'])) { return 'waiting'; }
        $seen = $now - (int) strtotime((string) $r['last_seen']);
        if ($seen > 120 || $seen < -120) { return 'offline'; }
        if (empty($r['last_fix_at']) || $r['last_lat'] === null || $r['last_lng'] === null) { return 'no_fix'; }
        $age = $now - (int) strtotime((string) $r['last_fix_at']);
        return $age >= -120 && $age <= 120 ? 'live' : 'stale';
    }

    public static function snapshot(?int $now = null): array
    {
        $now ??= time();
        $enabled = GpsTrackers::enabled();
        $out = [];
        if (!GpsTrackers::tableReady()) { return ['at' => $now * 1000, 'enabled' => $enabled, 'devices' => []]; }
        foreach (Database::fetchAll('SELECT g.id,g.bus_id,g.label,g.kind,g.is_active,g.last_seen,g.last_fix_at,
            g.last_lat,g.last_lng,g.last_speed,g.last_batt,b.bus_number,b.bus_name
            FROM gps_trackers g JOIN buses b ON b.id=g.bus_id WHERE b.is_active=1 ORDER BY b.bus_number,g.id') as $r) {
            $state = self::state($r, $now, $enabled);
            $out[] = ['id' => (int) $r['id'], 'busId' => (int) $r['bus_id'], 'bus' => $r['bus_number'],
                'diagnostic' => GpsTrackers::health((int) $r['id']),
                'public' => Settings::getBool('gps_public_bus_' . (int) $r['bus_id'], false),
                'name' => $r['bus_name'], 'label' => $r['label'], 'kind' => $r['kind'], 'state' => $state,
                'seenAt' => $r['last_seen'] ? strtotime($r['last_seen']) * 1000 : null,
                'fixAt' => $r['last_fix_at'] ? strtotime($r['last_fix_at']) * 1000 : null,
                'lat' => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
                'lng' => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
                'kmh' => $state === 'live' && $r['last_speed'] !== null ? round((float) $r['last_speed'], 1) : null,
                'battery' => $r['last_batt'] !== null ? (int) $r['last_batt'] : null];
        }
        return ['at' => $now * 1000, 'enabled' => $enabled, 'devices' => $out];
    }
}
