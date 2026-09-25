<?php
/**
 * =====================================================================
 *  ChalaniPng — the bus chalani (passenger sheet) as PICTURE PAGES.
 *
 *  Owner ask, 10 Sep 2026: "chalani haru ni PNG ma herna milos, page
 *  chutta chuttai download garna milos, logo/name/details ramro sanga
 *  manage hos." The chalani already existed as a print-from-the-browser
 *  HTML sheet and as a Nepali A4 PDF. Neither travels well on a phone:
 *  a desk that wants to send the driver "your bus, page 2" over WhatsApp
 *  had to send a whole PDF the driver then had to open, scroll and zoom.
 *
 *  So the same rows are drawn here as A4-landscape-shaped PNG pages, one
 *  file per page, each downloadable on its own.
 *
 *  ONE SOURCE OF TRUTH: this class never queries. admin/manifest.php
 *  builds $trip / $rows / $totals / $fmt once, hands the SAME arrays to
 *  ChalaniPdf::send() and to this class, so the picture, the PDF and the
 *  printed HTML sheet cannot disagree about a fare, a seat or a seller.
 *
 *  HEADINGS ROMAN, NAMES AS TYPED. The column heads and the labels are
 *  English because this is the crew / border-desk copy; the signed Nepali
 *  chalani stays the PDF (?format=chalanipdf). A PASSENGER NAME, though,
 *  prints in the script it was written in, shaped by HarfBuzz (DevShape,
 *  24 Sep 2026 — GD alone does NOT form the conjuncts, whatever the 10 Sep
 *  note here said; dev_shape() remains only as the fallback).
 *  A name in a script the font has no glyphs for still falls back.
 *
 *  Reads only. Writes PNG files under UPLOAD_PATH/chalani/<date>/ and
 *  nothing else — never a booking, a seat, a fare or a settings row.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/ticket.php';      // Ticket::logoFile(), Ticket::roman()

final class ChalaniPng
{
    /** A4 landscape shape (1:1.4142) at a size that stays readable on a phone. */
    public const WIDTH  = 2000;
    public const HEIGHT = 1414;

    /** Bump whenever the drawing changes, so every cached page re-renders once. */
    public const LAYOUT_VERSION = 8;   // 8 = seat column prints the two-floor grid A1-F6 / A7-F12 (23 Sep 2026)   // 7 = chalani number carries the extra-bus slot (17 Sep 2026)   // 6 = seat column prints the LA1/UA1 row-letter grid   // 5 = the cache key covers every drawn field   // 2 = passenger names print in their own script

    private const M         = 48;     // page margin
    private const ROW_H     = 42;
    private const HEAD_H    = 44;
    private const TABLE_Y   = 412;    // first table head baseline-top
    /* The last page rules itself to ROWS_LAST whatever the data does, so a
       light bus still hands over a full ruled sheet — the minimum only has
       to stop a one-passenger trip becoming a second, blank page. (The A4
       PDF keeps 25: a printed sheet has more room under its table.) */
    private const MIN_ROWS  = 14;
    private const ROWS_PAGE = 19;     // rows on a page that carries no summary
    private const ROWS_LAST = 14;     // rows on the page that carries the summary
    private const BOXES_H   = 200;    // summary / authorisation block
    private const FOOT_Y    = self::HEIGHT - 92;   // the footer rule

    /* ------------------------------------------------------------------ */
    /*  Pagination — one rule, used by both the count and the drawing so a
     *  "page 3 of 4" link can never point at a page that was not drawn.
     * ------------------------------------------------------------------ */

    /**
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array{0:int,1:int}>  [startIndex, rowCount] per page
     */
    public static function pages(array $rows): array
    {
        $total  = max(self::MIN_ROWS, count($rows));
        $chunks = [];
        for ($i = 0; $i < $total; $i += self::ROWS_PAGE) {
            $chunks[] = [$i, min(self::ROWS_PAGE, $total - $i)];
        }
        // The LAST page also carries the payment summary and the signature
        // boxes, so it holds fewer rows. Split the tail rather than shrink
        // every page — a 25-row sheet stays two pages, not three.
        $lastIdx = count($chunks) - 1;
        if ($chunks[$lastIdx][1] > self::ROWS_LAST) {
            /* Split it in HALF, not at the limit: taking ROWS_LAST off the
               front left the summary page carrying a single passenger. Both
               halves are under ROWS_PAGE by construction and the second is at
               most half of that, so it always clears ROWS_LAST as well. */
            [$start, $count] = $chunks[$lastIdx];
            $head             = (int) ceil($count / 2);
            $chunks[$lastIdx] = [$start, $head];
            $chunks[]         = [$start + $head, $count - $head];
        }
        return $chunks;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function pageCount(array $rows): int
    {
        return count(self::pages($rows));
    }

    /* ------------------------------------------------------------------ */
    /*  Files                                                              */
    /* ------------------------------------------------------------------ */

    /** BUS-PLATE style label for the file name. */
    public static function busLabel(array $trip): string
    {
        $plate = strtoupper(trim((string) ($trip['bus_number'] ?? '')));
        $lbl   = $plate !== '' ? $plate : 'BUS-' . strtoupper((string) ($trip['route_code'] ?? 'X'));
        $lbl   = preg_replace('/[^A-Z0-9]+/', '-', $lbl) ?? $lbl;
        return trim($lbl, '-') ?: 'BUS';
    }

    public static function chalaniNo(array $trip, string $date): string
    {
        // Same form as ChallanPng::challanNo(): an extra bus (slot 2+) carries
        // its slot, so both sheets of one departure quote one number.
        $slot = (int) ($trip['slot'] ?? 1);
        return 'CH-' . str_replace('-', '', $date) . '-' . strtoupper((string) ($trip['route_code'] ?? 'X'))
             . ($slot > 1 ? '-' . $slot : '');
    }

    /**
     * What the page was drawn FROM. Any change to a seat, a name, a phone,
     * a stop, a payment or the layout produces a different file name, so a
     * cached page can never show yesterday's coach — and no cache-busting
     * table is needed to notice.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $totals
     */
    public static function fingerprint(array $trip, array $rows, string $date, array $totals, array $fmt = []): string
    {
        /* Seed on everything the page DRAWS, not on a subset of it. The first
           cut hashed twelve columns and drew sixteen: renaming an agent,
           marking a passenger boarded, adding a special-need note or changing
           a booking's status left the cached picture untouched while the
           chalani PDF and the manifest moved on (audit, 11 Sep 2026).
           $fmt is optional so a caller that only wants the row hash still
           works; when it is passed, the SELLER CODE the page prints is folded
           in as well — that string can change with no booking row changing at
           all, because it is resolved through the agent_codes settings map. */
        $seller = isset($fmt['bookedBy']) && is_callable($fmt['bookedBy'])
            ? array_map(static fn(array $r): string => (string) $fmt['bookedBy']($r), $rows)
            : [];

        $seed = [
            'v'    => self::LAYOUT_VERSION,
            'date' => $date,
            'trip' => [
                $trip['schedule_id'] ?? 0, $trip['bus_number'] ?? '', $trip['coach_type'] ?? '',
                $trip['dep_time'] ?? '', $trip['driver_name'] ?? '', $trip['driver_phone'] ?? '',
                $trip['from_city'] ?? '', $trip['to_city'] ?? '', $trip['route_code'] ?? '',
            ],
            'rows' => array_map(static fn(array $r): array => [
                $r['seat_no'] ?? '', $r['full_name'] ?? '', $r['contact_phone'] ?? '',
                $r['boarding_stop'] ?? '', $r['drop_stop'] ?? '', $r['pay_status'] ?? '',
                $r['pay_method'] ?? '', $r['total_amount'] ?? 0, $r['sold_by_admin_id'] ?? 0,
                $r['ticket_number'] ?? '', $r['pnr'] ?? '', $r['created_at'] ?? '',
                // drawn on the page, and previously unhashed:
                $r['agent_role'] ?? '', $r['agent_name'] ?? '', $r['agent_user'] ?? '',
                $r['boarded_at'] ?? '', $r['special_need'] ?? '', $r['booking_status'] ?? '',
            ], $rows),
            'seller' => $seller,
            'stops'  => (string) ($fmt['stopsLineRoman'] ?? $fmt['stopsLine'] ?? ''),
            'totals' => $totals,
        ];
        return md5((string) json_encode($seed));
    }

    /**
     * Render (or reuse) ONE page and return where it landed.
     *
     * @param array<string,mixed>            $trip   admin/manifest.php's $trip
     * @param array<int,array<string,mixed>> $rows   its $rows
     * @param array<string,mixed>            $totals its $totals
     * @param array<string,mixed>            $fmt    money / stopShort / isLate / bookedBy + stopsLine
     * @param int                            $page   1-based
     * @return array{path:string,file:string,page:int,pages:int,fresh:bool,fingerprint:string}
     */
    public static function render(array $trip, array $rows, string $date, array $totals, array $fmt, int $page): array
    {
        $chunks = self::pages($rows);
        $pages  = count($chunks);
        if ($page < 1 || $page > $pages) {
            throw new RuntimeException('The chalani has ' . $pages . ' page' . ($pages === 1 ? '' : 's') . ' — page ' . $page . ' does not exist.');
        }

        $fp   = self::fingerprint($trip, $rows, $date, $totals, $fmt);
        $dir  = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $date;
        $file = self::busLabel($trip) . '-S' . (int) ($trip['schedule_id'] ?? 0)
              . '-p' . $page . 'of' . $pages . '-' . substr($fp, 0, 8) . '.png';
        $path = $dir . '/' . $file;

        if (is_file($path) && filesize($path) > 0) {
            return ['path' => $path, 'file' => $file, 'page' => $page, 'pages' => $pages, 'fresh' => false, 'fingerprint' => $fp];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the chalani folder.');
        }

        self::draw($trip, $rows, $date, $totals, $fmt, $page, $chunks, $path);
        self::sweep($dir, self::busLabel($trip) . '-S' . (int) ($trip['schedule_id'] ?? 0) . '-', substr($fp, 0, 8));

        return ['path' => $path, 'file' => $file, 'page' => $page, 'pages' => $pages, 'fresh' => true, 'fingerprint' => $fp];
    }

    /**
     * Drop pages of THIS departure drawn from older data. Scoped by the
     * exact prefix this class writes, so it can never reach another bus's
     * files — and a failure to delete is not a reason to fail the render.
     */
    private static function sweep(string $dir, string $prefix, string $keepFp): void
    {
        foreach ((array) @glob($dir . '/' . $prefix . '*.png') as $old) {
            if (!is_string($old) || str_contains(basename($old), '-' . $keepFp . '.png')) {
                continue;
            }
            @unlink($old);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Text helpers (Roman only — see the file header)                     */
    /* ------------------------------------------------------------------ */

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    /** Draw text with y = TOP edge; faux-bold by a 1px double strike. */
    private static function t($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        if ($text === '') {
            return;
        }
        $flat = dev_shape($text);
        $box = imagettfbbox($size, 0, self::font(), $flat);
        $baseline = $y - (int) min($box[5], $box[7]);
        if (class_exists('DevShape') && DevShape::needs($text)
            && DevShape::gdText($im, $size, $x, $baseline, $col, $text, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
            return;
        }
        imagettftext($im, $size, 0, $x, $baseline, $col, self::font(), $flat);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $baseline, $col, self::font(), $flat);
        }
    }

    private static function w(float $size, string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (class_exists('DevShape') && DevShape::needs($text) && ($sw = DevShape::gdWidth($size, $text)) !== null) {
            return $sw;
        }
        $box = imagettfbbox($size, 0, self::font(), dev_shape($text));
        return (int) (max($box[2], $box[4]) - min($box[0], $box[6]));
    }

    private static function tR($im, float $size, int $xr, int $y, int $col, string $text, bool $bold = false): void
    {
        self::t($im, $size, $xr - self::w($size, $text), $y, $col, $text, $bold);
    }

    private static function tC($im, float $size, int $xl, int $xr, int $y, int $col, string $text, bool $bold = false): void
    {
        self::t($im, $size, (int) (($xl + $xr - self::w($size, $text)) / 2), $y, $col, $text, $bold);
    }

    /** Cut a line to the width available, ending in ".." when it had to shrink. */
    /**
     * Ticket::roman(), but the middle dot survives.
     *
     * roman() drops every non-ASCII byte, and "·" is the separator the whole
     * company writes with — it is in the company_counters row, in stop
     * labels and in every heading. GD renders it perfectly from Noto, so
     * stripping it only produced run-on lines ("Mehsana +91 ... Ahmedabad
     * +91 ..."). Kept by swapping it out for an ASCII token first.
     */
    private static function ro(string $text): string
    {
        return str_replace('~MD~', '·', Ticket::roman(str_replace('·', '~MD~', $text)));
    }

    private static function fit(float $size, string $text, int $maxW): string
    {
        if ($text === '' || self::w($size, $text) <= $maxW) {
            return $text;
        }
        /* mb_substr, not substr: a byte-wise cut lands in the middle of a
           three-byte Devanagari codepoint and GD then draws NOTHING at all —
           a Nepali name simply vanished from its cell (10 Sep 2026). */
        $t = $text;
        while (mb_strlen($t) > 1 && self::w($size, $t . '..') > $maxW) {
            $t = mb_substr($t, 0, mb_strlen($t) - 1);
        }
        return rtrim($t) . '..';
    }

    private static function roundRect($im, int $x1, int $y1, int $x2, int $y2, int $r, ?int $fill, ?int $border): void
    {
        if ($fill !== null) {
            imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $fill);
            imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $fill);
            foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $fill);
            }
        }
        if ($border !== null) {
            imagerectangle($im, $x1, $y1, $x2, $y2, $border);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Drawing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array{0:int,1:int}>  $chunks
     */
    private static function draw(array $trip, array $rows, string $date, array $totals, array $fmt,
                                 int $page, array $chunks, string $path): void
    {
        $W = self::WIDTH;
        $H = self::HEIGHT;
        $M = self::M;
        $inner = $W - 2 * $M;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imageantialias($im, true);
        $c = static fn(string $hex): int => imagecolorallocate(
            $im,
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2))
        );
        $white = $c('#FFFFFF'); $ink = $c('#15233B'); $muted = $c('#5B6B85'); $line = $c('#C9D3E2');
        $navy  = $c('#0B2A5B'); $navy2 = $c('#12407F'); $orange = $c('#F07C1F'); $gold = $c('#E8C15A');
        $soft  = $c('#F3F6FB'); $stripe = $c('#FAFBFE'); $red = $c('#8A1111'); $green = $c('#178A50');
        $pale  = $c('#C7D6F0'); $dim = $c('#9FB4D8');

        imagefilledrectangle($im, 0, 0, $W, $H, $white);

        $co        = Settings::company();
        $roman     = static fn(string $s): string => self::ro($s);
        $pages     = count($chunks);
        $chalaniNo = self::chalaniNo($trip, $date);

        /* ================= header band ================= */
        imagefilledrectangle($im, 0, 0, $W, 196, $navy);
        imagefilledrectangle($im, 0, 196, $W, 204, $orange);

        $tx = $M;
        $logo = Ticket::logoFile();
        if ($logo !== null) {
            $li = @imagecreatefromstring((string) file_get_contents($logo));
            if ($li !== false) {
                if (!imageistruecolor($li)) { imagepalettetotruecolor($li); }
                $lh = 104;
                $lw = (int) round(imagesx($li) * $lh / max(1, imagesy($li)));
                self::roundRect($im, $M - 6, 34, $M + $lw + 6, 34 + $lh + 12, 10, $white, null);
                imagecopyresampled($im, $li, $M, 40, 0, 0, $lw, $lh, imagesx($li), imagesy($li));
                imagedestroy($li);
                $tx = $M + $lw + 30;
            }
        }
        self::t($im, 38, $tx, 26, $white, $roman($co['legal'] !== '' ? $co['legal'] : $co['name']), true);
        self::t($im, 16, $tx, 80, $pale, $roman($co['address']), false);
        self::t($im, 16, $tx, 108, $pale,
            $roman('Phone ' . $co['phone'] . '   |   Operator ' . $co['operator'] . '   |   ' . $co['email']), false);
        self::t($im, 16, $tx, 136, $dim, $roman('CIN ' . $co['cin'] . '   |   ' . $co['web']), false);

        /* Right: what this document IS, then the three fields the office
           fills in by hand on the paper form — printed here already. */
        $rx = $W - $M;
        self::tR($im, 32, $rx, 26, $white, 'BUS CHALANI', true);
        $coach = strtoupper($roman(trim((string) ($trip['coach_type'] ?? ''))));
        self::tR($im, 16, $rx, 68, $pale,
            'PASSENGER SHEET' . ($coach !== '' ? '  ·  ' . $coach . ' COACH' : ''), false);

        $ky = 100;
        foreach ([
            ['CHALANI NO.', $chalaniNo],
            ['DATE',        strtoupper(formatDate($date, 'D, j M Y'))],
            ['BUS NO.',     strtoupper(trim((string) ($trip['bus_number'] ?? '')) ?: '-')],
        ] as [$k, $v]) {
            self::tR($im, 15, $rx - 300, $ky + 4, $dim, $k, false);
            imageline($im, $rx - 292, $ky + 28, $rx, $ky + 28, $navy2);
            self::t($im, 19, $rx - 288, $ky, $white, $roman($v), true);
            $ky += 32;
        }

        /* ================= trip strip ================= */
        $y = 224;
        $boxW = (int) (($inner - 3 * 12) / 4);
        $driver = trim($roman((string) ($trip['driver_name'] ?? ''))
            . (trim((string) ($trip['driver_phone'] ?? '')) !== '' ? '  ·  ' . $roman((string) $trip['driver_phone']) : ''));
        $depTs = ($trip['dep_time'] ?? '') !== '' ? strtotime($date . ' ' . substr((string) $trip['dep_time'], 0, 8)) : false;
        foreach ([
            ['FROM (BOARDING CITY)', $roman((string) ($trip['from_city'] ?? '-'))],
            ['TO (DESTINATION)',     $roman((string) ($trip['to_city'] ?? '-'))],
            ['DEPARTURE',            $depTs !== false ? strtoupper(date('g:i A', $depTs)) : '-'],
            ['DRIVER / CONDUCTOR',   $driver !== '' ? $driver : '-'],
        ] as $i => [$k, $v]) {
            $bx = $M + $i * ($boxW + 12);
            self::roundRect($im, $bx, $y, $bx + $boxW, $y + 82, 8, $soft, $line);
            self::t($im, 14, $bx + 16, $y + 12, $muted, $k, false);
            self::t($im, 24, $bx + 16, $y + 40, $ink, self::fit(24, $v, $boxW - 32), true);
        }
        $y += 94;

        /* Boarding points, in travel order. The PDF's own line carries the
           times in Devanagari digits, which romanise away and leave "(:)"
           behind — so the caller hands us a Roman-digit twin and we only
           fall back to scrubbing the empty brackets out of the other one. */
        $stopsLine = $roman((string) ($fmt['stopsLineRoman'] ?? $fmt['stopsLine'] ?? ''));
        $stopsLine = trim((string) preg_replace('/\(\s*:?\s*\)/', '', $stopsLine));
        if ($stopsLine !== '') {
            self::t($im, 15, $M, $y, $muted, self::fit(15, 'Boarding points: ' . $stopsLine, $inner), false);
        }
        $y += 26;

        self::t($im, 20, $M, $y, $navy, strtoupper(
            $roman((string) ($trip['from_city'] ?? 'Gujarat')) . ' - '
            . $roman((string) ($trip['to_city'] ?? 'Rupaidiha')) . '  ·  PASSENGER RECORD'
        ), true);
        self::tR($im, 16, $W - $M, $y + 3, $muted, 'PAGE ' . $page . ' OF ' . $pages, true);

        /* ================= table ================= */
        [$startIdx, $rowCount] = $chunks[$page - 1];
        /* The last page rules its full height whatever the data does, so the
           summary boxes sit under a complete table instead of floating in a
           half-empty sheet — and the crew get spare lines for the pen. */
        $drawRows = $page === $pages ? max($rowCount, self::ROWS_LAST) : $rowCount;

        $heads = ['#', 'SEAT', 'PASSENGER NAME', 'MOBILE', 'BOARDING', 'DROP',
                  'FARE Rs', 'CASH Rs', 'ONLINE Rs', 'AGENT / CODE', 'REMARKS'];
        $cw    = [52, 84, 430, 176, 196, 196, 116, 116, 130, 190];
        $cw[]  = $inner - array_sum($cw);                 // REMARKS takes the rest
        $align = ['c', 'c', 'l', 'l', 'l', 'l', 'r', 'r', 'r', 'l', 'l'];
        $cx    = [];
        $x     = $M;
        foreach ($cw as $i => $wd) { $cx[$i] = $x; $x += $wd; }

        $ty = self::TABLE_Y;
        imagefilledrectangle($im, $M, $ty, $W - $M, $ty + self::HEAD_H, $navy2);
        foreach ($heads as $i => $h) {
            $hy = $ty + 12;
            if ($align[$i] === 'c')     { self::tC($im, 16, $cx[$i], $cx[$i] + $cw[$i], $hy, $white, $h, true); }
            elseif ($align[$i] === 'r') { self::tR($im, 16, $cx[$i] + $cw[$i] - 10, $hy, $white, $h, true); }
            else                        { self::t($im, 16, $cx[$i] + 10, $hy, $white, $h, true); }
            if ($i > 0) { imageline($im, $cx[$i], $ty, $cx[$i], $ty + self::HEAD_H, $navy); }
        }
        $ty += self::HEAD_H;

        $money    = $fmt['money'];
        $stopShrt = $fmt['stopShort'];
        $isLate   = $fmt['isLate'];
        $bookedBy = $fmt['bookedBy'];

        $pTicket = 0.0; $pCash = 0.0; $pOnline = 0.0; $pPax = 0;

        for ($n = 0; $n < $drawRows; $n++) {
            $idx = $startIdx + $n;
            $r   = $rows[$idx] ?? null;
            $ry  = $ty + $n * self::ROW_H;

            imagefilledrectangle($im, $M, $ry, $W - $M, $ry + self::ROW_H, $n % 2 === 0 ? $white : $stripe);
            imageline($im, $M, $ry + self::ROW_H, $W - $M, $ry + self::ROW_H, $line);
            foreach ($cw as $i => $wd) {
                if ($i > 0) { imageline($im, $cx[$i], $ry, $cx[$i], $ry + self::ROW_H, $line); }
            }

            $vy = $ry + 10;
            self::tC($im, 15, $cx[0], $cx[0] + $cw[0], $vy + 2, $muted, (string) ($idx + 1), false);
            if ($r === null) {
                continue;              // a clean ruled blank, for the pen
            }
            $pPax++;
            $m = $money($r);
            $pTicket += $m['ticket']; $pCash += $m['cash']; $pOnline += $m['online'];
            $late = (bool) $isLate($r);

            self::tC($im, 19, $cx[1], $cx[1] + $cw[1], $vy, $ink, Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing')), true);

            /* Name — with the ticket number under it. A Devanagari name
               romanises to nothing, so the ticket number takes the line
               instead of leaving the row anonymous. */
            $name = Ticket::display((string) ($r['full_name'] ?? ''));
            $tkt  = $roman((string) (($r['ticket_number'] ?? '') ?: ($r['pnr'] ?? '')));
            if ($name === '') { $name = $tkt !== '' ? $tkt : '(name not in Latin or Devanagari)'; }
            /* Name and ticket number share ONE line. They used to be stacked,
               which was fine for Latin and collided the moment a Devanagari
               name arrived — those glyphs are taller at the same point size
               and their descenders ran through the number underneath. */
            $tktW = $tkt !== '' ? self::w(11, $tkt) + 14 : 0;
            self::t($im, 18, $cx[2] + 10, $vy + 1, $ink, self::fit(18, $name, $cw[2] - 20 - $tktW), false);
            if ($tkt !== '') {
                self::tR($im, 11, $cx[2] + $cw[2] - 8, $vy + 9, $late ? $red : $muted, $tkt, false);
            }

            $ph = (string) ($r['contact_phone'] ?? '');
            self::t($im, 18, $cx[3] + 10, $vy + 2, $ink,
                self::fit(18, $ph === '0000000000' ? '' : $roman($ph), $cw[3] - 20), false);

            self::t($im, 17, $cx[4] + 10, $vy + 3, $ink,
                self::fit(17, $roman((string) $stopShrt((string) $r['boarding_stop'])), $cw[4] - 20), false);
            self::t($im, 17, $cx[5] + 10, $vy + 3, $ink,
                self::fit(17, $roman((string) $stopShrt((string) $r['drop_stop'])), $cw[5] - 20), false);

            self::tR($im, 18, $cx[6] + $cw[6] - 10, $vy + 2, $ink, number_format($m['ticket']), false);
            if ($m['cash']   > 0) { self::tR($im, 18, $cx[7] + $cw[7] - 10, $vy + 2, $ink,   number_format($m['cash']),   false); }
            if ($m['online'] > 0) { self::tR($im, 18, $cx[8] + $cw[8] - 10, $vy + 2, $green, number_format($m['online']), false); }
            if ($m['cash'] <= 0 && $m['online'] <= 0) {
                /* Nothing in either money column means the crew still has to
                   collect. Written ACROSS both so it can never be misread as
                   an amount received online. */
                self::tC($im, 15, $cx[7], $cx[8] + $cw[8], $vy + 3, $orange, 'TO COLLECT', true);
            }

            /* WHO CUT THE TICKET — the owner's whole point (10 Sep 2026).
               A numbered agent gets an orange code chip so an agent row is
               findable at arm's length; office / online rows stay plain so
               the chips mean something. */
            $code  = strtoupper($roman((string) $bookedBy($r)));
            $who   = $roman((string) (($r['agent_name'] ?? '') ?: ($r['agent_user'] ?? '')));
            /* A 42px row holds two lines only if they are placed for it: the
               code sits high when a name follows, and centres when it is the
               whole cell. Drawn low, the name lost its bottom half to the
               next row's rule. */
            $codeY = $who !== '' ? $ry + 4 : $ry + 11;
            if (str_starts_with($code, 'SHG-')) {
                $chW = self::w(15, $code) + 22;
                self::roundRect($im, $cx[9] + 10, $codeY - 3, $cx[9] + 10 + $chW, $codeY + 22, 9, $orange, null);
                self::t($im, 15, $cx[9] + 21, $codeY + 2, $white, $code, true);
            } else {
                self::t($im, 15, $cx[9] + 10, $codeY + 1, $muted, self::fit(15, $code, $cw[9] - 20), true);
            }
            if ($who !== '') {
                self::t($im, 12, $cx[9] + 10, $ry + 25, $muted, self::fit(12, $who, $cw[9] - 20), false);
            }

            $rem = $late ? 'Booked after departure' : '';
            if ($rem === '' && !empty($r['boarded_at']))     { $rem = 'Boarded'; }
            if ($rem === '' && !empty($r['special_need']))   { $rem = $roman((string) $r['special_need']); }
            if ($rem === '' && (string) ($r['booking_status'] ?? '') === 'pending') { $rem = 'Booking pending'; }
            self::t($im, 15, $cx[10] + 10, $vy + 3, $late ? $red : $muted, self::fit(15, $rem, $cw[10] - 20), false);
        }

        /* Page subtotal — a crew counting cash counts THIS sheet, not the trip. */
        $sy = $ty + $drawRows * self::ROW_H;
        imagefilledrectangle($im, $M, $sy, $W - $M, $sy + self::ROW_H, $soft);
        imagerectangle($im, $M, $sy, $W - $M, $sy + self::ROW_H, $line);
        self::t($im, 17, $cx[2] + 10, $sy + 11, $navy,
            'PAGE ' . $page . ' SUBTOTAL  ·  ' . $pPax . ' passenger' . ($pPax === 1 ? '' : 's'), true);
        self::tR($im, 18, $cx[6] + $cw[6] - 10, $sy + 11, $navy, number_format($pTicket), true);
        self::tR($im, 18, $cx[7] + $cw[7] - 10, $sy + 11, $navy, number_format($pCash),   true);
        self::tR($im, 18, $cx[8] + $cw[8] - 10, $sy + 11, $navy, number_format($pOnline), true);
        $sy += self::ROW_H;

        /* ================= summary + signatures (last page) ================= */
        if ($page === $pages) {
            $by = self::FOOT_Y - 8 - self::BOXES_H;      // anchored to the sheet, not to the table
            $halfW = (int) (($inner - 16) / 2);

            self::roundRect($im, $M, $by, $M + $halfW, $by + self::BOXES_H, 10, $white, $navy);
            self::t($im, 18, $M + 18, $by + 12, $navy, 'PAYMENT SUMMARY  ·  WHOLE BUS', true);
            $sum = [
                ['Total passengers',        number_format((float) ($totals['passengers'] ?? 0))],
                ['Total ticket value',      'Rs ' . number_format((float) ($totals['ticket'] ?? 0))],
                ['Cash collected',          'Rs ' . number_format((float) ($totals['cash'] ?? 0))],
                ['Online / UPI received',   'Rs ' . number_format((float) ($totals['online'] ?? 0))],
                ['Discount / concession',   'Rs ' . number_format((float) ($totals['discount'] ?? 0))],
                ['NET COLLECTION',          'Rs ' . number_format((float) ($totals['net'] ?? 0))],
            ];
            $ly = $by + 44;
            foreach ($sum as $i => [$lab, $val]) {
                $last = $i === count($sum) - 1;
                self::t($im, $last ? 18 : 16, $M + 22, $ly, $last ? $navy : $muted, $lab, $last);
                imageline($im, $M + $halfW - 260, $ly + 22, $M + $halfW - 22, $ly + 22, $line);
                self::tR($im, $last ? 19 : 17, $M + $halfW - 24, $ly - 1, $last ? $navy : $ink, $val, true);
                $ly += 25;
            }

            $ax = $M + $halfW + 16;
            self::roundRect($im, $ax, $by, $W - $M, $by + self::BOXES_H, 10, $white, $navy);
            self::t($im, 18, $ax + 18, $by + 12, $navy, 'AUTHORISATION  ·  SIGNATURES', true);
            $sw = (int) (($halfW - 60) / 2);
            foreach (['Conductor', 'Driver', 'Agent / Office', 'Verified by (Manager)'] as $i => $lab) {
                $col = $i % 2; $row = intdiv($i, 2);
                $sx  = $ax + 20 + $col * ($sw + 20);
                $ly2 = $by + 100 + $row * 62;
                imageline($im, $sx, $ly2, $sx + $sw, $ly2, $line);
                self::tC($im, 15, $sx, $sx + $sw, $ly2 + 8, $muted, $lab, false);
            }
        }

        /* ================= footer ================= */
        $fy = self::FOOT_Y;
        imageline($im, $M, $fy, $W - $M, $fy, $navy);
        self::t($im, 15, $M, $fy + 12, $muted, $roman('Booking counters: ' . $co['counters']), false);
        self::tR($im, 17, $W - $M, $fy + 10, $navy, 'PAGE ' . $page . ' OF ' . $pages, true);
        self::t($im, 13, $M, $fy + 40, $muted, $roman(
            'Official travel document of ' . $co['legal'] . '. Keep for company records. Valid with authorised signature / stamp. '
            . 'Route: ' . ($trip['from_city'] ?? '') . ' - ' . ($trip['to_city'] ?? '') . ' (India-Nepal). CIN ' . $co['cin']
        ), false);
        self::t($im, 13, $M, $fy + 62, $dim,
            'English crew copy for the border desk, names as typed - the signed Nepali chalani is the PDF.'
            . '   ·   Generated ' . date('D, j M Y g:i A') . '   ·   ' . $chalaniNo . '   ·   ' . $roman($co['web']),
            false);

        /* ================= write atomically ================= */
        $tmp = $path . '.tmp';
        if (!imagepng($im, $tmp, 6)) {
            imagedestroy($im);
            @unlink($tmp);
            throw new RuntimeException('Could not write the chalani page.');
        }
        imagedestroy($im);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not place the chalani page.');
        }
    }
}
