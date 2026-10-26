<?php
/**
 * includes/bookingchannel.php — ONE answer to "how did this booking come in?"
 * (5 Oct 2026, owner's Point 11: every booking carries one source tag).
 *
 *   AGENT       an agent booked it — signed in as an agent, OR through the
 *               public flow with their agent code (the way the owner and the
 *               agents really sell). Until now those stayed source='web' and
 *               every "agent" count on the dashboard missed them.
 *   COUNTER     counter staff booked it in counter mode
 *   ADMIN       the office booked it directly
 *   WHATSAPP    it started as a WhatsApp request / the WhatsApp assistant
 *   GOOGLE_ADS  a self-booked web sale that arrived on a Google Ads click
 *   ONLINE      the customer booked on the website, no one in between
 *
 * Nothing is rewritten and no history is backfilled: the tag is DERIVED from
 * what each booking already stores (source, sold_by_admin_id, the seller's
 * role, booking_ad_source). The single stored fact is bookings.channel_tag =
 * 'WHATSAPP', because nothing else on a row can say "this came by WhatsApp";
 * the desk ticks it at sale time, the assistant's own sales set it, and it
 * can be corrected on the booking page.
 *
 * sqlExpr() is self-contained (scalar sub-queries, no joins), so the same
 * expression filters the register, groups the charts and labels a row. It
 * also works on a database where the 5 Oct migration has not run yet — the
 * missing column / table is simply left out of the expression.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class BookingChannel
{
    public const TAGS = ['ONLINE', 'AGENT', 'COUNTER', 'ADMIN', 'WHATSAPP', 'GOOGLE_ADS'];

    /** tag => [label, pill text colour, pill background, chart colour] */
    public const META = [
        'ONLINE'     => ['Online',     '#0a6b3b', '#d7f4e3', '#1FA35B'],
        'AGENT'      => ['Agent',      '#1c3b72', '#e2ecfb', '#2F5FB3'],
        'COUNTER'    => ['Counter',    '#7a4a00', '#ffe6c7', '#F08423'],
        'ADMIN'      => ['Admin',      '#5a3fb0', '#efeaff', '#7B5CD6'],
        'WHATSAPP'   => ['WhatsApp',   '#075e54', '#d9fdd3', '#25D366'],
        'GOOGLE_ADS' => ['Google Ads', '#8a1c13', '#fde3df', '#EA4335'],
    ];

    /** The query-string values the registers use (?source=…) => tag. */
    public const FILTERS = [
        'online' => 'ONLINE', 'agent' => 'AGENT', 'counter' => 'COUNTER',
        'admin'  => 'ADMIN', 'whatsapp' => 'WHATSAPP', 'ads' => 'GOOGLE_ADS',
    ];

    private static ?bool $hasTag = null;
    private static ?bool $hasAds = null;

    /** bookings.channel_tag exists (database/upgrade-2026-10-05-booking-channel.sql has run). */
    public static function hasTagColumn(): bool
    {
        if (self::$hasTag === null) {
            try {
                self::$hasTag = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'channel_tag'",
                    [], 0
                ) > 0;
            } catch (Throwable $e) {
                self::$hasTag = false;
            }
        }
        return self::$hasTag;
    }

    public static function hasAdsTable(): bool
    {
        if (self::$hasAds === null) {
            try {
                self::$hasAds = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_ad_source'",
                    [], 0
                ) > 0;
            } catch (Throwable $e) {
                self::$hasAds = false;
            }
        }
        return self::$hasAds;
    }

    /** Forget the cached schema answers (tests, and right after a migration). */
    public static function reset(): void
    {
        self::$hasTag = null;
        self::$hasAds = null;
    }

    /**
     * SQL expression giving the tag of the bookings row aliased $b. No bound
     * parameters, no joins — safe in SELECT, WHERE and GROUP BY alike.
     */
    public static function sqlExpr(string $b = 'b'): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $b)) {
            throw new InvalidArgumentException('bad alias');
        }
        $sql = 'CASE';
        if (self::hasTagColumn()) {
            $sql .= " WHEN $b.channel_tag = 'WHATSAPP' THEN 'WHATSAPP'";
        }
        $sql .= " WHEN $b.source = 'counter' THEN 'COUNTER'"
              . " WHEN $b.source = 'agent' THEN 'AGENT'"
              . " WHEN $b.source = 'admin' THEN 'ADMIN'"
              . " WHEN $b.sold_by_admin_id IS NOT NULL THEN"
              . " (CASE COALESCE((SELECT chs.role FROM admins chs WHERE chs.id = $b.sold_by_admin_id), '')"
              . " WHEN 'agent' THEN 'AGENT' WHEN 'counter' THEN 'COUNTER' WHEN '' THEN 'ONLINE' ELSE 'ADMIN' END)";
        if (self::hasAdsTable()) {
            $sql .= " WHEN EXISTS (SELECT 1 FROM booking_ad_source cha WHERE cha.booking_id = $b.id AND cha.src = 'google-ads') THEN 'GOOGLE_ADS'";
        }
        return $sql . " ELSE 'ONLINE' END";
    }

    /** ?source=agent -> 'AGENT'; anything unknown -> null (no filter). */
    public static function filterTag(string $value): ?string
    {
        return self::FILTERS[strtolower(trim($value))] ?? null;
    }

    /**
     * The registers' "Sold via" filter. Appends one clause for a known value
     * and returns the cleaned value ('' = no filter) for the form and links.
     * The tag is taken from the whitelist above, never from the request.
     *
     * @param list<string> $where
     */
    public static function applyFilter(array &$where, string $value, string $b = 'b'): string
    {
        $value = strtolower(trim($value));
        $tag   = self::filterTag($value);
        if ($tag === null) {
            return '';
        }
        $where[] = '(' . self::sqlExpr($b) . ") = '" . $tag . "'";
        return $value;
    }

    public static function label(string $tag): string
    {
        return self::META[$tag][0] ?? 'Online';
    }

    /** The coloured pill the registers show. */
    public static function badge(string $tag): string
    {
        [$label, $fg, $bg] = self::META[$tag] ?? self::META['ONLINE'];
        return '<span class="pill ch-pill ch-' . strtolower($tag) . '" style="color:' . $fg . ';background:' . $bg . '">'
             . ($tag === 'WHATSAPP' ? '💬 ' : '') . $label . '</span>';
    }

    /** The tag of one stored booking ('ONLINE' if it cannot be read). */
    public static function of(int $bookingId): string
    {
        try {
            $tag = (string) Database::scalar(
                'SELECT ' . self::sqlExpr('b') . ' FROM bookings b WHERE b.id = :id', ['id' => $bookingId], 'ONLINE'
            );
        } catch (Throwable $e) {
            $tag = 'ONLINE';
        }
        return isset(self::META[$tag]) ? $tag : 'ONLINE';
    }

    /**
     * Bookings per tag in a window of whole days (both ends inclusive).
     *
     * @param  string   $basis   'created' (date the booking was placed) or 'travel' (outbound journey date)
     * @param  int|null $sellerId only this seller's bookings (an agent's own view)
     * @return array<string, array{n:int, confirmed:int, cancelled:int, revenue:float}> every tag present, zeros included
     */
    public static function counts(string $from, string $to, string $basis = 'created', ?int $sellerId = null, float $nprPerInr = 1.6): array
    {
        $out = [];
        foreach (self::TAGS as $t) {
            $out[$t] = ['n' => 0, 'confirmed' => 0, 'cancelled' => 0, 'revenue' => 0.0];
        }
        $peg    = $nprPerInr > 0 ? $nprPerInr : 1.6;
        $params = ['d0' => $from, 'd1' => addDaysISO($to, 1)];
        if ($basis === 'travel') {
            $join = "JOIN booking_legs chl ON chl.booking_id = b.id AND chl.leg_type = 'outbound'";
            $when = 'chl.travel_date >= :d0 AND chl.travel_date < :d1';
        } else {
            $join = '';
            $when = 'b.created_at >= :d0 AND b.created_at < :d1';
        }
        $scope = '';
        if ($sellerId !== null) {
            $scope = ' AND b.sold_by_admin_id = :seller';
            $params['seller'] = $sellerId;
        }
        try {
            $rows = Database::fetchAll(
                'SELECT ' . self::sqlExpr('b') . " AS tag, COUNT(*) AS n,
                        COALESCE(SUM(b.status IN ('confirmed','completed')), 0) AS confirmed,
                        COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed')
                                          THEN (CASE WHEN b.currency = 'NPR' THEN b.total_amount / $peg ELSE b.total_amount END)
                                          ELSE 0 END), 0) AS revenue
                   FROM bookings b $join
                  WHERE $when$scope
                  GROUP BY tag",
                $params
            );
        } catch (Throwable $e) {
            Logger::warning('BookingChannel::counts failed', ['error' => $e->getMessage()]);
            return $out;
        }
        foreach ($rows as $r) {
            $t = (string) $r['tag'];
            if (isset($out[$t])) {
                $out[$t] = [
                    'n' => (int) $r['n'], 'confirmed' => (int) $r['confirmed'],
                    'cancelled' => (int) $r['cancelled'], 'revenue' => round((float) $r['revenue'], 2),
                ];
            }
        }
        return $out;
    }

    /**
     * Bookings placed per day for one tag — date => count (days with none are absent).
     *
     * @return array<string, int>
     */
    public static function daily(string $from, string $to, string $tag): array
    {
        if (!isset(self::META[$tag])) {
            return [];
        }
        try {
            $rows = Database::fetchAll(
                'SELECT DATE(b.created_at) AS d, COUNT(*) AS n FROM bookings b
                  WHERE b.created_at >= :d0 AND b.created_at < :d1 AND ' . self::sqlExpr('b') . ' = :tag
                  GROUP BY DATE(b.created_at)',
                ['d0' => $from, 'd1' => addDaysISO($to, 1), 'tag' => $tag]
            );
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['d']] = (int) $r['n'];
        }
        return $out;
    }

    /** markWhatsapp() for callers that hold the PNR (Quick Ticket, the assistant). */
    public static function markWhatsappPnr(string $pnr, string $why = ''): bool
    {
        if ($pnr === '' || !self::hasTagColumn()) {
            return false;
        }
        try {
            $id = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr], 0);
        } catch (Throwable $e) {
            return false;
        }
        return self::markWhatsapp($id, true, $why);
    }

    /**
     * Mark (or un-mark) a booking as a WhatsApp request. Returns false when the
     * column is not there yet or the booking does not exist; never throws —
     * a tag must not be able to fail a sale.
     */
    public static function markWhatsapp(int $bookingId, bool $on, string $why = ''): bool
    {
        if ($bookingId <= 0 || !self::hasTagColumn()) {
            return false;
        }
        try {
            $before = Database::fetch('SELECT id, pnr, channel_tag FROM bookings WHERE id = :id', ['id' => $bookingId]);
            if ($before === null) {
                return false;
            }
            $new = $on ? 'WHATSAPP' : null;
            if (($before['channel_tag'] ?? null) === $new) {
                return true;
            }
            Database::run('UPDATE bookings SET channel_tag = :t WHERE id = :id', ['t' => $new, 'id' => $bookingId]);
            Logger::audit('booking.channel', 'booking', (string) $before['pnr'],
                ['channel_tag' => $before['channel_tag'] ?? null], ['channel_tag' => $new],
                $why !== '' ? $why : ($on ? 'marked as a WhatsApp request' : 'WhatsApp mark removed'));
            return true;
        } catch (Throwable $e) {
            Logger::warning('BookingChannel::markWhatsapp failed', ['booking' => $bookingId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
