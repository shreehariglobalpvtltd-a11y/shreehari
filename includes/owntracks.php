<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/gpsloggerprofile.php';

/** OwnTracks HTTP adapter. Device topics never choose the bus; authenticated credentials do. */
final class OwnTracksGateway
{
    public static function credentials(array $server): ?array
    {
        if (isset($server['PHP_AUTH_USER'], $server['PHP_AUTH_PW'])) {
            return [(string) $server['PHP_AUTH_USER'], (string) $server['PHP_AUTH_PW']];
        }
        $header = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || strlen($header) > 512 || preg_match('/^Basic\s+(.+)$/i', $header, $m) !== 1) { return null; }
        $decoded = base64_decode($m[1], true);
        return is_string($decoded) && str_contains($decoded, ':') ? explode(':', $decoded, 2) : null;
    }

    public static function authenticatedTracker(array $server): ?array
    {
        $auth = self::credentials($server);
        if ($auth === null || !GpsTrackers::isGeneratedUid($auth[1])) { return null; }
        $tracker = GpsTrackers::findByUid($auth[1]);
        if ($tracker === null || $tracker['kind'] !== 'phone' || !(int) $tracker['is_active'] || $tracker['revoked_at'] !== null
            || !hash_equals('bus-' . $tracker['bus_id'], $auth[0])) { return null; }
        return $tracker;
    }

    public static function location(array $payload, string $uid): array
    {
        if (($payload['_type'] ?? '') !== 'location' || !is_numeric($payload['tst'] ?? null)
            || (float) $payload['tst'] < 1000000000 || !is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lon'] ?? null)) {
            throw new InvalidArgumentException('A timestamped location is required.');
        }
        $velocity = is_numeric($payload['vel'] ?? null) && (float) $payload['vel'] >= 0 ? (float) $payload['vel'] : null;
        return ['id' => $uid, 'lat' => $payload['lat'], 'lon' => $payload['lon'], 'timestamp' => $payload['tst'],
            // OwnTracks is km/h, while this existing ingestion format uses knots.
            'speed' => $velocity !== null ? $velocity / GpsTrackers::KNOT_KMH : null,
            'bearing' => $payload['cog'] ?? null, 'accuracy' => $payload['acc'] ?? null, 'batt' => $payload['batt'] ?? null];
    }

    public static function profile(array $tracker): string
    {
        if (!GpsTrackers::isGeneratedUid((string) $tracker['device_uid'])) { throw new InvalidArgumentException('Phone code required.'); }
        $plate = (string) $tracker['bus_number'];
        return json_encode(['_type' => 'configuration', 'mode' => 3, 'url' => GpsTrackers::PUBLIC_URL . '/api/owntracks.php',
            'auth' => true, 'username' => 'bus-' . (int) $tracker['bus_id'], 'password' => $tracker['device_uid'],
            'deviceId' => $plate, 'trackerId' => substr(preg_replace('/[^A-Za-z0-9]/', '', $plate), -2),
            'monitoring' => 2, 'locatorPriority' => 0, 'locatorInterval' => 3, 'locatorDisplacement' => 0,
            'ignoreInaccurateLocations' => 100, 'autostartOnBoot' => true, 'allowRemoteLocation' => false],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
