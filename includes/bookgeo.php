<?php
/**
 * includes/bookgeo.php — BookGeo: where a ticket was cut (25 Nov 2026, cp1).
 *
 * Owner (25 Nov): "customer location ticket ma nai, admin page ma chalani page ma aos".
 * The booking page asks the phone for its location when the person starts booking (never
 * on page load) and, when allowed, sends it with the booking POST as two headers:
 *
 *   X-SHG-Geo:       "<lat>,<lng>,<accuracy m>"
 *   X-SHG-Geo-Place: rawurlencoded town / area name (reverse geocoded in the browser)
 *
 * stamp() turns them into the bookings columns of database/upgrade-2026-11-25-cp1-booked-geo.sql.
 * Anything missing, malformed or out of range = nothing stored; a booking NEVER fails on this.
 * Switch booking_geo_on (default on). Before the migration runs, stamp() returns [].
 */
declare(strict_types=1);

final class BookGeo
{
    public static function hasColumns(): bool
    {
        if (!class_exists('CounterDesk')) {
            require_once __DIR__ . '/counterdesk.php';
        }
        return CounterDesk::hasColumn('bookings', 'booked_lat') && CounterDesk::hasColumn('bookings', 'booked_place');
    }

    /** @return array{lat: float, lng: float, acc: ?int, place: string}|null */
    public static function fromRequest(): ?array
    {
        $raw = trim((string) ($_SERVER['HTTP_X_SHG_GEO'] ?? ''));
        if ($raw === '' || strlen($raw) > 60) {
            return null;
        }
        if (preg_match('/^(-?\d{1,2}(?:\.\d{1,7})?),(-?\d{1,3}(?:\.\d{1,7})?)(?:,(\d{1,6}))?$/', $raw, $m) !== 1) {
            return null;
        }
        $lat = (float) $m[1];
        $lng = (float) $m[2];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }
        $acc = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        $place = rawurldecode(substr((string) ($_SERVER['HTTP_X_SHG_GEO_PLACE'] ?? ''), 0, 400));
        $place = Security::clean((string) (preg_replace('/[\x00-\x1F<>"]+/u', ' ', $place) ?? ''), 120);
        return ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'acc' => $acc, 'place' => trim($place)];
    }

    /** The bookings columns for this request, or [] (no fix / switch off / no migration). */
    public static function stamp(): array
    {
        try {
            if (!Settings::getBool('booking_geo_on', true) || !self::hasColumns()) {
                return [];
            }
            $g = self::fromRequest();
            if ($g === null) {
                return [];
            }
            return [
                'booked_lat'   => $g['lat'],
                'booked_lng'   => $g['lng'],
                'booked_acc'   => $g['acc'],
                'booked_place' => $g['place'] !== '' ? $g['place'] : null,
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** "Nana Chiloda, Ahmedabad (23.109162, 72.677421)" — or '' when the row has no fix. */
    public static function line(array $booking): string
    {
        $lat = $booking['booked_lat'] ?? null;
        $lng = $booking['booked_lng'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return '';
        }
        $pt = sprintf('%.5f, %.5f', (float) $lat, (float) $lng);
        $place = trim((string) ($booking['booked_place'] ?? ''));
        return $place !== '' ? $place . ' (' . $pt . ')' : $pt;
    }

    public static function mapUrl(array $booking): string
    {
        $lat = $booking['booked_lat'] ?? null;
        $lng = $booking['booked_lng'] ?? null;
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return '';
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sprintf('%.6f,%.6f', (float) $lat, (float) $lng));
    }
}
