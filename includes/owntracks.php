<?php
/**
 * =====================================================================
 *  includes/owntracks.php — OwnTracks Mobile App Integration
 *
 *  Supports OwnTracks on Android/iOS (HTTP mode 3).
 *  The driver/staff phone runs OwnTracks with background location service.
 *  It transmits GPS updates via HTTP POST to /api/owntracks.php.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/gpstrackers.php';

final class OwnTracksGateway
{
    /**
     * Authenticate tracker from HTTP Basic Auth headers or bearer token.
     *
     * @param array<string,mixed> $server Usually $_SERVER
     * @return array<string,mixed>|null Tracker row or null
     */
    public static function authenticatedTracker(array $server): ?array
    {
        $user = trim((string) ($server['PHP_AUTH_USER'] ?? ''));
        $pass = trim((string) ($server['PHP_AUTH_PW'] ?? ''));

        if ($user === '' && $pass === '') {
            $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
            if (stripos($auth, 'Basic ') === 0) {
                $decoded = base64_decode(substr($auth, 6), true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                    $user = trim($user);
                    $pass = trim($pass);
                }
            }
        }

        // The UID can be in username, password, or both
        $candidateUid = GpsTrackers::normaliseUid($user ?: $pass);
        if ($candidateUid === '') {
            $candidateUid = GpsTrackers::normaliseUid($pass);
        }

        if ($candidateUid === '') {
            return null;
        }

        $tracker = Database::fetch(
            "SELECT g.*, b.bus_number, b.bus_name
               FROM gps_trackers g
               JOIN buses b ON b.id = g.bus_id
              WHERE g.device_uid = :uid
                AND g.is_active = 1
                AND g.revoked_at IS NULL
                AND b.is_active = 1
              LIMIT 1",
            ['uid' => $candidateUid]
        );

        if ($tracker === null) {
            return null;
        }

        // If the tracker needs a forward key (hardware IMEI), verify the password
        if (GpsTrackers::needsKey($candidateUid)) {
            $expectedKey = Settings::getString(GpsTrackers::KEY_SETTING, '');
            if ($expectedKey === '' || !hash_equals($expectedKey, $pass)) {
                return null;
            }
        }

        return $tracker;
    }

    /**
     * Map OwnTracks JSON location payload into OsmAnd format for GpsTrackers::ingestOsmAnd.
     *
     * @param array<string,mixed> $payload
     * @param string $deviceUid
     * @return array<string,mixed>
     */
    public static function location(array $payload, string $deviceUid): array
    {
        $lat = $payload['lat'] ?? null;
        $lon = $payload['lon'] ?? null;
        $tst = $payload['tst'] ?? time();
        $vel = isset($payload['vel']) && is_numeric($payload['vel']) ? (float) $payload['vel'] : null;
        $cog = isset($payload['cog']) && is_numeric($payload['cog']) ? (float) $payload['cog'] : null;
        $acc = isset($payload['acc']) && is_numeric($payload['acc']) ? (float) $payload['acc'] : null;
        $batt = isset($payload['batt']) && is_numeric($payload['batt']) ? (int) $payload['batt'] : null;

        // OwnTracks sends vel in km/h. GpsTrackers::parseOsmAnd expects speed in knots and multiplies by 1.852.
        $speedKnots = ($vel !== null && $vel >= 0) ? ($vel / GpsTrackers::KNOT_KMH) : null;

        return [
            'id'        => $deviceUid,
            'lat'       => $lat,
            'lon'       => $lon,
            'timestamp' => $tst,
            'speed'     => $speedKnots,
            'bearing'   => $cog,
            'accuracy'  => $acc,
            'batt'      => $batt,
        ];
    }

    /**
     * Generate .otrc JSON profile for 1-tap OwnTracks Android/iOS setup.
     *
     * @param array<string,mixed> $tracker
     * @return string
     */
    public static function profile(array $tracker): string
    {
        $uid = (string) ($tracker['device_uid'] ?? '');
        $busNo = (string) ($tracker['bus_number'] ?? 'SHG-BUS');
        $baseUrl = defined('GpsTrackers::PUBLIC_URL') ? GpsTrackers::PUBLIC_URL : 'https://www.shreehariglobal.in';

        $config = [
            '_type'               => 'configuration',
            'mode'                => 3, // 3 = HTTP mode
            'url'                 => $baseUrl . '/api/owntracks.php',
            'auth'                => true,
            'username'            => $uid,
            'password'            => $uid,
            'deviceId'            => $busNo,
            'tid'                 => 'SH',
            'locatorDisplacement' => 20,  // meters
            'locatorInterval'     => 15,  // seconds
            'monitoring'          => 2,   // significant movement + periodic
            'cmd'                 => false,
            'pubExtendedData'     => true,
        ];

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
