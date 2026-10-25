<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/gpstrackers.php';

/** GPSLogger's Java .properties format. Profiles contain a phone's existing credential. */
final class GpsLoggerProfile
{
    public const TTL = 86400;

    public static function signature(int $id, int $expires): string
    {
        return hash_hmac('sha256', 'gpslogger-profile-v1|' . $id . '|' . $expires, APP_KEY);
    }

    public static function valid(int $id, int $expires, string $sig, ?int $now = null): bool
    {
        $now ??= time();
        return $id > 0 && $expires > $now && $expires <= $now + self::TTL
            && strlen($sig) === 64 && hash_equals(self::signature($id, $expires), $sig);
    }

    public static function query(int $id, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::TTL;
        return http_build_query(['id' => $id, 'e' => $expires, 'sig' => self::signature($id, $expires)]);
    }

    public static function body(string $uid): string
    {
        if (!GpsTrackers::isGeneratedUid($uid)) { throw new InvalidArgumentException('Phone tracker code required.'); }
        $payload = 'source=gpslogger&id=' . $uid . '&lat=%LAT&lon=%LON&timestamp=%TIMESTAMP&speed=%SPD&bearing=%DIR&accuracy=%ACC&batt=%BATT';
        return "# S Hari Global - bus GPS profile\n# Import into GPSLogger for Android (Mendhak). Keep this file private.\n"
            . "log_customurl_enabled=true\nlog_customurl_url=" . GpsTrackers::PUBLIC_URL . "/api/gps.php\n"
            . "log_customurl_method=POST\nlog_customurl_headers=Content-Type: application/x-www-form-urlencoded\n"
            . 'log_customurl_body=' . $payload . "\n"
            . "time_before_logging=10\ndistance_before_logging=0\naccuracy_before_logging=40\n"
            . "keep_fix=true\nretry_time=30\nabsolute_timeout=120\nretry_get_best_possible_accuracy=false\n"
            . "log_satellite_locations=true\nlog_network_locations=false\nlog_passive_locations=false\nlog_fused_locations=false\n"
            . "log_customurl_discard_offline_locations_enabled=true\nautosend_enabled=false\nautocustomurl_enabled=false\n"
            . "log_gpx=false\nlog_kml=false\nlog_plain_text=false\nlog_geojson=false\nlog_nmea=false\n"
            . "startonbootup=false\nstartonapplaunch=false\nstoponapplaunch=false\n";
    }

    /** Shared by the setup page and the download; no cookies required on the phone. */
    public static function authorizedTracker(array $q): ?array
    {
        $id = (int) ($q['id'] ?? 0);
        $expires = (int) ($q['e'] ?? 0);
        $sig = is_string($q['sig'] ?? null) ? $q['sig'] : '';
        if (!self::valid($id, $expires, $sig)) { return null; }
        return Database::fetch("SELECT g.id,g.bus_id,g.device_uid,b.bus_number FROM gps_trackers g JOIN buses b ON b.id=g.bus_id
            WHERE g.id=:id AND g.kind='phone' AND g.is_active=1 AND g.revoked_at IS NULL AND b.is_active=1", ['id' => $id]);
    }
}
