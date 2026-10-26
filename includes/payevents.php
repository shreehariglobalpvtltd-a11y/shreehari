<?php
/**
 * =====================================================================
 *  PayEvents — who opened the payment QR, and when (20 Sep 2026)
 *
 *  A UPI QR is paid inside the passenger's own bank app. Nothing reports
 *  back to us, so "has this person paid?" can only be answered by the UTR
 *  they submit or by the money landing in the account — never by the QR
 *  itself. Anyone promising otherwise is guessing.
 *
 *  What we CAN see is INTENT, and that is worth a lot at the desk:
 *    qr_view  — the payment image with the amount was opened
 *    pay_open — the tap-to-pay link was tapped (a UPI app was launched)
 *
 *  Meta and WhatsApp fetch the image themselves when a message is sent,
 *  so those hits are flagged is_bot and kept out of the desk's view.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class PayEvents
{
    public static function log(?int $bookingId, string $pnr, string $kind): void
    {
        try {
            $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            Database::insert('payment_events', [
                'booking_id' => ($bookingId !== null && $bookingId > 0) ? $bookingId : null,
                'pnr'        => mb_substr(strtoupper(trim($pnr)), 0, 40),
                'kind'       => $kind,
                'is_bot'     => self::looksAutomated($ua) ? 1 : 0,
                'ip'         => mb_substr((string) Security::clientIp(), 0, 45),
                'user_agent' => $ua,
            ]);
        } catch (Throwable $e) {
            // Tracking must never break the page a customer is paying on.
        }
    }

    /** Link previews and crawlers, not a passenger looking at the QR. */
    private static function looksAutomated(string $ua): bool
    {
        if (trim($ua) === '') {
            return true;
        }
        foreach (['facebookexternalhit', 'whatsapp', 'bot', 'crawler', 'spider', 'preview', 'curl', 'wget', 'python', 'okhttp'] as $needle) {
            if (stripos($ua, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Per-booking counts for the admin list.
     *
     * @param  array<int, mixed> $bookingIds
     * @return array<int, array{qr: int, link: int, last: string}>
     */
    public static function summary(array $bookingIds): array
    {
        $ids = [];
        foreach ($bookingIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        // Ints only — cast above, so this list is safe to inline.
        $rows = Database::fetchAll(
            "SELECT e.booking_id,
                    SUM(e.kind = 'qr_view')  AS qr,
                    SUM(e.kind = 'pay_open') AS link,
                    MAX(e.created_at)        AS last_seen,
                    SUBSTRING_INDEX(GROUP_CONCAT(e.user_agent ORDER BY e.id DESC SEPARATOR '||'), '||', 1) AS last_ua
               FROM payment_events e
              WHERE e.is_bot = 0
                AND e.booking_id IN (" . implode(',', $ids) . ")
              GROUP BY e.booking_id"
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['booking_id']] = [
                'qr'     => (int) $r['qr'],
                'link'   => (int) $r['link'],
                'last'   => (string) $r['last_seen'],
                'device' => self::device((string) ($r['last_ua'] ?? '')),
            ];
        }
        return $out;
    }

    /** The phone the passenger used, as the desk would say it. */
    public static function device(string $ua): string
    {
        foreach ([
            'iPhone'  => 'iPhone',
            'iPad'    => 'iPad',
            'Android' => 'Android',
            'Windows' => 'Windows',
            'Macintosh' => 'Mac',
            'Linux'   => 'Linux',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }
        return 'unknown device';
    }

    /** Full trail for one booking, newest first. */
    public static function forBooking(int $bookingId, int $limit = 20): array
    {
        return Database::fetchAll(
            'SELECT kind, is_bot, ip, user_agent, created_at
               FROM payment_events
              WHERE booking_id = :b
              ORDER BY id DESC
              LIMIT ' . max(1, min(100, $limit)),
            ['b' => $bookingId]
        );
    }
}
