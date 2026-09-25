<?php
/**
 * =====================================================================
 *  SeatStatusPng — the live seat-status card (23 Sep 2026, v3 brief §9).
 *
 *  One PNG per departure: bus, date, route, the coach as coloured cells
 *  (open / sold sharing / private cabin / payment pending / held / out of
 *  service / staff) and the counts - NO passenger names or numbers, so it
 *  can go to an agent group. It reads the same data as the bus challan
 *  (ChallanPng::collect) so the two can never disagree.
 *
 *  notify() sends it on WhatsApp to the configured numbers after every
 *  confirmed / cancelled booking (EventBus listeners in events.php), behind
 *  `seat_status_wa_on` (default OFF). Delivery goes through Notify::whatsapp
 *  - free-form image message inside the 24h window, or the approved template
 *  named in `seat_status_wa_template` with the image as its header.
 *  The office can also open / download it from Admin > Bus Chalan.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/challanpng.php';
require_once INCLUDE_PATH . '/notify.php';

final class SeatStatusPng
{
    private const W = 1080;
    private const M = 48;
    private const CELL_H = 64;
    private const CELL_GAP = 10;
    private const AISLE_W = 46;
    private const ROW_GAP = 12;
    private const VERSION = 1;

    public static function enabled(): bool
    {
        return Settings::getBool('seat_status_wa_on', false);
    }

    /** Where the file lives: UPLOAD_PATH/seatstatus/YYYY-MM-DD/<BUS>-S<schedule>.png */
    public static function fileFor(array $sched): string
    {
        $date = (string) ($sched['travel_date'] ?? todayISO());
        return rtrim(UPLOAD_PATH, '/\\') . '/seatstatus/' . $date . '/' . ChallanPng::busLabel($sched) . '-S' . (int) $sched['id'] . '.png';
    }

    /**
     * Render (or reuse, when the data fingerprint is unchanged) the card.
     * @return array{path:string,file:string,fresh:bool,width:int,height:int,totals:array,bus:string,sched:array}
     */
    public static function render(int $scheduleId, bool $force = false): array
    {
        $sched = ChallanPng::schedule($scheduleId);
        if ($sched === null) {
            throw new RuntimeException('Unknown departure.');
        }
        $data = ChallanPng::collect($scheduleId, $sched);
        $path = self::fileFor($sched);
        $fp   = md5(self::VERSION . '|' . $data['fingerprint']);
        $side = $path . '.fp';

        if (!$force && is_file($path) && is_file($side) && trim((string) @file_get_contents($side)) === $fp) {
            $sz = @getimagesize($path) ?: [self::W, 0];
            return ['path' => $path, 'file' => basename($path), 'fresh' => false, 'width' => (int) $sz[0], 'height' => (int) $sz[1],
                    'totals' => $data['totals'], 'bus' => ChallanPng::busLabel($sched), 'sched' => $sched];
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the seat-status folder.');
        }
        [$w, $h] = self::draw($sched, $data, $path);
        @file_put_contents($side, $fp);
        return ['path' => $path, 'file' => basename($path), 'fresh' => true, 'width' => $w, 'height' => $h,
                'totals' => $data['totals'], 'bus' => ChallanPng::busLabel($sched), 'sched' => $sched];
    }

    /** A signed, expiring public URL the WhatsApp provider can fetch (download-chalan.php?doc=status). */
    public static function publicUrl(int $scheduleId, int $ttlSeconds = 172800): string
    {
        $exp = time() + $ttlSeconds;
        $k   = substr(Security::sign('chalan-dl|' . $scheduleId . '|status|1|' . $exp), 0, 20);
        return appUrl('download-chalan.php?' . http_build_query(['sid' => $scheduleId, 'doc' => 'status', 'page' => 1, 'exp' => $exp, 'k' => $k]));
    }

    /** Recipients: the setting's list, else the admin WhatsApp. */
    public static function recipients(): array
    {
        $raw = Settings::getString('seat_status_wa_numbers', '');
        $out = [];
        foreach (preg_split('/[,\s;]+/', $raw) ?: [] as $n) {
            $d = preg_replace('/\D+/', '', $n) ?? '';
            if (strlen($d) >= 10 && !in_array($d, $out, true)) { $out[] = $d; }
        }
        if ($out === []) {
            $d = preg_replace('/\D+/', '', Settings::getString('admin_whatsapp', Settings::officePhone())) ?? '';
            if ($d !== '') { $out[] = $d; }
        }
        return $out;
    }

    /** The caption / template body values for one card. */
    public static function caption(array $res, string $reason): string
    {
        $s = $res['sched']; $t = $res['totals'];
        $route = trim((string) ($s['from_city'] ?? '')) . ' → ' . trim((string) ($s['to_city'] ?? ''));
        $why = $reason === 'cancelled' ? '❌ Seat released' : ($reason === 'approved' ? '✅ New booking' : '🪑 Seat status');
        return $why . " · " . $res['bus'] . "\n"
            . '📅 ' . formatDate((string) $s['travel_date']) . ' · ' . substr((string) ($s['dep_time'] ?? ''), 0, 5) . "\n"
            . '🛣️ ' . $route . "\n"
            . '🟢 Sold ' . $t['booked'] . '/' . $t['beds'] . ' · ⬜ Free ' . $t['empty'] . ' · 👑 Private ' . $t['private'] . ' · ⏳ Held ' . $t['held'];
    }

    /**
     * Draw + send to every recipient. Never throws (called from an event
     * listener after the sale has committed). Returns how many sends an API
     * accepted.
     */
    public static function notify(int $scheduleId, string $reason = 'approved', ?int $bookingId = null): int
    {
        if (!self::enabled()) { return 0; }
        try {
            $res = self::render($scheduleId, false);
        } catch (Throwable $e) {
            Logger::warning('Seat-status card render failed', ['sid' => $scheduleId, 'err' => $e->getMessage()]);
            return 0;
        }
        $url  = self::publicUrl($scheduleId);
        $text = self::caption($res, $reason);
        $tpl  = trim(Settings::getString('seat_status_wa_template', ''));
        $vars = [];
        $meta = ['purpose' => 'seat_status', 'media_type' => 'image'];
        if ($tpl !== '') {
            $s = $res['sched']; $t = $res['totals'];
            $vars = [
                '1' => $res['bus'],
                '2' => formatDate((string) $s['travel_date']),
                '3' => 'Sold ' . $t['booked'] . '/' . $t['beds'] . ', free ' . $t['empty'] . ', private ' . $t['private'],
                '7' => $url,
            ];
            $meta['template_name'] = $tpl;
            $meta['template_lang'] = Settings::getString('seat_status_wa_template_lang', 'en');
        }
        $ok = 0;
        foreach (self::recipients() as $to) {
            try {
                $r = Notify::whatsapp($to, $text, $url, 'IN', $vars, $bookingId, $meta);
                if ($r === true) { $ok++; }
            } catch (Throwable $e) {
                Logger::warning('Seat-status card send failed', ['to' => $to, 'err' => $e->getMessage()]);
            }
        }
        Logger::info('Seat-status card', ['sid' => $scheduleId, 'reason' => $reason, 'sent' => $ok, 'fresh' => $res['fresh']]);
        return $ok;
    }

    /** Every departure a booking sits on (bookings has no schedule_id; legs do). */
    public static function scheduleIdsFor(int $bookingId): array
    {
        $ids = [];
        try {
            foreach (Database::fetchAll('SELECT DISTINCT schedule_id FROM booking_legs WHERE booking_id = :b', ['b' => $bookingId]) as $r) {
                $ids[] = (int) $r['schedule_id'];
            }
            if ($ids === []) {
                foreach (Database::fetchAll('SELECT DISTINCT schedule_id FROM booking_seats WHERE booking_id = :b', ['b' => $bookingId]) as $r) {
                    $ids[] = (int) $r['schedule_id'];
                }
            }
        } catch (Throwable $e) {}
        return array_values(array_filter(array_unique($ids)));
    }

    /* ------------------------------------------------------------------ */
    /*  Drawing                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array{0:int,1:int} width, height */
    private static function draw(array $sched, array $data, string $path): array
    {
        $W = self::W; $M = self::M;
        $layout = $data['layout']; $beds = $data['beds']; $tot = $data['totals'];

        $rowsTotal = 0; $decks = 0;
        foreach ($layout['decks'] as $deck) { $rowsTotal += count($deck['rows']); $decks++; }
        $H = 250 + 70 + $decks * 60 + $rowsTotal * (self::CELL_H + self::ROW_GAP) + 150 + 60;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imageantialias($im, true);
        $c = static fn(string $hex): int => imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        $white = $c('#FFFFFF'); $ink = $c('#15233B'); $muted = $c('#5B6B85'); $line = $c('#D5DEEA');
        $navy = $c('#0C306C'); $navy2 = $c('#12407F'); $orange = $c('#F07C1F'); $bg = $c('#F6F8FC');
        $stroke = ['open' => $c('#0C306C'), 'booked' => $c('#2E8B57'), 'private' => $c('#C9A227'), 'held' => $c('#E0A800'),
                   'blocked' => $c('#8A94A6'), 'staff' => $c('#2E5FA8'), 'pending' => $c('#D9822B')];
        $fill = ['open' => $white, 'booked' => $c('#2E8B57'), 'private' => $c('#F2C14E'), 'held' => $c('#FFF0BF'),
                 'blocked' => $c('#E4E7EC'), 'staff' => $c('#DDEBFF'), 'pending' => $c('#FFE1C4')];
        $txt = ['open' => $ink, 'booked' => $white, 'private' => $c('#5A4300'), 'held' => $c('#7A5B00'),
                'blocked' => $muted, 'staff' => $c('#12408C'), 'pending' => $c('#8A4B00')];
        imagefilledrectangle($im, 0, 0, $W, $H, $bg);

        /* header */
        imagefilledrectangle($im, 0, 0, $W, 170, $navy);
        imagefilledrectangle($im, 0, 170, $W, 176, $orange);
        $x = $M;
        $logo = Ticket::logoFile();
        if ($logo !== null) {
            $li = @(str_ends_with(strtolower($logo), '.png') ? imagecreatefrompng($logo) : imagecreatefromjpeg($logo));
            if ($li !== false) {
                $lh = 92; $lw = (int) round(imagesx($li) * $lh / max(1, imagesy($li)));
                imagecopyresampled($im, $li, $x, 30, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
                imagedestroy($li);
                $x += $lw + 24;
            }
        }
        $company = ChallanPng::roman(Settings::getString('company_name', APP_NAME)) ?: 'S Hari Global Pvt Ltd';
        self::text($im, 26, $x, 36, $white, self::fit(26, $company, $W - $x - $M), true);
        self::text($im, 16, $x, 78, $c('#C7D6F0'), 'LIVE SEAT STATUS  ·  ' . date('d M Y, H:i'));
        $busName = trim((string) ($sched['bus_name'] ?? ''));
        $plate   = trim((string) ($sched['bus_number'] ?? ''));
        self::text($im, 20, $x, 112, $c('#FFD9A8'), self::fit(20, trim($busName . ($plate !== '' ? '  ·  ' . $plate : '')) ?: ChallanPng::busLabel($sched), $W - $x - $M), true);

        $y = 200;
        /* the arrow is drawn (Noto Sans Devanagari has no U+2192 glyph) */
        $fromC = ChallanPng::roman((string) ($sched['from_city'] ?? ''));
        $toC   = ChallanPng::roman((string) ($sched['to_city'] ?? ''));
        self::text($im, 24, $M, $y, $ink, self::fit(24, $fromC, ($W - 2 * $M) / 2), true);
        $ax = $M + self::width(24, $fromC) + 22;
        imagesetthickness($im, 3);
        imageline($im, $ax, $y + 16, $ax + 34, $y + 16, $orange);
        imagefilledpolygon($im, [$ax + 30, $y + 8, $ax + 44, $y + 16, $ax + 30, $y + 24], $orange);
        imagesetthickness($im, 1);
        self::text($im, 24, $ax + 58, $y, $ink, self::fit(24, $toC, $W - $M - ($ax + 58)), true);
        $meta = formatDate((string) $sched['travel_date']) . '   ·   Departs ' . substr((string) ($sched['dep_time'] ?? ''), 0, 5)
              . (((string) ($sched['status'] ?? '') === 'cancelled') ? '   ·   ** TRIP CANCELLED **' : '');
        self::text($im, 17, $M, $y + 36, $muted, self::fit(17, $meta, $W - 2 * $M));
        $y += 80;

        /* legend */
        $lx = $M; $ly = $y;
        foreach ([['open', 'Free'], ['booked', 'Sold'], ['private', 'Private'], ['pending', 'Pending'], ['held', 'Held'], ['blocked', 'Out'], ['staff', 'Staff']] as [$k, $lbl]) {
            self::roundRect($im, $lx, $ly, $lx + 26, $ly + 22, 6, $fill[$k], $stroke[$k]);
            self::text($im, 14, $lx + 32, $ly + 1, $muted, $lbl);
            $lx += 32 + self::width(14, $lbl) + 22;
        }
        $y += 44;

        /* decks */
        $deckNames = ['L' => '1F  ·  LOWER DECK', 'U' => '2F  ·  UPPER DECK', 'M' => 'MAIN DECK'];
        $coach = $data['coach'];
        foreach ($layout['decks'] as $deck) {
            $key = (string) ($deck['key'] ?? 'L');
            $ids = [];
            foreach ($deck['rows'] as $row) { foreach (array_merge($row['left'], $row['right']) as $s) { $ids[] = $s; } }
            $sold = 0; foreach ($ids as $s) { if (($beds[$s]['status'] ?? '') === 'booked') { $sold++; } }
            imagefilledrectangle($im, $M, $y, $W - $M, $y + 38, $navy2);
            self::text($im, 17, $M + 14, $y + 8, $white, $deckNames[$key] ?? strtoupper((string) ($deck['label'] ?? 'DECK')), true);
            $dr = $sold . ' / ' . count($ids) . ' sold';
            self::text($im, 15, $W - $M - 14 - self::width(15, $dr), $y + 9, $c('#C7D6F0'), $dr);
            $y += 50;
            $maxCells = 1;
            foreach ($deck['rows'] as $row) { $maxCells = max($maxCells, count($row['left']) + count($row['right'])); }
            $cellW = (int) floor(($W - 2 * $M - self::AISLE_W - ($maxCells - 1) * self::CELL_GAP) / $maxCells);
            foreach ($deck['rows'] as $row) {
                $cx = $M;
                foreach ($row['left'] as $s) { self::cell($im, $s, $beds, $coach, $cx, $y, $cellW, $fill, $stroke, $txt); $cx += $cellW + self::CELL_GAP; }
                $cx += self::AISLE_W - self::CELL_GAP;
                foreach ($row['right'] as $s) { self::cell($im, $s, $beds, $coach, $cx, $y, $cellW, $fill, $stroke, $txt); $cx += $cellW + self::CELL_GAP; }
                $y += self::CELL_H + self::ROW_GAP;
            }
            $y += 10;
        }

        /* counts */
        self::roundRect($im, $M, $y, $W - $M, $y + 120, 16, $white, $line);
        $boxes = [['SOLD', $tot['booked'] . ' / ' . $tot['beds'], $stroke['booked']], ['FREE', (string) $tot['empty'], $navy],
                  ['PRIVATE', (string) $tot['private'], $stroke['private']], ['HELD', (string) $tot['held'], $stroke['held']],
                  ['OUT / STAFF', (string) ($tot['blocked'] + $tot['staff']), $muted]];
        $bw = (int) (($W - 2 * $M - 40) / count($boxes));
        $bx = $M + 20;
        foreach ($boxes as [$lbl, $val, $col]) {
            self::text($im, 13, $bx, $y + 22, $muted, $lbl, true);
            self::text($im, 34, $bx, $y + 50, $col, $val, true);
            $bx += $bw;
        }
        $y += 140;
        self::text($im, 13, $M, $y, $muted, 'Counts include sharing berths and private cabins on their physical beds. No passenger details on this card.');

        imagepng($im, $path, 6);
        imagedestroy($im);
        @chmod($path, 0664);
        return [$W, $H];
    }

    private static function cell($im, string $seat, array $beds, string $coach, int $x, int $y, int $w, array $fill, array $stroke, array $txt): void
    {
        $b = $beds[$seat] ?? ['status' => 'open', 'sale' => null];
        $st = (string) ($b['status'] ?? 'open');
        $k = $st;
        if ($st === 'booked') {
            $sale = $b['sale'] ?? null;
            if (is_array($sale) && ($sale['mode'] ?? '') === 'private') { $k = 'private'; }
            elseif (is_array($sale) && !in_array((string) ($sale['status'] ?? ''), ['confirmed', 'completed'], true)) { $k = 'pending'; }
        }
        if (!isset($fill[$k])) { $k = 'open'; }
        self::roundRect($im, $x, $y, $x + $w, $y + self::CELL_H, 10, $fill[$k], $stroke[$k]);
        $label = Seats::displayLabel($seat, $coach, 'sharing');
        $size = 17;
        self::text($im, $size, $x + (int) (($w - self::width($size, $label)) / 2), $y + 18, $txt[$k], $label, true);
        if ($k === 'private') { self::text($im, 11, $x + 8, $y + 4, $txt[$k], 'PVT', true); }
    }

    private static function font(): string { return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf'; }
    private static function width(float $size, string $text): int
    {
        if (class_exists('DevShape') && DevShape::needs($text)) {        // shaped width (integration, 24 Sep)
            $w = DevShape::gdWidth($size, $text);
            if ($w !== null) { return $w; }
        }
        $bb = @imagettfbbox($size, 0, self::font(), $text);
        return $bb ? (int) abs($bb[2] - $bb[0]) : (int) ($size * 0.6 * mb_strlen($text));
    }
    private static function fit(float $size, string $text, int $maxW): string
    {
        if (self::width($size, $text) <= $maxW) { return $text; }
        while (mb_strlen($text) > 3 && self::width($size, $text . '…') > $maxW) { $text = mb_substr($text, 0, -1); }
        return $text . '…';
    }
    private static function text($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        $baseline = $y + (int) round($size * 1.1);
        /* Devanagari through HarfBuzz like the ticket and the chalani (live
           since 24 Sep); raw imagettftext prints every conjunct broken. */
        if (class_exists('DevShape') && DevShape::needs($text)
            && DevShape::gdText($im, $size, $x, $baseline, $col, $text, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
            return;
        }
        imagettftext($im, $size, 0, $x, $baseline, $col, self::font(), $text);
        if ($bold) { imagettftext($im, $size, 0, $x + 1, $baseline, $col, self::font(), $text); }
    }
    private static function roundRect($im, int $x1, int $y1, int $x2, int $y2, int $r, int $fill, int $stroke): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $fill);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $fill);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $fill);
        }
        imagesetthickness($im, 2);
        imageline($im, $x1 + $r, $y1, $x2 - $r, $y1, $stroke); imageline($im, $x1 + $r, $y2, $x2 - $r, $y2, $stroke);
        imageline($im, $x1, $y1 + $r, $x1, $y2 - $r, $stroke); imageline($im, $x2, $y1 + $r, $x2, $y2 - $r, $stroke);
        imagearc($im, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, 180, 270, $stroke); imagearc($im, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, 270, 360, $stroke);
        imagearc($im, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, 90, 180, $stroke);  imagearc($im, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, 0, 90, $stroke);
        imagesetthickness($im, 1);
    }
}
