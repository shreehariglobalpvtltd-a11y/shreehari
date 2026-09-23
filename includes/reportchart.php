<?php
/**
 * =====================================================================
 *  ReportChart — the office's "aaja ko report" as a picture (23 Sep 2026).
 *
 *  Owner: "graph laune — office lai report chart". An office number that
 *  asks for the report on WhatsApp gets today's figures in three lines and
 *  a bar chart of the last seven days: tickets and money per day.
 *
 *  The figures are the SAME definitions the assistant's office_day tool and
 *  Admin use: a sale is a booking created that day whose status is
 *  confirmed or completed, its money is total_amount; pending = bookings
 *  still waiting for payment; refunds = refund_amount by cancellation day.
 *
 *  Office only (the caller checks the role). The picture carries totals,
 *  never a passenger, and is served by /report-chart.php behind a 10-minute
 *  HMAC link keyed on APP_KEY.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ReportChart
{
    private const W   = 900;
    private const H   = 560;
    private const TTL = 600;

    /**
     * @return array{end: string, days: list<array{date: string, label: string, tickets: int, revenue: float}>,
     *               today: array{tickets: int, seats: int, revenue: float, pending: int, refunds: float},
     *               week: array{tickets: int, revenue: float}}
     */
    public static function data(string $endDate = '', int $days = 7): array
    {
        $end   = Security::isValidDate($endDate) ? $endDate : todayISO();
        $days  = max(1, min(31, $days));
        $start = date('Y-m-d', (int) strtotime($end . ' -' . ($days - 1) . ' days'));
        $next  = date('Y-m-d', (int) strtotime($end . ' +1 day'));

        $by = [];
        foreach (Database::fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS amt
               FROM bookings
              WHERE created_at >= :s AND created_at < :e AND status IN ('confirmed','completed')
              GROUP BY DATE(created_at)",
            ['s' => $start . ' 00:00:00', 'e' => $next . ' 00:00:00']
        ) as $r) {
            $by[(string) $r['d']] = ['n' => (int) $r['n'], 'amt' => (float) $r['amt']];
        }

        $list = [];
        $week = ['tickets' => 0, 'revenue' => 0.0];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', (int) strtotime($start . ' +' . $i . ' days'));
            $n = $by[$d]['n'] ?? 0;
            $a = $by[$d]['amt'] ?? 0.0;
            $list[] = ['date' => $d, 'label' => date('D j', (int) strtotime($d)), 'tickets' => $n, 'revenue' => $a];
            $week['tickets'] += $n;
            $week['revenue'] += $a;
        }

        $seats = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_seats s JOIN bookings b ON b.id = s.booking_id
              WHERE b.created_at >= :s AND b.created_at < :e AND b.status IN ('confirmed','completed') AND s.released_at IS NULL",
            ['s' => $end . ' 00:00:00', 'e' => $next . ' 00:00:00'], 0);
        $pending = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'", [], 0);
        $refunds = (float) Database::scalar(
            'SELECT COALESCE(SUM(refund_amount), 0) FROM bookings WHERE cancelled_at >= :s AND cancelled_at < :e',
            ['s' => $end . ' 00:00:00', 'e' => $next . ' 00:00:00'], 0);

        $last = $list[count($list) - 1];
        return [
            'end'   => $end,
            'days'  => $list,
            'today' => ['tickets' => $last['tickets'], 'seats' => $seats, 'revenue' => $last['revenue'],
                        'pending' => $pending, 'refunds' => $refunds],
            'week'  => $week,
        ];
    }

    /** Three short lines for WhatsApp, in the office's language. */
    public static function message(array $data, string $lang): string
    {
        $t = $data['today'];
        $w = $data['week'];
        $day = date('D j M', (int) strtotime($data['end']));
        return match ($lang) {
            'hi' => "📊 आज ($day): {$t['tickets']} टिकट · {$t['seats']} सीट · " . inr($t['revenue']) . "\n"
                  . "भुगतान बाकी: {$t['pending']} · रिफंड: " . inr($t['refunds']) . "\n"
                  . "7 दिन: {$w['tickets']} टिकट · " . inr($w['revenue']) . ' — chart नीचे 👇',
            'en' => "📊 Today ($day): {$t['tickets']} tickets · {$t['seats']} seats · " . inr($t['revenue']) . "\n"
                  . "Payments waiting: {$t['pending']} · Refunds: " . inr($t['refunds']) . "\n"
                  . "7 days: {$w['tickets']} tickets · " . inr($w['revenue']) . ' — chart below 👇',
            default => "📊 आज ($day): {$t['tickets']} टिकट · {$t['seats']} सिट · " . inr($t['revenue']) . "\n"
                  . "Payment बाँकी: {$t['pending']} · Refund: " . inr($t['refunds']) . "\n"
                  . "७ दिन: {$w['tickets']} टिकट · " . inr($w['revenue']) . ' — chart तल 👇',
        };
    }

    public static function url(string $endDate = '', int $ttl = self::TTL): string
    {
        $end = Security::isValidDate($endDate) ? $endDate : todayISO();
        $exp = time() + max(60, $ttl);
        return appUrl('report-chart.php?' . http_build_query(['d' => $end, 'e' => $exp, 'k' => self::sign($end, $exp)]));
    }

    public static function verify(string $end, int $exp, string $sig): bool
    {
        if (!Security::isValidDate($end) || $exp < time() || $exp > time() + 3600 || $sig === '') {
            return false;
        }
        return hash_equals(self::sign($end, $exp), $sig);
    }

    /** The PNG bytes: one bar per day, money on top, tickets under the bar. */
    public static function png(string $endDate = ''): string
    {
        $data = self::data($endDate);
        $im   = imagecreatetruecolor(self::W, self::H);
        imageantialias($im, true);
        $c = static fn(string $hex): int => imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        $white = $c('#FFFFFF'); $ink = $c('#15233B'); $muted = $c('#5B6B85'); $grid = $c('#E3E8F0');
        $navy = $c('#0B2A5B'); $bar = $c('#12407F'); $today = $c('#F07C1F');
        imagefilledrectangle($im, 0, 0, self::W, self::H, $white);

        /* header */
        imagefilledrectangle($im, 0, 0, self::W, 84, $navy);
        imagefilledrectangle($im, 0, 84, self::W, 88, $today);
        self::text($im, 20, 28, 14, $white, 'S Hari Global  ·  Last 7 days', true);
        $w = $data['week'];
        self::text($im, 14, 28, 50, $c('#C7D6F0'), $w['tickets'] . ' tickets  ·  ' . self::money($w['revenue']) . '  ·  up to ' . date('D j M Y', (int) strtotime($data['end'])));

        /* plot */
        $x0 = 70; $x1 = self::W - 30; $y0 = self::H - 110; $y1 = 130;
        $max = 0.0;
        foreach ($data['days'] as $d) { $max = max($max, $d['revenue']); }
        $max = self::niceMax($max);
        for ($g = 0; $g <= 4; $g++) {
            $gy = (int) round($y0 - ($y0 - $y1) * $g / 4);
            imageline($im, $x0, $gy, $x1, $gy, $grid);
            self::text($im, 10, 8, $gy - 9, $muted, self::short($max * $g / 4));
        }
        $n    = count($data['days']);
        $slot = ($x1 - $x0) / max(1, $n);
        $bw   = (int) min(72, $slot * 0.6);
        foreach ($data['days'] as $i => $d) {
            $cx  = (int) round($x0 + $slot * $i + $slot / 2);
            $h   = $max > 0 ? (int) round(($y0 - $y1) * $d['revenue'] / $max) : 0;
            $col = $i === $n - 1 ? $today : $bar;
            if ($h > 0) {
                imagefilledrectangle($im, $cx - (int) ($bw / 2), $y0 - $h, $cx + (int) ($bw / 2), $y0, $col);
            }
            $val = self::short($d['revenue']);
            self::text($im, 11, $cx - (int) (self::width(11, $val) / 2), $y0 - $h - 22, $ink, $val, true);
            $lbl = $d['label'];
            self::text($im, 12, $cx - (int) (self::width(12, $lbl) / 2), $y0 + 10, $ink, $lbl, $i === $n - 1);
            $tk = $d['tickets'] . ' tkt';
            self::text($im, 11, $cx - (int) (self::width(11, $tk) / 2), $y0 + 32, $muted, $tk);
        }
        imageline($im, $x0, $y0, $x1, $y0, $ink);

        /* today line */
        $t = $data['today'];
        self::text($im, 13, 28, self::H - 44, $ink,
            'Today: ' . $t['tickets'] . ' tickets · ' . $t['seats'] . ' seats · ' . self::money($t['revenue'])
            . '   ·   payments waiting ' . $t['pending'] . '   ·   refunds ' . self::money($t['refunds']), true);
        self::text($im, 11, 28, self::H - 22, $muted, 'Confirmed + completed sales by booking date · live from the register · ' . date('j M, g:i A'));

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */

    private static function sign(string $end, int $exp): string
    {
        return substr(hash_hmac('sha256', 'report|' . $end . '|' . $exp, APP_KEY . ':report-chart-v1'), 0, 32);
    }

    private static function niceMax(float $v): float
    {
        if ($v <= 0) {
            return 1000.0;
        }
        $mag = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $v) {
                return $m * $mag;
            }
        }
        return 10 * $mag;
    }

    /** Rs 750 · Rs 24.5k · Rs 1.2L */
    private static function short(float $v): string
    {
        if ($v >= 100000) { return 'Rs ' . rtrim(rtrim(number_format($v / 100000, 1), '0'), '.') . 'L'; }
        if ($v >= 1000)   { return 'Rs ' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k'; }
        return 'Rs ' . number_format($v, 0);
    }

    private static function money(float $v): string
    {
        return 'Rs ' . number_format($v, 0);
    }

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    private static function text($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        if ($text === '') {
            return;
        }
        $base = $y + (int) round($size * 1.05);
        imagettftext($im, $size, 0, $x, $base, $col, self::font(), $text);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $base, $col, self::font(), $text);
        }
    }

    private static function width(float $size, string $text): int
    {
        $b = imagettfbbox($size, 0, self::font(), $text);
        return $b === false ? (int) (mb_strlen($text) * $size * 0.6) : (int) abs($b[2] - $b[0]);
    }
}
