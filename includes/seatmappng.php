<?php
/**
 * =====================================================================
 *  SeatMapPng — the customer's seat picture for WhatsApp (23 Sep 2026).
 *
 *  Owner: "customer lai seat photo". A passenger asking "khali seat cha?"
 *  gets one image of the coach: every berth FREE (green), TAKEN (grey),
 *  NOT FOR SALE (dark), a women-only shared cabin marked pink — and, when a
 *  booking is being proposed, THEIR berths in orange.
 *
 *  PRIVACY: it reads the same occupancy the crew challan reads
 *  (ChallanPng::collect) but draws ONLY a status per berth. No name, no
 *  phone, no PNR, no pickup is ever drawn — a stranger holding the link
 *  learns how full the bus is, which the public seat map shows anyway.
 *
 *  LINK: WhatsApp fetches media by public URL, so the picture is served by
 *  /seatmap-image.php behind an HMAC over (schedule, highlight, expiry)
 *  keyed on APP_KEY. A link cannot be edited to another bus or another
 *  highlight, and it dies after 30 minutes.
 *
 *  Reads only. Never touches a booking, a seat or a fare.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SeatMapPng
{
    private const W       = 900;
    private const M       = 28;
    private const CELL_H  = 58;
    private const GAP     = 8;
    private const ROW_GAP = 8;
    private const AISLE   = 26;
    private const TTL     = 1800;

    /** A signed, expiring public link to one departure's picture. */
    public static function url(int $scheduleId, array $highlight = [], int $ttl = self::TTL): string
    {
        $h   = self::cleanHighlight($highlight);
        $exp = time() + max(60, $ttl);
        return appUrl('seatmap-image.php?' . http_build_query([
            's' => $scheduleId, 'h' => implode(',', $h), 'e' => $exp, 'k' => self::sign($scheduleId, $h, $exp),
        ]));
    }

    public static function verify(int $scheduleId, string $highlight, int $exp, string $sig): bool
    {
        if ($scheduleId <= 0 || $exp < time() || $exp > time() + 86400 || $sig === '') {
            return false;
        }
        $h = self::cleanHighlight($highlight === '' ? [] : explode(',', $highlight));
        return hash_equals(self::sign($scheduleId, $h, $exp), $sig);
    }

    /**
     * The numbers the WhatsApp text quotes, from the same occupancy as the picture.
     *
     * @return array{free:int, total:int, date:string, from:string, to:string, dep:string}|null
     */
    public static function summary(int $scheduleId): ?array
    {
        self::deps();
        $sched = ChallanPng::schedule($scheduleId);
        if ($sched === null) {
            return null;
        }
        $data = ChallanPng::collect($scheduleId, $sched);
        return [
            'free'  => (int) $data['totals']['empty'],
            'total' => (int) $data['totals']['beds'],
            'date'  => (string) $sched['travel_date'],
            'from'  => (string) $sched['from_city'],
            'to'    => (string) $sched['to_city'],
            'dep'   => (string) ($sched['dep_time'] ?? ''),
        ];
    }

    /** The PNG bytes. */
    public static function png(int $scheduleId, array $highlight = []): string
    {
        self::deps();
        $sched = ChallanPng::schedule($scheduleId);
        if ($sched === null) {
            throw new RuntimeException('Unknown departure.');
        }
        $data   = ChallanPng::collect($scheduleId, $sched);
        $coach  = $data['coach'];
        $layout = $data['layout'];
        $beds   = $data['beds'];

        // The proposed berths, expanded onto the physical beds they occupy.
        $mine = [];
        foreach (self::cleanHighlight($highlight) as $seat) {
            foreach (Seats::physicalSeats($seat, 'sharing', $coach) as $bed) {
                $mine[$bed] = true;
            }
        }

        $maxPerRow = 1;
        foreach ($layout['decks'] as $deck) {
            foreach ($deck['rows'] as $row) {
                $maxPerRow = max($maxPerRow, count($row['left']) + count($row['right']));
            }
        }
        $cellW = (int) floor((self::W - 2 * self::M - self::AISLE - self::GAP * ($maxPerRow - 1)) / $maxPerRow);
        $cellW = min($cellW, 190);
        $gridW = $cellW * $maxPerRow + self::AISLE + self::GAP * ($maxPerRow - 1);
        $gridX = (int) ((self::W - $gridW) / 2);

        $H = 104 + 44;
        foreach ($layout['decks'] as $deck) {
            $H += 40 + count($deck['rows']) * (self::CELL_H + self::ROW_GAP) + 14;
        }
        $H += 58;

        $im = imagecreatetruecolor(self::W, $H);
        imageantialias($im, true);
        $c = static fn(string $hex): int => imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        $white = $c('#FFFFFF'); $ink = $c('#15233B'); $muted = $c('#5B6B85'); $navy = $c('#0B2A5B');
        $navy2 = $c('#12407F'); $orange = $c('#F07C1F'); $pink = $c('#E91E63');
        $fill   = ['free' => $c('#DFF5E4'), 'taken' => $c('#E4E7EC'), 'off' => $c('#C3CAD5'), 'mine' => $c('#FFE3C8')];
        $stroke = ['free' => $c('#2E8B57'), 'taken' => $c('#B8C4D6'), 'off' => $c('#8A94A6'), 'mine' => $orange];
        imagefilledrectangle($im, 0, 0, self::W, $H, $white);

        /* header */
        imagefilledrectangle($im, 0, 0, self::W, 104, $navy);
        imagefilledrectangle($im, 0, 104, self::W, 108, $orange);
        $company = trim(ChallanPng::roman(Settings::getString('company_name', APP_NAME))) ?: 'S Hari Global';
        self::text($im, 22, self::M, 14, $white, $company . '  ·  Seat availability', true);
        $depTs = strtotime((string) $sched['travel_date'] . ' ' . (string) ($sched['dep_time'] ?? ''));
        $meta  = ChallanPng::roman((string) $sched['from_city']) . ' -> ' . ChallanPng::roman((string) $sched['to_city'])
               . '   ·   ' . formatDate((string) $sched['travel_date'], 'D, j M Y')
               . ($depTs !== false ? '   ·   departs ' . date('g:i A', $depTs) : '');
        self::text($im, 15, self::M, 52, $c('#C7D6F0'), $meta);
        $freeTxt = $data['totals']['empty'] . ' of ' . $data['totals']['beds'] . ' free';
        self::text($im, 15, self::M, 76, $c('#9FE3B5'), $freeTxt, true);
        $y = 108;

        /* legend */
        $lx = self::M; $ly = $y + 12;
        $legend = [['free', 'Free'], ['taken', 'Booked'], ['off', 'Not for sale']];
        if ($mine !== []) {
            $legend[] = ['mine', 'Your seat'];
        }
        foreach ($legend as [$k, $lbl]) {
            self::box($im, $lx, $ly, $lx + 22, $ly + 18, $fill[$k], $stroke[$k]);
            self::text($im, 13, $lx + 28, $ly, $muted, $lbl);
            $lx += 28 + self::width(13, $lbl) + 22;
        }
        imagefilledrectangle($im, $lx, $ly + 6, $lx + 22, $ly + 10, $pink);
        self::text($im, 13, $lx + 28, $ly, $muted, 'Women-only cabin');
        $y += 44;

        /* decks */
        $deckNames = ['L' => 'LOWER DECK', 'U' => 'UPPER DECK', 'M' => 'MAIN DECK'];
        foreach ($layout['decks'] as $deck) {
            $key = (string) ($deck['key'] ?? 'L');
            imagefilledrectangle($im, self::M, $y, self::W - self::M, $y + 30, $navy2);
            self::text($im, 14, self::M + 12, $y + 6, $white, $deckNames[$key] ?? strtoupper((string) ($deck['label'] ?? 'DECK')), true);
            $y += 40;
            foreach ($deck['rows'] as $row) {
                $x = $gridX;
                foreach ([$row['left'], $row['right']] as $side => $ids) {
                    foreach ($ids as $bed) {
                        $info  = $beds[$bed] ?? ['status' => 'open'];
                        $st    = (string) ($info['status'] ?? 'open');
                        $kind  = isset($mine[$bed]) ? 'mine'
                               : ($st === 'open' ? 'free' : (in_array($st, ['blocked', 'staff'], true) ? 'off' : 'taken'));
                        self::box($im, $x, $y, $x + $cellW, $y + self::CELL_H, $fill[$kind], $stroke[$kind]);
                        $unit = Seats::unitKey((string) $bed, $coach, 'sharing');
                        if (($data['unitLocks'][$unit] ?? '') === 'female_only') {
                            imagefilledrectangle($im, $x + 8, $y + 3, $x + $cellW - 8, $y + 6, $pink);
                        }
                        $lbl = Seats::displayLabel((string) $bed, $coach, 'sharing');
                        self::text($im, 15, $x + 10, $y + 10, $kind === 'taken' || $kind === 'off' ? $muted : $ink, $lbl, true);
                        $tag = match ($kind) { 'free' => 'FREE', 'mine' => 'YOURS', 'off' => '-', default => '' };
                        if ($tag !== '') {
                            self::text($im, 10, $x + 10, $y + 34, $stroke[$kind], $tag, true);
                        }
                        $x += $cellW + self::GAP;
                    }
                    if ($side === 0) {
                        $x += self::AISLE - self::GAP;
                    }
                }
                $y += self::CELL_H + self::ROW_GAP;
            }
            $y += 14;
        }

        /* footer */
        $web = Settings::getString('company_web', 'shreehariglobal.in');
        self::text($im, 13, self::M, $y + 8, $muted, 'Live from our booking register · ' . date('j M, g:i A') . ' · Book: ' . $web);
        self::text($im, 12, self::M, $y + 30, $c('#8A94A6'), 'Reply on WhatsApp with your name and how many people to book.');

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    /* ------------------------------------------------------------------ */

    private static function deps(): void
    {
        foreach (['seats', 'boarding', 'ticket', 'challanpng'] as $f) {
            require_once INCLUDE_PATH . '/' . $f . '.php';
        }
    }

    /** @return list<string> upper-case seat ids, letters/digits only, at most 12 */
    private static function cleanHighlight(array $seats): array
    {
        $out = [];
        foreach ($seats as $s) {
            $s = strtoupper(trim((string) $s));
            if ($s !== '' && preg_match('/^[A-Z0-9]{1,6}$/', $s) === 1) {
                $out[$s] = true;
            }
        }
        $out = array_keys($out);
        sort($out);
        return array_slice($out, 0, 12);
    }

    private static function sign(int $scheduleId, array $highlight, int $exp): string
    {
        return substr(hash_hmac('sha256', $scheduleId . '|' . implode(',', $highlight) . '|' . $exp, APP_KEY . ':seatmap-v1'), 0, 32);
    }

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    /** y = TOP edge of the text; faux-bold by a 1px double strike. */
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

    private static function box($im, int $x1, int $y1, int $x2, int $y2, int $fillCol, int $border): void
    {
        imagefilledrectangle($im, $x1, $y1, $x2, $y2, $fillCol);
        imagerectangle($im, $x1, $y1, $x2, $y2, $border);
    }
}
