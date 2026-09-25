<?php
/**
 * includes/trust.php — the trust layer (24 Sep 2026).
 *
 *  Three switches, all shipped OFF (database/upgrade-2026-09-24-trust-layer.sql):
 *
 *   trust_card_on     the home page shows real numbers from our own register
 *                     (trips this year, passengers carried, the public
 *                     rating) — never a typed claim;
 *   refund_ladder_on  the refund slabs stand next to the Pay button and a
 *                     cancelled booking shows where its refund is;
 *   women_layer_on    the seat map says how many women are already on the
 *                     bus and carries the 24×7 helpline (women_helpline,
 *                     else the office number).
 *
 *  Nothing here is typed twice: the ladder is Fare::refundSlabs(), the
 *  same slabs Fare::refundFor() enforces; the numbers are three cheap
 *  queries, cached ten minutes when APCu is present.
 */

declare(strict_types=1);

final class Trust
{
    private const CACHE_KEY = 'shg:trust:numbers';
    private const CACHE_TTL = 600;

    /** @return array{trips:int,pax:int,rating:float,ratingCount:int,womenSeats:int,year:string} */
    public static function numbers(): array
    {
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch(self::CACHE_KEY);
            if (is_array($hit)) {
                return $hit;
            }
        }
        $year = date('Y');
        $out  = ['trips' => 0, 'pax' => 0, 'rating' => 0.0, 'ratingCount' => 0, 'womenSeats' => 0, 'year' => $year];
        try {
            $row = Database::fetch(
                "SELECT COUNT(DISTINCT bl.schedule_id, bl.travel_date) trips, COALESCE(SUM(bl.seat_count), 0) pax
                   FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                  WHERE b.status IN ('confirmed', 'completed') AND bl.travel_date >= :y AND bl.travel_date <= CURDATE()",
                ['y' => $year . '-01-01']
            ) ?? [];
            $out['trips'] = (int) ($row['trips'] ?? 0);
            $out['pax']   = (int) ($row['pax'] ?? 0);
        } catch (Throwable $e) { /* the card simply shows less */ }
        try {
            $r = Database::fetch('SELECT ROUND(AVG(rating), 1) r, COUNT(*) n FROM feedback WHERE is_public = 1 AND rating > 0') ?? [];
            $out['rating']      = (float) ($r['r'] ?? 0);
            $out['ratingCount'] = (int) ($r['n'] ?? 0);
        } catch (Throwable $e) { /* no feedback table yet */ }
        try {
            foreach ((array) Settings::getArray('female_seats', []) as $list) {
                $out['womenSeats'] = max($out['womenSeats'], count((array) $list));
            }
        } catch (Throwable $e) { /* optional */ }
        if (function_exists('apcu_store')) {
            apcu_store(self::CACHE_KEY, $out, self::CACHE_TTL);
        }
        return $out;
    }

    /** @return list<array{minHrs:int,pct:int}> highest threshold first */
    public static function ladder(): array
    {
        require_once __DIR__ . '/fare.php';
        return array_values(array_map(
            static fn(array $s): array => ['minHrs' => (int) $s['minHrs'], 'pct' => (int) $s['pct']],
            Fare::refundSlabs()
        ));
    }

    /** What the app receives in SHG_BOOT.trust — only what a switch turned on. */
    public static function boot(): array
    {
        $out = [];
        if (Settings::getBool('trust_card_on', false)) {
            $out['numbers'] = self::numbers();
        }
        if (Settings::getBool('refund_ladder_on', false)) {
            $out['ladder'] = self::ladder();
        }
        return $out;
    }
}
