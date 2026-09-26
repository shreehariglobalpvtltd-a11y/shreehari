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
        $header = static function () use ($pdf, $W, $M, $txt, $txtR, $neDate, $neTime, $coachNe, $date, $chalaniNo, $trip, $cin, $co, &$page): float {
            $page++;
            $y = $M + 2;
            $logo = Ticket::logoFile();
            $lx = $M;
            if ($logo !== null) { $pdf->image($logo, $M, $y - 2, 44, 44); $lx = $M + 52; }
            $txt($lx, $y + 12, $co['legalNe'], 14.5, true, self::NAVY);
            $txt($lx, $y + 23.5, $co['addressNe'], 7.4, false, self::MUTE);
            $txt($lx, $y + 32.5, 'फोन : ' . $co['phone'] . '  |  सञ्चालक : ' . $co['operatorNe'] . '  |  इमेल : ' . $co['email'], 7.4, false, self::MUTE);
            $txt($lx, $y + 41.5, 'CIN : ' . $cin . '  |  वेब : ' . $co['web'], 7.4, false, self::MUTE);

            $rx = $W - $M;
            $txtR($rx, $y + 12, 'बस चलानी · यात्रु विवरण', 13, true, self::NAVY);
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
            $txt($M, $fy + 12, 'यो एस हरि ग्लोबल प्राइभेट लिमिटेडको आधिकारिक यात्रा कागजात हो। कम्पनी अभिलेखका लागि राख्नुहोस्। अधिकृत सही / छापसँग मात्र मान्य। रुट : गुजरात ⇄ रुपैडिहा (भारत–नेपाल)। CIN ' . $cin, 6.4, false, self::MUTE);
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
        $ruleRow = static function (float $y, ?array $r, int $n) use ($pdf, $M, $inner, $cx, $cw, $rowH, $txt, $txtR, $txtC, $fit, $neDigits, $money, $stopShort, $isLate, $bookedBy, $soldByNe, $seatCoach): void {
            foreach ($cw as $i => $w) { $pdf->rect($cx[$i], $y, $w, $rowH, self::LINE, false); }
            $ty = $y + 9.4;
            $txtC($cx[0], $cx[0] + $cw[0], $ty, $neDigits((string) $n), 6.8, false, self::MUTE);
            if ($r === null) { return; }
            $m = $money($r);
            $txtC($cx[1], $cx[1] + $cw[1], $ty, Seats::displayLabel((string) $r['seat_no'], $seatCoach, (string) ($r['booking_mode'] ?? 'sharing')), 8, true, self::INK);
            $name = (string) ($r['full_name'] ?? '');
            $tail = ' ' . (string) ($r['ticket_number'] ?: $r['pnr']);
            $txt($cx[2] + 3, $ty, $fit($name, 7.8, $cw[2] - 6 - 52), 7.8, false, self::INK);
            $txtR($cx[2] + $cw[2] - 3, $ty, $fit(trim($tail), 5.6, 50), 5.6, false, $isLate($r) ? self::RED : self::MUTE);
            $ph = (string) ($r['contact_phone'] ?? '');
            $txt($cx[3] + 3, $ty, $ph === '0000000000' ? '' : $fit($ph, 7.4, $cw[3] - 6), 7.4, false, self::INK);
            $txt($cx[4] + 3, $ty, $fit($stopShort((string) $r['boarding_stop']), 7.2, $cw[4] - 6), 7.2, false, self::INK);
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
        $txt($M, $y + 1, 'गुजरात ⇄ रुपैडिहा · यात्रु यात्रा अभिलेख', 8, true, self::NAVY);
        $txtR($W - $M, $y + 1, 'सिट · नाम · भुक्तानी विवरण', 8, true, self::NAVY);
        $y += 7;

        /* ---- table, paginated ---------------------------------------- */
        $limit  = $H - $M - 16;                  // the footer rule sits just below this
        $boxesH = 80;                            // summary / authorisation boxes
        $bottomReserve = 6 + $boxesH + 8;        // gap + boxes + breathing room, on the LAST page
        $y = $tableHead($y);
        $total = max(self::MIN_ROWS, count($rows));
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
        /* ---- per-counter split (owner, 26 Sep 2026: "chalani ma ni
               chuttinu paryo") ------------------------------------------
           One bus carries tickets cut at several windows, and the office
           settling the trip has to know whose money is whose. Printed only
           when a desk is actually involved — a single online-only sheet
           gains nothing from a one-line table. A Nepal desk's cash is also
           shown in the NPR it was taken in. */
        $byC = $totals['byCounter'] ?? [];
        $hasDesk = false;
        foreach ($byC as $code => $_c) { if ((string) $code !== '') { $hasDesk = true; break; } }
        if ($hasDesk && count($byC) > 0) {
            $y += $boxesH + 10;
            $blockH = 26 + count($byC) * 11;
            if ($y + $blockH + 8 > $limit) { $footer(); $pdf->newPage(); $y = $header(); }
            $pdf->roundedRect($M, $y, $inner, $blockH, 4, self::NAVY, false);
            $txt($M + 8, $y + 11, 'काउन्टर अनुसार संकलन', 7.8, true, self::NAVY);

            $cName = $M + 10;
            $cPax  = $M + $inner * 0.52;
            $cTkt  = $M + $inner * 0.66;
            $cCash = $M + $inner * 0.81;
            $cOnl  = $M + $inner - 10;

            $cy = $y + 22;
            $txtR($cPax, $cy, 'यात्रु', 6.6, false, self::MUTE);
            $txtR($cTkt, $cy, 'टिकट', 6.6, false, self::MUTE);
            $txtR($cCash, $cy, 'नगद', 6.6, false, self::MUTE);
            $txtR($cOnl, $cy, 'अनलाइन', 6.6, false, self::MUTE);
            $cy += 10;

            foreach ($byC as $c) {
                $label = ((string) ($c['code'] ?? '') !== '' ? $c['code'] . ' · ' : '') . (string) ($c['name'] ?? '');
                $txt($cName, $cy, $fit($label, 7.0, $cPax - $cName - 30), 7.0, false, self::INK);
                $txtR($cPax, $cy, $neDigits((string) (int) ($c['pax'] ?? 0)), 7.0);
                $txtR($cTkt, $cy, '₹ ' . number_format((float) ($c['ticket'] ?? 0)), 7.0);
                $txtR($cCash, $cy, '₹ ' . number_format((float) ($c['cash'] ?? 0)), 7.0);
                $txtR($cOnl, $cy, '₹ ' . number_format((float) ($c['online'] ?? 0)), 7.0);
                if ((float) ($c['localCash'] ?? 0) > 0) {
                    $txt($cName + 4, $cy + 7.4, 'नगद रू ' . number_format((float) $c['localCash']), 6.4, false, self::MUTE);
                    $cy += 4;
                }
                $cy += 11;
            }
        }

        $footer();

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
