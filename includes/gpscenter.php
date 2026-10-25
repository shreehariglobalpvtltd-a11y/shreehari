<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/gpstrackers.php';
require_once __DIR__ . '/fleet.php';

final class GpsCenter
{
    public static function plan(array $d, bool $canCreate, bool $canPublish): array
    {
        $busId = max(0, (int) ($d['bus_id'] ?? 0));
        $plate = strtoupper(trim(mb_substr((string) ($d['bus_number'] ?? ''), 0, 30)));
        if ($busId === 0 && (!$canCreate || $plate === '')) { throw new RuntimeException('Choose an existing bus, or enter a new bus number with fleet-management permission.'); }
        $kind = ($d['kind'] ?? '') === 'hardware' ? 'hardware' : 'phone';
        $uid = $kind === 'hardware' ? GpsTrackers::normaliseUid($d['uid'] ?? '') : '';
        if ($kind === 'hardware' && $uid === '') { throw new RuntimeException('Enter the GPS device IMEI / identifier.'); }
        $publish = ($d['public'] ?? '') === '1';
        if ($publish && !$canPublish) { throw new RuntimeException('Only a manager with settings permission can enable public GPS sharing.'); }
        return ['busId' => $busId, 'plate' => $plate, 'name' => trim(mb_substr((string) ($d['bus_name'] ?? ''), 0, 120)),
            'kind' => $kind, 'uid' => $uid, 'publish' => $publish,
            'coach' => ($d['coach_type'] ?? '') === 'seater' ? 'seater' : 'sleeper',
            'seats' => max(1, min(120, (int) ($d['total_seats'] ?? 72)))];
    }

    public static function connect(array $d, int $by, bool $canCreate, bool $canPublish): array
    {
        $p = self::plan($d, $canCreate, $canPublish);
        try {
            return Database::transaction(static function () use ($p, $by, $canPublish): array {
                $busId = $p['busId'];
                if ($busId === 0) {
                    $existing = Database::fetch('SELECT id FROM buses WHERE bus_number=:n', ['n' => $p['plate']]);
                    $busId = $existing !== null ? (int) $existing['id'] : Fleet::createBus($p['name'] ?: $p['plate'], $p['plate'], $p['coach'], $p['seats'], '', $by);
                }
                if (Database::fetch('SELECT id FROM buses WHERE id=:b AND is_active=1 FOR UPDATE', ['b' => $busId]) === null) { throw new RuntimeException('Choose an active bus.'); }
                $tracker = null;
                foreach (GpsTrackers::forBus($busId) as $t) {
                    if ((int) $t['is_active'] === 1 && $t['revoked_at'] === null && $t['kind'] === $p['kind']
                        && ($p['kind'] === 'phone' || $t['device_uid'] === $p['uid'])) { $tracker = $t; break; }
                }
                $tracker ??= GpsTrackers::add($busId, $p['kind'] === 'phone' ? 'Bus phone - OwnTracks' : 'Bus GPS unit', $p['kind'], $p['uid']);
                if ($canPublish) { Settings::set('gps_public_bus_' . $busId, $p['publish'], 'bool', 'automation'); }
                Logger::audit('gps_center.connect', 'bus', (string) $busId, null,
                    ['tracker' => (int) $tracker['id'], 'kind' => $p['kind'], 'public' => $canPublish ? $p['publish'] : null], 'by admin #' . $by);
                return ['busId' => $busId, 'trackerId' => (int) $tracker['id']];
            });
        } finally { Settings::flush(); }
    }
}
