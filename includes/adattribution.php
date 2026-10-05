<?php
/**
 * =====================================================================
 *  AdAttribution — which online booking came from a paid ad (5 Oct 2026)
 *
 *  Owner: "Google Ads bata naya customer le ticket kinda chhainan". The
 *  site could not answer the question at all: nothing remembered that a
 *  visitor arrived on an ad click, so a booking from Google Ads looked
 *  exactly like one typed in by a regular customer.
 *
 *  The browser keeps the landing source for 30 days (ShgAds in
 *  05-router.js) and sends it with the booking; this file stores it in its
 *  own side table, one row per booking. Nothing here can fail a sale - a
 *  write problem is logged and swallowed.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdAttribution
{
    private function __construct() {}

    /** Additive, idempotent; also in database/upgrade-2026-10-05-ad-attribution.sql. */
    public static function ensureTable(): void
    {
        Database::query(
            'CREATE TABLE IF NOT EXISTS booking_ad_source (
                booking_id  INT UNSIGNED NOT NULL PRIMARY KEY,
                src         VARCHAR(48)  NOT NULL,
                campaign    VARCHAR(80)  NULL,
                click_id    VARCHAR(80)  NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY ix_bas_src (src, created_at)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Clean what the browser sent. Returns null when there is nothing to keep.
     * @return array{src:string,campaign:string,click:string}|null
     */
    public static function clean(mixed $ad): ?array
    {
        if (!is_array($ad)) {
            return null;
        }
        $f = static function (mixed $v, int $len): string {
            $v = is_scalar($v) ? (string) $v : '';
            return substr((string) preg_replace('/[^A-Za-z0-9_.:\/-]/', '', $v), 0, $len);
        };
        $src = strtolower($f($ad['src'] ?? '', 48));
        if ($src === '') {
            return null;
        }
        return ['src' => $src, 'campaign' => $f($ad['campaign'] ?? '', 80), 'click' => $f($ad['click'] ?? '', 80)];
    }

    /** Remember the source of one booking. Never throws. */
    public static function record(int $bookingId, mixed $ad): void
    {
        $a = self::clean($ad);
        if ($bookingId <= 0 || $a === null) {
            return;
        }
        $sql = 'INSERT IGNORE INTO booking_ad_source (booking_id, src, campaign, click_id) VALUES (:b, :s, :c, :k)';
        $params = ['b' => $bookingId, 's' => $a['src'], 'c' => $a['campaign'] !== '' ? $a['campaign'] : null, 'k' => $a['click'] !== '' ? $a['click'] : null];
        try {
            Database::query($sql, $params);
        } catch (Throwable $e) {
            try {
                self::ensureTable();
                Database::query($sql, $params);
            } catch (Throwable $e2) {
                Logger::warning('ad attribution write failed', ['booking' => $bookingId, 'error' => $e2->getMessage()]);
            }
        }
    }

    /** @return array{src:string,campaign:?string,click_id:?string,created_at:string}|null */
    public static function forBooking(int $bookingId): ?array
    {
        try {
            $r = Database::fetch('SELECT src, campaign, click_id, created_at FROM booking_ad_source WHERE booking_id = :b', ['b' => $bookingId]);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;   // table not created yet = no ad bookings yet
        }
    }

    /** Human label for a stored source. */
    public static function label(string $src): string
    {
        return match (true) {
            $src === 'google-ads'          => 'Google Ads',
            $src === 'facebook'            => 'Facebook / Instagram',
            str_starts_with($src, 'link:') => 'Campaign link · ' . substr($src, 5),
            default                        => ucfirst($src),
        };
    }

    /**
     * Bookings per source over the last N days (admin summary).
     * @return array<int, array{src:string,n:int,confirmed:int,amount:float}>
     */
    /**
     * The same summary for an exact window of whole days (both inclusive) -
     * what a report with a date range needs. summary() below is "the last N
     * days from now", which is wrong for any window that does not end today.
     *
     * @return array<int, array{src:string,n:int,confirmed:int,amount:float}>
     */
    public static function summaryBetween(string $from, string $to): array
    {
        try {
            return array_map(static fn($r) => [
                'src' => (string) $r['src'], 'n' => (int) $r['n'], 'confirmed' => (int) $r['confirmed'], 'amount' => (float) $r['amount'],
            ], Database::fetchAll(
                'SELECT a.src, COUNT(*) n, SUM(b.status IN (\'confirmed\',\'completed\')) confirmed,
                        COALESCE(SUM(CASE WHEN b.status IN (\'confirmed\',\'completed\') THEN b.total_amount END), 0) amount
                   FROM booking_ad_source a JOIN bookings b ON b.id = a.booking_id
                  WHERE a.created_at >= :d0 AND a.created_at < :d1
                  GROUP BY a.src ORDER BY n DESC',
                ['d0' => $from, 'd1' => addDaysISO($to, 1)]
            ));
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function summary(int $days = 30): array
    {
        try {
            return array_map(static fn($r) => [
                'src' => (string) $r['src'], 'n' => (int) $r['n'], 'confirmed' => (int) $r['confirmed'], 'amount' => (float) $r['amount'],
            ], Database::fetchAll(
                'SELECT a.src, COUNT(*) n, SUM(b.status = \'confirmed\') confirmed,
                        COALESCE(SUM(CASE WHEN b.status = \'confirmed\' THEN b.total_amount END), 0) amount
                   FROM booking_ad_source a JOIN bookings b ON b.id = a.booking_id
                  WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
                  GROUP BY a.src ORDER BY n DESC',
                ['d' => max(1, $days)]
            ));
        } catch (Throwable $e) {
            return [];
        }
    }
}
