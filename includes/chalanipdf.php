<?php
/**
 * =====================================================================
 *  ChalaniPdf — the bus chalani / passenger sheet as a downloadable PDF.
 *  Landscape A4, Nepali labels, the owner's reference layout (6 Sep 2026).
 *
 *  Until now the chalani existed only as an HTML page you printed from
 *  the browser, and the separate "?format=pdf" was an ENGLISH passenger
 *  manifest from ReportPdf — which registers no Devanagari font at all.
 *
 *  This draws the same rows the HTML sheet shows, using the same
 *  per-row money rule the caller passes in, so the two can never
 *  disagree. NotoSansDevanagari.ttf is embedded as a CID font — the same
 *  mechanism the ticket PDF uses. Labels are Nepali; proper nouns, codes,
 *  phone and ticket numbers stay Roman. A name typed in Devanagari is
 *  drawn with the Devanagari face, one typed in Roman with Helvetica.
 *
 *  The sheet always carries at least 25 ruled rows: system rows first,
 *  then clean empty boxes for the pen, so it is useful before, during
 *  and after ticket entry. More passengers than fit → more pages, each
 *  with the header and column heads repeated; the summary and
 *  authorisation boxes close the last page.
 *
 *  No Bold Devanagari face ships (see Ticket::registerDevanagariFont),
 *  so "bold" Nepali renders regular — same limitation as the ticket PDF.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';      // Ticket::logoFile()

final class ChalaniPdf
{
    private const NAVY  = [18, 38, 78];
    private const INK   = [22, 35, 60];
    private const MUTE  = [59, 74, 102];
    private const LINE  = [22, 35, 60];
    private const SOFT  = [238, 242, 248];
    private const PINK  = [252, 228, 236];   // 6 Nov 2026: woman on a single sleeper
    private const ROSE  = [194, 24, 91];
    private const WHITE = [255, 255, 255];
    private const RED   = [138, 17, 17];

    private const M        = 20.0;   // page margin (pt) — the reference runs edge to edge
    private const MIN_ROWS = 25;

    /**
     * Stream the PDF and exit.
     *
     * @param array<string,mixed>    $trip    manifest.php's $trip
     * @param array<int,array>       $rows    manifest.php's $rows
     * @param array<string,mixed>    $totals  passengers, ticket, cash, online, discount, net, chalaniNo
     * @param array<string,mixed>    $fmt     money / stopShort / isLate / bookedBy closures + stopsLine
     */
    public static function send(array $trip, array $rows, string $date, array $totals, array $fmt): void
    {
        $pdf = new Pdf(841.89, 595.28);   // A4 landscape
        $W   = $pdf->width();
        $H   = $pdf->height();
        $M   = self::M;

        $ttf    = dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
        $hasDev = is_file($ttf);
        if ($hasDev) {
            $pdf->registerTTF($ttf, 'F7');
        }

        /* ---- text helpers --------------------------------------------- */
        $isDev = static fn(string $t): bool => (bool) preg_match('/\p{Devanagari}/u', $t);
        $latin = static fn(string $t): string => trim((string) preg_replace('/[^\x20-\x7E]/', '', $t));
        $needCID = static fn(string $t): bool => $isDev($t) || str_contains($t, '₹') || str_contains($t, '⇄') || str_contains($t, '·');

        /* Every y below is a BASELINE (the natural way to line text up with a
           rule or a box edge). Pdf::text()/textCID() take the TOP edge and put
           the baseline 0.8em under it, so the helpers convert once, here. Noto
           Sans Devanagari has no U+21C4 (⇄) — it drew a box — so the PDF uses
           the en dash it does have; the HTML sheet keeps ⇄ via browser fallback. */
        $norm = static fn(string $t): string => str_replace('⇄', '–', $t);
        $txt = static function (float $x, float $y, string $t, float $size, bool $bold = false, array $rgb = self::INK) use ($pdf, $hasDev, $needCID, $latin, $norm): void {
            $t = $norm($t);
            if ($t === '') { return; }
            $top = $y - $size * 0.8;
            if ($hasDev && $needCID($t)) {
                try { $pdf->textCID($x, $top, $t, $size, 'F7', $rgb); return; } catch (\Throwable $e) {}
            }
            $pdf->text($x, $top, $latin($t), $size, $bold ? 'F2' : 'F1', $rgb);
        };
        $txtR = static function (float $xr, float $y, string $t, float $size, bool $bold = false, array $rgb = self::INK) use ($pdf, $hasDev, $needCID, $latin, $norm): void {
            $t = $norm($t);
            if ($t === '') { return; }
            $top = $y - $size * 0.8;
            if ($hasDev && $needCID($t)) {
                try { $pdf->textCIDRight($xr, $top, $t, $size, 'F7', $rgb); return; } catch (\Throwable $e) {}
            }
            $pdf->textRight($xr, $top, $latin($t), $size, $bold ? 'F2' : 'F1', $rgb);
        };
        $txtC = static function (float $xl, float $xr, float $y, string $t, float $size, bool $bold = false, array $rgb = self::INK) use ($pdf, $hasDev, $needCID, $latin, $norm): void {
            $t = $norm($t);
            if ($t === '') { return; }
            $top = $y - $size * 0.8;
            if ($hasDev && $needCID($t)) {
                try { $pdf->textCIDCenter($xl, $xr, $top, $t, $size, 'F7', $rgb); return; } catch (\Throwable $e) {}
            }
            $pdf->textCenter($xl, $xr, $top, $latin($t), $size, $bold ? 'F2' : 'F1', $rgb);
        };
        $width = static function (string $t, float $size) use ($pdf, $hasDev, $needCID, $latin, $norm): float {
            $t = $norm($t);
            if ($hasDev && $needCID($t)) {
                try { return $pdf->textWidthCID($t, $size, 'F7'); } catch (\Throwable $e) {}
            }
            return $pdf->textWidth($latin($t), $size, 'F1');
        };
        $fit = static function (string $t, float $size, float $max) use ($width): string {
            if ($t === '' || $width($t, $size) <= $max) { return $t; }
            while ($t !== '' && $width($t . '…', $size) > $max) { $t = mb_substr($t, 0, mb_strlen($t) - 1); }
            return $t . '…';
        };

        /* ---- Nepali formatting --------------------------------------- */
        $neDigits = static fn(string $s): string => strtr($s, ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
        $neMonths = ['जनवरी','फेब्रुअरी','मार्च','अप्रिल','मे','जुन','जुलाई','अगस्ट','सेप्टेम्बर','अक्टोबर','नोभेम्बर','डिसेम्बर'];
        $neDays   = ['आइतबार','सोमबार','मङ्गलबार','बुधबार','बिहीबार','शुक्रबार','शनिबार'];
        $neDate = static function (string $iso) use ($neDigits, $neMonths, $neDays): string {
            $ts = strtotime($iso);
            if ($ts === false) { return $iso; }
            return $neDigits((string) (int) date('j', $ts)) . ' ' . $neMonths[(int) date('n', $ts) - 1] . ' '
                 . $neDigits(date('Y', $ts)) . ' (' . $neDays[(int) date('w', $ts)] . ')';
        };
        $neTime  = static fn(?string $t): string => ($t === null || $t === '') ? '' : $neDigits(substr((string) $t, 0, 5));
        $coachNe = static function (string $c): string {
            $c = strtolower(trim($c));
            return $c === 'sleeper' ? 'स्लिपर कोच' : ($c === 'seater' ? 'सिटर कोच' : ($c === 'semi' ? 'सेमी-स्लिपर' : ucfirst($c)));
        };
        $soldByNe = static fn(string $s): string => match (strtoupper($s)) {
            'OFFICE' => 'कार्यालय', 'COUNTER' => 'काउन्टर', 'ONLINE' => 'अनलाइन', 'AGENT' => 'एजेन्ट', default => $s,
        };

        $money     = $fmt['money'];
        $stopShort = $fmt['stopShort'];
        $isLate    = $fmt['isLate'];
        $bookedBy  = $fmt['bookedBy'];
        $stopsLine = (string) ($fmt['stopsLine'] ?? '');
        $chalaniNo = (string) ($totals['chalaniNo'] ?? '');
        /* The letterhead from ONE place (10 Sep 2026) — the address, the
           operator's name, the email, the CIN and the counter numbers were
           typed into this function, into the HTML sheet and into the PNG
           pages separately, so changing the office meant remembering three
           files. Settings::company() is the only copy now, and its
           fallbacks are exactly the strings that used to sit here. */
        $co        = Settings::company();
        $cin       = $co['cin'];

        /* ---- columns (pt) — mirror the HTML colgroup --------------------- */
        $inner = $W - 2 * $M;                                    // 801.89
        $cw    = [20, 34, 148, 72, 88, 88, 50, 50, 56, 40];       // 646
        $cw[]  = $inner - array_sum($cw);                         // कैफियत takes the rest
        $cx    = [];
        $x     = $M;
        foreach ($cw as $i => $w) { $cx[$i] = $x; $x += $w; }
        $heads  = ['#', 'सिट', 'यात्रुको नाम', 'मोबाइल नं.', 'चढ्ने', 'ओर्लिने', 'टिकट ₹', 'नगद ₹', 'अनलाइन ₹', 'प्रा.लि.', 'कैफियत'];
        $align  = ['c', 'c', 'l', 'l', 'l', 'l', 'r', 'r', 'r', 'c', 'l'];
        $rowH   = 12.5;
        $headH  = 14.0;

        /* ---- page chrome --------------------------------------------- */
        $page = 0;
        $header = static function () use ($pdf, $W, $M, $txt, $txtR, $txtC, $width, $neDate, $neTime, $coachNe, $date, $chalaniNo, $trip, $cin, $co, &$page): float {
            $page++;
            $y = $M + 2;
            /* The emblem alone, aspect-correct (it was squashed into 44x44),
               no tile — the Nepali legal name is the line beside it (9 Oct 2026). */
            $lx = $M;
            $mk = DocArt::pdfMark($pdf, $M - 2, $y - 4, 86, 48);
            if ($mk !== null) { $lx = $mk['x'] + $mk['w'] + 8; }
            $hl = [
                [$co['legalNe'], 14.5],
                [$co['addressNe'], 7.4],
                ['फोन : ' . $co['phone'] . '  |  सञ्चालक : ' . $co['operatorNe'] . '  |  इमेल : ' . $co['email'], 7.4],
                ['CIN : ' . $cin . '  |  वेब : ' . $co['web'], 7.4],
            ];
            $txt($lx, $y + 12, $hl[0][0], 14.5, true, self::NAVY);
            $txt($lx, $y + 23.5, $hl[1][0], 7.4, false, self::MUTE);
            $txt($lx, $y + 32.5, $hl[2][0], 7.4, false, self::MUTE);
            $txt($lx, $y + 41.5, $hl[3][0], 7.4, false, self::MUTE);

            $rx = $W - $M;
            $title = 'बस चलानी · यात्रु विवरण';
            $txtR($rx, $y + 12, $title, 13, true, self::NAVY);
            // both flags before the title, and the heritage art in the free middle of the letterhead
            DocArt::pdfFlags($pdf, $rx - $width($title, 13) - 8 - DocArt::pdfFlagsWidth(12, 3), $y + 1, 12, 3, 'IN', true);
            $artL = $lx + max(array_map(static fn(array $l): float => $width((string) $l[0], (float) $l[1]), $hl)) + 16;
            $artR = $rx - 270;
            if (DocArt::heritageOn() && $artR - $artL >= 90) {
                $aw = min(170.0, $artR - $artL);
                if (DocArt::pdfBand($pdf, $artR - $aw, $y - 4, $aw, 40, 0.5, 0.8)) {
                    $txtC($artR - $aw, $artR, $y + 44, 'सगरमाथा · लुम्बिनी', 6.4, false, self::MUTE);
                }
            }
            $txtR($rx, $y + 22, 'गुजरात ⇄ रुपैडिहा · ' . $coachNe((string) ($trip['coach_type'] ?? '')), 7.6, false, self::MUTE);
            // three underlined fields, right-aligned as the reference
            $kv = [['चलानी नं. :', $chalaniNo], ['मिति :', $neDate($date)], ['बस नं. :', (string) ($trip['bus_number'] ?? '')]];
            $ky = $y + 32;
            foreach ($kv as [$k, $v]) {
                $txtR($rx - 128, $ky, $k, 7.6, false, self::MUTE);
                $pdf->line($rx - 124, $ky + 1.5, $rx, $ky + 1.5, self::LINE, 0.5);
                $txt($rx - 121, $ky, $v, 7.8, true, self::INK);
                $ky += 9.5;
            }
            $by = $y + 56;
            $pdf->line($M, $by, $W - $M, $by, self::NAVY, 1.6);
            return $by + 8;
        };
        $footer = static function () use ($pdf, $W, $H, $M, $txt, $txtR, $cin, $co, &$page): void {
            $fy = $H - $M - 14;
            $pdf->line($M, $fy - 4, $W - $M, $fy - 4, self::NAVY, 1.0);
            $txt($M, $fy + 4, 'बुकिङ काउन्टर : ' . $co['counters'] . '  ·  २४×७ सहायता / WhatsApp : +' . $co['whatsapp'], 6.9, false, self::MUTE);
            $txt($M, $fy + 12, 'यो ' . ($co['legalNe'] !== '' ? $co['legalNe'] : 'एस हरि ग्लोबल प्राइभेट लिमिटेड') . 'को आधिकारिक यात्रा कागजात हो। कम्पनी अभिलेखका लागि राख्नुहोस्। अधिकृत सही / छापसँग मात्र मान्य। रुट : गुजरात ⇄ रुपैडिहा (भारत–नेपाल)। CIN ' . $cin, 6.4, false, self::MUTE);
            $txtR($W - $M, $fy + 12, $co['web'] . '  ·  पृष्ठ ' . $page, 6.6, false, self::MUTE);
        };
        $tableHead = static function (float $y) use ($pdf, $M, $inner, $cx, $cw, $heads, $align, $headH, $txt, $txtR, $txtC): float {
            $pdf->rect($M, $y, $inner, $headH, self::SOFT, true);
            foreach ($heads as $i => $h) {
                $pdf->rect($cx[$i], $y, $cw[$i], $headH, self::LINE, false);
                $ty = $y + 10.2;
                if ($align[$i] === 'c')      { $txtC($cx[$i], $cx[$i] + $cw[$i], $ty, $h, 7.6, true, self::INK); }
                elseif ($align[$i] === 'r')  { $txtR($cx[$i] + $cw[$i] - 3, $ty, $h, 7.6, true, self::INK); }
                else                         { $txt($cx[$i] + 3, $ty, $h, 7.6, true, self::INK); }
            }
            return $y + $headH;
        };
        // Coach type for the seat-label grid (LA1/UA1). booking_mode rides on
        // each row ($r); coach is per-trip, so capture it into the row closure.
        $seatCoach = (string) ($trip['coach_type'] ?? 'sleeper');
        // 6 Nov 2026: a woman on a single sleeper reads pink; the pickup carries its place number
        $womanSingle = isset($fmt['womanSingle']) && is_callable($fmt['womanSingle']) ? $fmt['womanSingle'] : static fn(array $r): bool => false;
        $placeNo     = isset($fmt['placeNo']) && is_callable($fmt['placeNo']) ? $fmt['placeNo'] : static fn(string $s): int => 0;
        $ruleRow = static function (float $y, ?array $r, int $n) use ($pdf, $M, $inner, $cx, $cw, $rowH, $txt, $txtR, $txtC, $fit, $neDigits, $money, $stopShort, $isLate, $bookedBy, $soldByNe, $seatCoach, $womanSingle, $placeNo): void {
            $ws = $r !== null && $womanSingle($r);
            if ($ws) { $pdf->rect($M, $y, $inner, $rowH, self::PINK, true); }
            foreach ($cw as $i => $w) { $pdf->rect($cx[$i], $y, $w, $rowH, self::LINE, false); }
            $ty = $y + 9.4;
            $txtC($cx[0], $cx[0] + $cw[0], $ty, $neDigits((string) $n), 6.8, false, self::MUTE);
            if ($r === null) { return; }
            $m = $money($r);
            $txtC($cx[1], $cx[1] + $cw[1], $ty, Seats::displayLabel((string) $r['seat_no'], $seatCoach, (string) ($r['booking_mode'] ?? 'sharing')), 8, true, $ws ? self::ROSE : self::INK);
            $name = (string) ($r['full_name'] ?? '');
            $tail = ' ' . (string) ($r['ticket_number'] ?: $r['pnr']);
            $txt($cx[2] + 3, $ty, $fit($name, 7.8, $cw[2] - 6 - 52), 7.8, false, self::INK);
            $txtR($cx[2] + $cw[2] - 3, $ty, $fit(trim($tail), 5.6, 50), 5.6, false, $isLate($r) ? self::RED : self::MUTE);
            $ph = (string) ($r['contact_phone'] ?? '');
            $txt($cx[3] + 3, $ty, $ph === '0000000000' ? '' : $fit($ph, 7.4, $cw[3] - 6), 7.4, false, self::INK);
            $pk  = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—';
            $pno = $placeNo($pk);
            $txt($cx[4] + 3, $ty, $fit(($pno > 0 ? $neDigits((string) $pno) . ' · ' : '') . $pk, 7.2, $cw[4] - 6), 7.2, false, self::INK);
            $txt($cx[5] + 3, $ty, $fit($stopShort((string) $r['drop_stop']), 7.2, $cw[5] - 6), 7.2, false, self::INK);
            $txtR($cx[6] + $cw[6] - 3, $ty, number_format($m['ticket']), 7.6, false, self::INK);
            if ($m['cash']   > 0) { $txtR($cx[7] + $cw[7] - 3, $ty, number_format($m['cash']),   7.6, false, self::INK); }
            if ($m['online'] > 0) { $txtR($cx[8] + $cw[8] - 3, $ty, number_format($m['online']), 7.6, false, self::INK); }
            $txt($cx[10] + 3, $ty, $fit($soldByNe($bookedBy($r)) . ($isLate($r) ? ' · बस चलेपछि' : ''), 6.8, $cw[10] - 6), 6.8, false, $isLate($r) ? self::RED : self::MUTE);
        };

        /* ==============================================================
           PAGE 1 — header, framed fields, stops line, band
           ============================================================== */
        $y = $header();

        $boxW = ($inner - 3 * 8) / 4;
        $boxes = [
            ['चढ्ने ठाउँ (FROM)', (string) ($trip['from_city'] ?? '')],
            ['गन्तव्य (TO)',       (string) ($trip['to_city'] ?? '')],
            ['प्रस्थान समय',        $neTime($trip['dep_time'] ?? '')],
            ['चालक / कन्डक्टर',    trim((string) ($trip['driver_name'] ?? '') . ((($trip['driver_phone'] ?? '') !== '') ? ' · ' . $trip['driver_phone'] : ''))],
        ];
        foreach ($boxes as $i => [$k, $v]) {
            $bx = $M + $i * ($boxW + 8);
            $pdf->roundedRect($bx, $y, $boxW, 28, 4, self::NAVY, false);
            $txt($bx + 6, $y + 9, $k, 6.2, false, self::MUTE);
            $pdf->line($bx + 6, $y + 23.5, $bx + $boxW - 6, $y + 23.5, self::MUTE, 0.4);
            $txt($bx + 6, $y + 20, $fit($v, 8.6, $boxW - 12), 8.6, true, self::INK);
        }
        $y += 34;

        if ($stopsLine !== '') {
            $line = 'चढ्ने ठाउँहरू : ' . $stopsLine . '  ·  सीमा : रुपैडिहा · जमुनाहा  ·  अगाडि : नेपालगंज, बाँके';
            $txt($M, $y, $fit($line, 6.6, $inner), 6.6, false, self::MUTE);
            $y += 9;
        }
        /* 6 Nov 2026: place-wise strip — bus + total (+ women on a single sleeper),
           then "१ Surat — ८   २ Kamrej — ३ …", wrapped inside one framed box. */
        $places = (array) ($fmt['placeList'] ?? []);
        if ($places !== []) {
            $head = 'बस : ' . ((string) ($fmt['busName'] ?? '') !== '' ? (string) $fmt['busName'] : '—')
                  . '   ·   जम्मा यात्रु : ' . $neDigits((string) count($rows))
                  . ((int) ($fmt['womenSingleCount'] ?? 0) > 0 ? '   ·   महिला सिंगल स्लिपर : ' . $neDigits((string) (int) $fmt['womenSingleCount']) : '');
            $lines = [];
            $cur = '';
            foreach ($places as $pl) {
                $tok = $neDigits((string) (int) $pl['no']) . '. ' . (string) $pl['name'] . ' — ' . $neDigits((string) (int) $pl['n']);
                $try = $cur === '' ? $tok : $cur . '     ' . $tok;
                if ($cur !== '' && $width($try, 7.4) > $inner - 16) { $lines[] = $cur; $cur = $tok; } else { $cur = $try; }
            }
            if ($cur !== '') { $lines[] = $cur; }
            $lines = array_slice($lines, 0, 3);
            $bh = 14 + 10 * count($lines);
            $pdf->roundedRect($M, $y - 2, $inner, $bh, 4, self::NAVY, false);
            $txt($M + 8, $y + 8, $fit($head, 8.2, $inner - 16), 8.2, true, self::NAVY);
            $ly = $y + 18;
            foreach ($lines as $ln) { $txt($M + 8, $ly, $ln, 7.4, false, self::INK); $ly += 10; }
            $y += $bh + 4;
        }
        $txt($M, $y + 1, 'गुजरात ⇄ रुपैडिहा · यात्रु यात्रा अभिलेख', 8, true, self::NAVY);
        $txtR($W - $M, $y + 1, 'सिट · नाम · भुक्तानी विवरण', 8, true, self::NAVY);
        $y += 7;

        /* ---- table, paginated ---------------------------------------- */
        $limit  = $H - $M - 16;                  // the footer rule sits just below this
        $boxesH = 80;                            // summary / authorisation boxes
        $bottomReserve = 6 + $boxesH + 8;        // gap + boxes + breathing room, on the LAST page
        $y = $tableHead($y);
        // the place strip takes the room of a few blank pen rows, so a short list still fits on page 1
        $total = max(self::MIN_ROWS - ($places !== [] ? (int) ceil(($bh + 4) / $rowH) : 0), count($rows));
        for ($i = 0; $i < $total; $i++) {
            $isLast = ($i === $total - 1);
            $need   = $rowH + ($isLast ? $bottomReserve : 0);
            if ($y + $need > $limit) {
                $footer();
                $pdf->newPage();
                $y = $header();
                $y = $tableHead($y);
            }
            $ruleRow($y, $rows[$i] ?? null, $i + 1);
            $y += $rowH;
        }
        $y += 6;

        /* ---- summary + authorisation ---------------------------------- */
        if ($y + $boxesH + 8 > $limit) { $footer(); $pdf->newPage(); $y = $header(); }
        $halfW = ($inner - 10) / 2;
        // Payment summary
        $pdf->roundedRect($M, $y, $halfW, $boxesH, 4, self::NAVY, false);
        $txt($M + 8, $y + 11, 'भुक्तानी सारांश', 7.8, true, self::NAVY);
        $sum = [
            ['जम्मा यात्रु',            $neDigits((string) ($totals['passengers'] ?? 0))],
            ['जम्मा टिकट रकम',          '₹ ' . number_format((float) ($totals['ticket'] ?? 0))],
            ['नगद संकलन',               '₹ ' . number_format((float) ($totals['cash'] ?? 0))],
            ['अनलाइन / UPI प्राप्ति',     '₹ ' . number_format((float) ($totals['online'] ?? 0))],
            ['छुट / सहुलियत',            '₹ ' . number_format((float) ($totals['discount'] ?? 0))],
            ['खुद जम्मा संकलन',          '₹ ' . number_format((float) ($totals['net'] ?? 0))],
        ];
        $sy = $y + 21.5;
        foreach ($sum as $k => [$lab, $val]) {
            $last = $k === count($sum) - 1;
            $txt($M + 10, $sy, $lab, 7.2, $last, $last ? self::NAVY : self::MUTE);
            $pdf->line($M + $halfW - 116, $sy + 1.5, $M + $halfW - 10, $sy + 1.5, self::LINE, $last ? 0.9 : 0.5);
            $txtR($M + $halfW - 12, $sy, $val, 7.4, $last, $last ? self::NAVY : self::INK);
            $sy += 10;
        }
        // Authorisation
        $ax = $M + $halfW + 10;
        $pdf->roundedRect($ax, $y, $halfW, $boxesH, 4, self::NAVY, false);
        $txt($ax + 8, $y + 11, 'प्रमाणीकरण · दस्तखत', 7.8, true, self::NAVY);
        $signs = ['कन्डक्टरको सही', 'चालकको सही', 'एजेन्ट / कार्यालय सही', 'प्रमाणित गर्ने (म्यानेजर)'];
        $sw = ($halfW - 30) / 2;
        foreach ($signs as $k => $lab) {
            $col = $k % 2; $row = intdiv($k, 2);
            $sx  = $ax + 10 + $col * ($sw + 10);
            $ly  = $y + 38 + $row * 24;
            $pdf->line($sx, $ly, $sx + $sw, $ly, self::LINE, 0.6);
            $txtC($sx, $sx + $sw, $ly + 8, $lab, 6.8, false, self::MUTE);
        }
        $footer();

        /* ==============================================================
           6 Nov 2026 — LAST PAGE: the seat chart with names. Lower | upper
           floor side by side; DOUBLE (2 berth) | aisle gap | SINGLE (1 berth),
           the same picture the customer books on and the print sheet shows.
           ============================================================== */
        $layout = (array) ($fmt['layout'] ?? []);
        $info   = (array) ($fmt['seatInfo'] ?? []);
        if (($layout['decks'] ?? []) !== []) {
            $pdf->newPage();
            $y = $header();
            $txt($M, $y + 4, 'सिट चार्ट · Seat chart — ' . $chalaniNo . ' · ' . (string) ($fmt['busName'] ?? '') . ' · यात्रु ' . $neDigits((string) count($rows)), 10, true, self::NAVY);
            $y += 12;
            $decks  = $layout['decks'];
            $gap    = 14.0;
            $deckW  = ($inner - $gap * (count($decks) - 1)) / max(1, count($decks));
            $aisle  = 22.0;
            $sleeper = $seatCoach === 'sleeper';
            // 6 Nov 2026: groups in DRAWN order (single column left of the aisle) — Seats::rowGroups
            $g0 = Seats::rowGroups((array) ($decks[0]['rows'][0] ?? []), $layout);
            $nL = max(1, count($g0[0][0]));
            $nR = max(1, count($g0[1][0]));
            $hs = ['single' => 'SINGLE SLEEPER · 1 BERTH', 'double' => 'DOUBLE SLEEPER · 2 BERTHS', 'seat' => ''];
            $cellW = ($deckW - 12 - $aisle) / ($nL + $nR);
            $maxRows = max(array_map(static fn(array $d): int => count($d['rows']), $decks));
            $avail = ($H - $M - 30) - ($y + 34);
            $cellH = min(46.0, max(22.0, $avail / max(1, $maxRows) - 4));
            $col = static function (?array $i): array {
                if ($i === null)        { return [[234, 247, 238], [159, 211, 174]]; }
                if (!empty($i['ws']))   { return [[248, 187, 217], [194, 24, 91]]; }
                if (!empty($i['woman'])) { return [[253, 231, 242], [224, 106, 168]]; }
                return [[220, 232, 251], [110, 149, 216]];
            };
            foreach ($decks as $di => $dk) {
                $dx = $M + $di * ($deckW + $gap);
                $dy = $y;
                $deckH = 30 + count($dk['rows']) * ($cellH + 4) + 6;
                $pdf->roundedRect($dx, $dy, $deckW, $deckH, 6, self::LINE, false);
                $txt($dx + 8, $dy + 12, (string) $dk['label'], 9, true, self::NAVY);
                if ($sleeper) {
                    $lw = $nL * $cellW;
                    $pdf->roundedRect($dx + 6, $dy + 16, $lw, 11, 3, [228, 232, 239], true);
                    $txtC($dx + 6, $dx + 6 + $lw, $dy + 24.5, $hs[$g0[0][1]], 6.6, true, self::INK);
                    $rx0 = $dx + 6 + $lw + $aisle;
                    $pdf->roundedRect($rx0, $dy + 16, $nR * $cellW, 11, 3, [228, 232, 239], true);
                    $txtC($rx0, $rx0 + $nR * $cellW, $dy + 24.5, $hs[$g0[1][1]], 6.6, true, self::INK);
                }
                $ry = $dy + 30;
                foreach ($dk['rows'] as $ri => $rw) {
                    $cells = [];
                    [$ga, $gb] = Seats::rowGroups($rw, $layout);
                    foreach ($ga[0] as $k => $bed) { $cells[] = [$dx + 6 + $k * $cellW, $bed]; }
                    foreach ($gb[0] as $k => $bed) { $cells[] = [$dx + 6 + $nL * $cellW + $aisle + $k * $cellW, $bed]; }
                    foreach ($cells as [$cxp, $bed]) {
                        $i = $info[$bed] ?? null;
                        [$fill, $edge] = $col($i);
                        $pdf->roundedRect($cxp + 1.5, $ry, $cellW - 3, $cellH, 4, $fill, true);
                        $pdf->roundedRect($cxp + 1.5, $ry, $cellW - 3, $cellH, 4, $edge, false);
                        $lbl = Seats::displayLabel((string) $bed, $seatCoach, 'sharing');
                        $txtC($cxp, $cxp + $cellW, $ry + 10, $lbl, 8, true, $i !== null && !empty($i['woman']) ? self::ROSE : self::NAVY);
                        if ($i !== null && $cellH >= 28) {
                            $first = (string) (explode(' ', trim((string) $i['name']))[0] ?? '');
                            $txtC($cxp, $cxp + $cellW, $ry + 20, $fit($first, 6.6, $cellW - 6), 6.6, false, self::INK);
                            $tag = ((int) $i['place'] > 0 ? 'ठाउँ ' . $neDigits((string) (int) $i['place']) : '') . (!empty($i['ws']) ? ' · महिला' : '');
                            $txtC($cxp, $cxp + $cellW, $ry + 29, $fit(trim($tag, ' ·'), 6, $cellW - 4), 6, true, !empty($i['ws']) ? self::ROSE : self::MUTE);
                        }
                    }
                    if ($ri === intdiv(count($dk['rows']), 2)) {
                        $txtC($dx + 6 + $nL * $cellW, $dx + 6 + $nL * $cellW + $aisle, $ry + 4, 'A', 6, true, self::MUTE);
                        $txtC($dx + 6 + $nL * $cellW, $dx + 6 + $nL * $cellW + $aisle, $ry + 11, 'I', 6, true, self::MUTE);
                        $txtC($dx + 6 + $nL * $cellW, $dx + 6 + $nL * $cellW + $aisle, $ry + 18, 'S', 6, true, self::MUTE);
                        $txtC($dx + 6 + $nL * $cellW, $dx + 6 + $nL * $cellW + $aisle, $ry + 25, 'L', 6, true, self::MUTE);
                        $txtC($dx + 6 + $nL * $cellW, $dx + 6 + $nL * $cellW + $aisle, $ry + 32, 'E', 6, true, self::MUTE);
                    }
                    $ry += $cellH + 4;
                }
            }
            // legend
            $ly = $y + 30 + $maxRows * ($cellH + 4) + 18;
            $lx = $M;
            foreach ([[null, 'खाली · Free'], [['x' => 1], 'बुक · Booked'], [['woman' => 1], 'महिला · Woman'], [['woman' => 1, 'ws' => 1], 'महिला सिंगल स्लिपर · Woman on single sleeper']] as [$sample, $lab]) {
                [$fill, $edge] = $col($sample);
                $pdf->roundedRect($lx, $ly - 7, 14, 9, 2, $fill, true);
                $pdf->roundedRect($lx, $ly - 7, 14, 9, 2, $edge, false);
                $txt($lx + 18, $ly, $lab, 7.2, false, self::INK);
                $lx += 18 + $width($lab, 7.2) + 18;
            }
            $txt($lx, $ly, 'ठाउँ N = चढ्ने ठाउँ नं.', 7.2, false, self::MUTE);
            $footer();
        }

        /* ---- send ---------------------------------------------------- */
        $bytes = $pdf->output();
        $fname = 'chalani-' . $date . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($trip['route_code'] ?? 'bus')) . '.pdf';
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, no-store');
        }
        echo $bytes;
        exit;
    }
}
