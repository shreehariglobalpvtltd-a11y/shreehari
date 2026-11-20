<?php
/**
 * includes/normalfare.php — the route's real "normal fare" (20 Nov 2026, nf1).
 *
 * Owner asked to show a higher fare struck through so a customer sees a saving.
 * A made-up "was" price is misleading advertising (India CPA 2019 / CCPA dark-
 * pattern guidelines, Nepal consumer law), so this shows ONLY a fare the office
 * enters as the route's real normal fare (e.g. the counter / peak-day price it
 * actually charges). Per seat, per route, in settings `normal_fare_json`
 * ({"<routeId>": 4500}); switch `normal_fare_on`. It is shown only when it is
 * higher than what this sale really costs - it never changes a price.
 */

declare(strict_types=1);

final class NormalFare
{
    /** @return array<int,float> routeId => per-seat normal fare (> 0 only) */
    public static function map(): array
    {
        $raw = Settings::get('normal_fare_json', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $out = [];
        foreach ((array) $raw as $rid => $amt) {
            $a = round((float) $amt, 2);
            if ((int) $rid > 0 && $a > 0) {
                $out[(int) $rid] = $a;
            }
        }
        return $out;
    }

    public static function on(): bool
    {
        return Settings::getBool('normal_fare_on', false);
    }

    public static function perSeat(int $routeId): float
    {
        if (!self::on()) {
            return 0.0;
        }
        return self::map()[$routeId] ?? 0.0;
    }

    /**
     * The normal total for $seats seats, or null when there is nothing honest to
     * show (switch off, no fare set, or not above what is really charged).
     *
     * @return array{normal:float,save:float}|null
     */
    public static function compare(int $routeId, int $seats, float $paid): ?array
    {
        $per = self::perSeat($routeId);
        if ($per <= 0 || $seats <= 0 || $paid <= 0) {
            return null;
        }
        $normal = round($per * $seats, 2);
        $save   = round($normal - $paid, 2);
        return $save >= 1 ? ['normal' => $normal, 'save' => $save] : null;
    }

    /** compare() for a stored booking (route via its first leg, live seat rows). */
    public static function forBooking(array $booking): ?array
    {
        if (!self::on() || empty($booking['id'])) {
            return null;
        }
        try {
            $rid = (int) (Database::scalar(
                'SELECT s.route_id FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id
                  WHERE bl.booking_id = :b ORDER BY bl.id LIMIT 1',
                ['b' => (int) $booking['id']]
            ) ?? 0);
            $n = (int) (Database::scalar(
                'SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL',
                ['b' => (int) $booking['id']]
            ) ?? 0);
        } catch (Throwable $e) {
            return null;
        }
        return $rid > 0 ? self::compare($rid, max(1, $n), (float) ($booking['total_amount'] ?? 0)) : null;
    }
}
