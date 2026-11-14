<?php
/**
 * =====================================================================
 *  includes/gpsretention.php - how long the GPS history is kept
 *  (15 Nov 2026, fleet watch + data hygiene).
 *
 *  Every fix the driver app or a bus tracker sends is a trip_locations row
 *  (one every ~15 s, ~4,800 a day per coach). Kept for ever by default.
 *  With Settings -> gps_history_days > 0 (cron/rotate.php, daily):
 *
 *    trip_locations  rows older than the window, trip by trip through the
 *                    (trip_id, recorded_at) index, DELETE ... LIMIT 5000 a
 *                    batch, at most MAX_BATCHES batches a run (the next
 *                    night carries on);
 *    live_trips      a FINISHED trip (completed / cancelled, with an
 *                    ended_at) that ended before the window - its last
 *                    fixes go with it (ON DELETE CASCADE).
 *
 *  A trip that is running or paused is never touched, however old. 0 = OFF
 *  (the default: nothing is deleted); any other value is at least 7 days.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class GpsRetention
{
    public const BATCH       = 5000;
    public const MAX_BATCHES = 400;     // 2,000,000 rows a night at most
    public const MIN_DAYS    = 7;

    /**
     * @param int|null $days  null = Settings gps_history_days
     * @param int|null $now   the clock (tests)
     * @return array{on: bool, days?: int, cutoff?: string, points?: int, trips?: int, more?: bool, error?: string}
     */
    public static function prune(?int $days = null, ?int $now = null): array
    {
        $days ??= Settings::getInt('gps_history_days', 0);
        if ($days <= 0) {
            return ['on' => false];
        }
        $days    = max(self::MIN_DAYS, min(3650, $days));
        $now   ??= time();
        $cut     = date('Y-m-d H:i:s', $now - $days * 86400);
        $out     = ['on' => true, 'days' => $days, 'cutoff' => $cut, 'points' => 0, 'trips' => 0, 'more' => false];
        $batches = 0;

        try {
            /* Finished trips with fixes before the cut-off. A trip records
               fixes from at most LiveTrips::WINDOW_AHEAD_H before departure,
               so one departing over a day after the cut-off has none. */
            $trips = Database::fetchAll(
                "SELECT lt.id FROM live_trips lt
                  WHERE lt.status NOT IN ('running','paused') AND lt.departure_at < :c2
                    AND EXISTS (SELECT 1 FROM trip_locations tl WHERE tl.trip_id = lt.id AND tl.recorded_at < :c1)
                  ORDER BY lt.id",
                ['c1' => $cut, 'c2' => date('Y-m-d H:i:s', $now - ($days - 1) * 86400)]
            );
            foreach ($trips as $t) {
                $id = (int) $t['id'];
                do {
                    if ($batches >= self::MAX_BATCHES) {
                        $out['more'] = true;
                        break 2;
                    }
                    // the status is checked again in the same statement: a trip that came back to life keeps its fixes
                    $n = Database::run(
                        "DELETE FROM trip_locations
                          WHERE trip_id = :t AND recorded_at < :c
                            AND NOT EXISTS (SELECT 1 FROM live_trips WHERE id = :t2 AND status IN ('running','paused'))
                          LIMIT " . self::BATCH,
                        ['t' => $id, 'c' => $cut, 't2' => $id]
                    );
                    $out['points'] += $n;
                    $batches++;
                } while ($n >= self::BATCH);
            }

            /* Finished trips that ended before the window (their last fixes cascade). */
            do {
                if ($batches >= self::MAX_BATCHES) {
                    $out['more'] = true;
                    break;
                }
                $n = Database::run(
                    "DELETE FROM live_trips
                      WHERE status IN ('completed','cancelled') AND departure_at < :c1
                        AND ended_at IS NOT NULL AND ended_at < :c2
                      LIMIT " . self::BATCH,
                    ['c1' => $cut, 'c2' => $cut]
                );
                $out['trips'] += $n;
                $batches++;
            } while ($n >= self::BATCH);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();   // live-tracking tables missing: nothing to prune
        }
        return $out;
    }
}
