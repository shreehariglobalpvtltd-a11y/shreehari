<?php
/**
 * =====================================================================
 *  TicketClean — the CLEAN ticket (mp1 "tk", 25 Nov 2026, owner plan §3)
 *
 *  A trait used inside class Ticket (includes/ticket.php), so it can call
 *  the ticket's own private drawing helpers (gdText / gdQr / gdSecurityTile
 *  ...) without copying them. Two switches, both read inline:
 *
 *    ticket_clean_v2_on   (default ON)  the clean PNG layout below and the
 *                                       decluttered PDF; OFF = the old
 *                                       layout, byte for byte the same code.
 *    ticket_cash_no_payqr (default ON)  a fully paid ticket (cash or online)
 *                                       never shows a payment QR anywhere -
 *                                       only the PAID stamp ("PAID · नगद
 *                                       प्राप्त भयो / Cash received" for cash).
 *    ticket_pay_qr_by_country (default ON) while money is due, the pay QR is
 *                                       chosen by country: a trip from the
 *                                       Nepal side (or an NPR / Nepal-issued
 *                                       sale) gets the Nepal eSewa QR with the
 *                                       amount in रू, India gets the UPI QR (₹).
 *
 *  The clean PNG, top to bottom: navy header (emblem, S HARI GLOBAL PVT.
 *  LTD., ONE big national flag chosen by the bus's direction), PNR + ticket
 *  number, big FROM -> TO (the passenger's own boarding / drop place),
 *  passenger / mobile / seat / bus / date / time tiles, the payment status
 *  bar, the passenger list for a group, the note / extras strips, then the
 *  pickup location QR with that place's phone beside the pay QR (only while
 *  money is due) or the PAID stamp, the barcode + SECURITY CODE, the Nepal
 *  office (smaller), "टिकट काट्ने · Booked by", and the legal lines.
 *  Mantra, blessing, heritage photos, mandalas, the berth-mode badge, the
 *  verify QR beside the PNR and the double flag pair are not drawn here
 *  (their settings and the old layout are untouched).
 *
 *  Presentation only: nothing here reads or writes money state beyond
 *  Ticket::paymentSummary() / payWords(), which only count VERIFIED rows.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

trait TicketClean
{
    /** The clean layout (PNG + PDF + web). Off = the old layout. */
    public static function cleanV2On(): bool
    {
        return Settings::getBool('ticket_clean_v2_on', true);
    }

    /** A fully paid ticket never carries a payment QR (PNG, PDF, web, 80 mm). */
    public static function cashNoPayQrOn(): bool
    {
        return Settings::getBool('ticket_cash_no_payqr', true);
    }

    /**
     * The ONE flag the clean ticket shows: the side the bus leaves from. A
     * route that starts on the Nepal side (Rupaidiha / Nepalgunj, r7) and
     * runs into India -> 'NP'; everything else (Gujarat -> Rupaidiha) -> 'IN'.
     */
    public static function flagByDirection(array $booking): string
    {
        if (!class_exists('Boarding')) {
            require_once __DIR__ . '/boarding.php';
        }
        $from = (string) ($booking['from_city'] ?? '');
        $to   = (string) ($booking['to_city'] ?? '');
        return Boarding::isNepalTown($from) && !Boarding::isNepalTown($to) ? 'NP' : 'IN';
    }

    /**
     * Which country's pay QR a ticket that still owes money gets: 'NP' for a
     * sale frozen in NPR (always, as before), and - with ticket_pay_qr_by_country
     * on - for a Nepal-issued sale or a trip from the Nepal side; else 'IN'.
     */
    public static function payCountry(array $booking): string
    {
        if (self::frozenNpr($booking) > 0) {
            return 'NP';
        }
        if (!Settings::getBool('ticket_pay_qr_by_country', true)) {
            return 'IN';
        }
        if ((self::companyOf($booking)['entity'] ?? 'IN') === 'NP') {
            return 'NP';
        }
        return self::flagByDirection($booking);
    }

    /**
     * May a STATIC pay QR - the office's Nepal eSewa / Fonepay image, which records
     * nothing - stand in for the live pay link (pay.php)? The same test as rawUpiQrOk()
     * without the UPI switch: a CONFIRMED booking, not pay-at-boarding (is_cod), with no
     * payment proof waiting. A pay-at-boarding ticket keeps pay.php (its UTR box records
     * the CodPayClaim, so the crew does not collect the cash again) and a pending online
     * booking keeps it too (its proof box holds the expiry clock) - review of mp1 tk.
     */
    public static function staticPayQrOk(array $booking): bool
    {
        if ((string) ($booking['status'] ?? '') !== 'confirmed' || (int) ($booking['is_cod'] ?? 0) === 1) {
            return false;
        }
        return !self::proofWaiting($booking);
    }

    /**
     * What the fare saved, for the clean PNG's saving strip (the old layout draws the same
     * words in its fare band): the route's REAL normal fare struck through + the saving
     * (nf1, never on an NPR sale), else every discount with its percentage and source
     * ("SAVED ₹200 (9.1%) · काउन्टर छुट", P4-5f). kind '' = nothing saved, no strip.
     *
     * @return array{kind:string, normal:string, save:string, text:string}
     */
    public static function saveLine(array $booking): array
    {
        $nf = self::isNprSale($booking) ? null : NormalFare::forBooking($booking);
        if ($nf !== null) {
            $save = 'बचत SAVED ₹' . number_format((float) $nf['save']);
            return ['kind' => 'nf', 'normal' => '₹' . number_format((float) $nf['normal']), 'save' => $save,
                'text' => 'सामान्य भाडा ₹' . number_format((float) $nf['normal']) . '  ·  ' . $save];
        }
        $cut = self::discountTotal($booking);
        if ($cut <= 0) {
            return ['kind' => '', 'normal' => '', 'save' => '', 'text' => ''];
        }
        $src  = self::discountSource($booking);
        $amt  = self::isNprSale($booking) ? self::money($booking, $cut, false) : '₹' . indianNumber($cut);   // an NPR sale says NPR (TK-5)
        $text = 'तपाईंले बचाउनुभयो  ·  SAVED ' . $amt . self::discountPctTxt($booking, $cut)
            . ($src !== '' ? '  ·  ' . $src : '');
        return ['kind' => 'disc', 'normal' => '', 'save' => $text, 'text' => $text];
    }

    /**
     * NPR for an INR amount, for the pay QR's words: the rate frozen on the sale
     * when there is one (nprOf), else today's npr_per_inr. Words only - the eSewa
     * QR image carries no amount and the office still approves every payment.
     */
    public static function nprDue(array $booking, float $inr): float
    {
        if (self::frozenNpr($booking) > 0) {
            return self::nprOf($booking, $inr);
        }
        $rate = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
        return round(max(0.0, $inr) * max(0.0, $rate));
    }

    /**
     * The PAID stamp words (mp1): cash -> "PAID · नगद प्राप्त भयो / Cash received",
     * online -> "PAID · अनलाइन भुक्तानी भयो / Paid online (UPI)", a pay-at-boarding
     * ticket with money due -> बसमा तिर्ने, anything else -> तिर्न बाँकी · PAYMENT DUE.
     * From payWords(), so only VERIFIED money counts as paid.
     *
     * @return array{state:string, way:string, ne:string, en:string, text:string}
     */
    public static function paidStamp(array $booking): array
    {
        $w = self::payWords($booking);
        if ($w['state'] === 'paid') {
            if ($w['way'] === 'cash') {
                return ['state' => 'paid', 'way' => 'cash', 'ne' => 'नगद प्राप्त भयो', 'en' => 'Cash received',
                    'text' => 'PAID · नगद प्राप्त भयो / Cash received'];
            }
            if ($w['way'] === 'online') {
                $en = 'Paid online' . ($w['detail'] !== '' ? ' (' . $w['detail'] . ')' : '');
                return ['state' => 'paid', 'way' => 'online', 'ne' => 'अनलाइन भुक्तानी भयो', 'en' => $en,
                    'text' => 'PAID · अनलाइन भुक्तानी भयो / ' . $en];
            }
            return ['state' => 'paid', 'way' => '', 'ne' => 'भुक्तानी भयो', 'en' => 'Paid', 'text' => 'PAID · भुक्तानी भयो'];
        }
        if ($w['state'] === 'board') {
            return ['state' => 'board', 'way' => '', 'ne' => 'बसमा तिर्ने', 'en' => 'Pay on boarding', 'text' => 'बसमा तिर्ने · PAY ON BOARDING'];
        }
        return ['state' => 'due', 'way' => '', 'ne' => 'तिर्न बाँकी', 'en' => 'Payment due', 'text' => 'तिर्न बाँकी · PAYMENT DUE'];
    }

    /**
     * paidStamp() + bookedByText() for a booking id (the 80 mm receipt, which holds only
     * the bare bookings row): the same loaded row the PNG / PDF draw from.
     *
     * @return array{stamp:array{state:string, way:string, ne:string, en:string, text:string}, bookedBy:string}
     */
    public static function stampFor(int $bookingId): array
    {
        $b = self::loadBooking($bookingId);
        return ['stamp' => self::paidStamp($b), 'bookedBy' => self::bookedByText($b)];
    }

    /**
     * The phone of the place the passenger boards at: the company location in
     * that town (Admin -> Locations), else the desk's / issuing company's own
     * number (the old support line). Latin, first number only when several.
     */
    public static function pickupPhone(array $booking): string
    {
        $label = self::stopForPrint((string) ($booking['boarding_stop'] ?? ''));
        $phone = '';
        if ($label !== '') {
            try {
                if (!class_exists('CompanyLocations')) {
                    require_once __DIR__ . '/companylocations.php';
                }
                $phone = CompanyLocations::phoneForTown($label);
            } catch (Throwable) {
                $phone = '';
            }
        }
        if ($phone === '') {
            $co    = self::companyOf($booking);
            $phone = (string) (($co['desk']['phone'] ?? '') !== '' ? $co['desk']['phone'] : ($co['phone'] ?? ''));
        }
        return self::latin(trim((string) (preg_split('#\s*/\s*#', $phone)[0] ?? $phone)));
    }

    /**
     * "Ram Agent (SHG-0012) · 98XXXXXXXX · Nepalgunj" - who cut the ticket, their
     * phone and their town (issuedBy()). An online sale says "Online · <website>".
     * An office / counter / channel sale (issuedBy() keeps no phone for those) prints
     * the DESK's own number - the desk that cut it, else the desk on the sale, else the
     * issuing company's line - never the clerk's personal mobile.
     */
    public static function bookedByText(array $booking): string
    {
        $iss = self::issuedBy($booking);
        if ($iss['kind'] === 'online') {
            $co = self::companyOf($booking);
            return implode(' · ', array_filter(['Online', self::latin((string) ($co['web'] ?? ''))], 'strlen'));
        }
        $name = self::display((string) $iss['name']);
        if ($iss['kind'] === 'agent' && $iss['code'] !== '' && $iss['code'] !== 'AGENT') {
            $name .= ' (' . self::latinUpper((string) $iss['code']) . ')';
        }
        $phone = (string) $iss['phone'];
        if ($phone === '' && $iss['kind'] === 'office') {
            $desk = '';
            try {
                $lc = (string) ($iss['locCode'] ?? '');
                if ($lc !== '' && class_exists('CounterDesk')) {
                    $desk = trim((string) (CounterDesk::get($lc)['phone'] ?? ''));
                }
            } catch (Throwable) {
                $desk = '';
            }
            if ($desk === '') {
                $co   = self::companyOf($booking);
                $desk = trim((string) (($co['desk']['phone'] ?? '') !== '' ? $co['desk']['phone'] : ($co['phone'] ?? '')));
            }
            $phone = self::latin(trim((string) (preg_split('#\s*/\s*#', $desk)[0] ?? $desk)));
        }
        $town = self::latin(trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($iss['location'] ?? ''))));
        return implode(' · ', array_values(array_filter([$name, self::display($phone), $town], static fn(string $s): bool => $s !== '')));
    }

    /* ----------------------------------------------------------------
     *  Clean PNG
     * -------------------------------------------------------------- */

    /** Largest Poppins size in [$max..$min] that fits $maxW, the text clamped with … at $min. */
    private static function v2Fit(int $max, int $min, string $text, int $maxW, string $wt = 'Bold'): array
    {
        for ($s = $max; $s >= $min; $s--) {
            if (self::gdWidthP($s, $text, $wt) <= $maxW) {
                return [$s, $text];
            }
        }
        while ($text !== '' && self::gdWidthP($min, $text, $wt) > $maxW) {
            $text = rtrim(mb_substr($text, 0, mb_strlen($text) - 2)) . '…';
        }
        return [$min, $text];
    }

    /** A place name on at most two lines at the biggest size that fits. @return array{0:int,1:list<string>} */
    private static function v2Wrap(string $text, int $maxW, int $max, int $min): array
    {
        for ($s = $max; $s >= $min; $s -= 2) {
            if (self::gdWidthP($s, $text, 'Bold') <= $maxW) {
                return [$s, [$text]];
            }
        }
        $words = preg_split('/\s+/u', trim($text)) ?: [$text];
        for ($s = $max - 6; $s >= $min; $s -= 2) {
            $l1   = '';
            $used = 0;
            foreach ($words as $wd) {
                $try = $l1 === '' ? $wd : $l1 . ' ' . $wd;
                if ($l1 !== '' && self::gdWidthP($s, $try, 'Bold') > $maxW) {
                    break;
                }
                $l1 = $try;
                $used++;
            }
            if (self::gdWidthP($s, $l1, 'Bold') > $maxW) {
                continue;
            }
            $l2 = trim(implode(' ', array_slice($words, $used)));
            if ($l2 === '' || self::gdWidthP($s, $l2, 'Bold') <= $maxW) {
                return [$s, array_values(array_filter([$l1, $l2], 'strlen'))];
            }
        }
        [$s, $t] = self::v2Fit($min, $min, $text, $maxW);
        return [$s, [$t]];
    }

    /** One pale tile: Nepali · English label, a big value, an optional small line. */
    private static function v2Tile($im, int $x1, int $y1, int $x2, int $y2, string $label, string $value, string $sub = '', ?array $face = null): void
    {
        $face ??= self::PNG_TILE;
        $orangeFace = $face === self::P_ORG;
        DocArt::softShadow($im, $x1, $y1, $x2, $y2, 16, 8, 4, [10, 42, 143], 12);
        if ($orangeFace) {
            DocArt::gradBox($im, $x1, $y1, $x2, $y2, 16, self::P_ORG, self::P_ORG_D);
        } else {
            self::gdRounded($im, $x1, $y1, $x2, $y2, 16, DocArt::col($im, $face));
            DocArt::roundOutline($im, $x1, $y1, $x2, $y2, 16, DocArt::col($im, self::P_EDGE), 1);
            imagefilledrectangle($im, $x1, $y1 + 16, $x1 + 5, $y2 - 16, DocArt::col($im, DocArt::ROYAL));
        }
        $lab = $orangeFace ? imagecolorallocate($im, 255, 240, 226) : imagecolorallocate($im, 84, 96, 118);
        $val = $orangeFace ? imagecolorallocate($im, 255, 255, 255) : DocArt::col($im, DocArt::INK);
        self::gdText($im, 16, $x1 + 24, $y1 + 32, $lab, $label, false);
        $room = $x2 - $x1 - 44;
        $value = $value !== '' ? $value : '-';
        if ($orangeFace) {
            /* the SEAT tile: the berths big, the floor small on the right of the same line */
            $subW = $sub !== '' ? self::gdWidth(17, $sub) + 16 : 0;
            [$sz, $txt] = self::v2Fit(50, 20, $value, $room - $subW);
            self::gdTextP($im, $sz, $x1 + 24, $y1 + 92, $val, $txt, 'Bold');
            if ($sub !== '') {
                self::gdText($im, 17, $x2 - 20 - self::gdWidth(17, $sub), $y1 + 92, $lab, $sub, true);
            }
            return;
        }
        [$sz, $txt] = self::v2Fit($sub !== '' ? 30 : 34, 17, $value, $room);
        $base = $sub !== '' ? $y1 + 72 : $y1 + 80;
        self::gdTextP($im, $sz, $x1 + 24, $base, $val, $txt, 'Bold');
        if ($sub !== '') {
            [$ss, $st] = self::v2Fit(17, 12, $sub, $room, 'Medium');
            self::gdTextP($im, $ss, $x1 + 24, $y2 - 14, $lab, $st, 'Medium');
        }
    }

    /** A QR card head strip: rounded white card, coloured head with a Nepali · English title. */
    private static function v2Card($im, int $x1, int $y1, int $x2, int $y2, string $title, array $a, array $b): void
    {
        DocArt::softShadow($im, $x1, $y1, $x2, $y2, 18, 10, 6, [10, 42, 143], 14);
        self::gdRounded($im, $x1, $y1, $x2, $y2, 18, imagecolorallocate($im, 255, 255, 255));
        DocArt::roundOutline($im, $x1, $y1, $x2, $y2, 18, DocArt::col($im, $b), 2);
        DocArt::gradBox($im, $x1 + 2, $y1 + 2, $x2 - 2, $y1 + 52, 16, $a, $b);
        imagefilledrectangle($im, $x1 + 2, $y1 + 36, $x2 - 2, $y1 + 52, DocArt::col($im, $b));
        $sz = 19;
        while ($sz > 13 && self::gdWidth($sz, $title) > $x2 - $x1 - 40) { $sz--; }
        self::gdText($im, $sz, $x1 + 20, $y1 + 36, imagecolorallocate($im, 255, 255, 255), $title, true);
    }

    /**
     * The clean PNG ticket. Same file contract as renderTicketPng() (atomic
     * tmp + rename), 1080 wide; the height follows the passenger count and the
     * note / extras strips.
     */
    private static function renderTicketPngV2(array $booking, array $ticket, string $path, string $mark = ''): void
    {
        if (!class_exists('QrCode')) { require_once __DIR__ . '/qr.php'; }
        $W     = 1080;
        $X1    = 64;
        $X2    = $W - 64;
        $seats = self::seatNumbers((int) $booking['id']);
        $paxes = array_values($booking['passengers'] ?? []);
        $paxN  = count($paxes) > 0 ? count($paxes) : max(1, count($seats));
        $listN = $paxN > 1 ? min($paxN, self::PNG_MAX_PAX_ROWS) : 0;
        $listH = $listN > 0 ? 64 + $listN * 44 + 14 + ($paxN > $listN ? 30 : 0) : 0;
        $tNote  = self::ticketNote($booking);
        $tExtra = self::extrasLine($booking);
        $tSave  = self::saveLine($booking);     // review: the discount / normal-fare saving the old fare band carried
        $co     = self::companyOf($booking);
        $issued = self::issuedBy($booking);
        $sum    = self::paymentSummary($booking);
        $payQ   = self::paymentQr($booking, $sum);
        $stamp  = self::paidStamp($booking);
        $mapQ   = self::mapQrs($booking);
        $board  = null;
        $nepal  = null;
        foreach ($mapQ as $mq) {
            if ($mq['kind'] === 'board' && $board === null) { $board = $mq; }
            if ($mq['kind'] === 'nepal' && $nepal === null) { $nepal = $mq; }
        }
        $address = self::latin((string) (($co['desk']['address'] ?? '') !== '' ? $co['desk']['address'] : ($co['address'] ?? '')));

        /* ---- heights, top to bottom (all fixed but the list / strips) ---- */
        $headB  = 256;                          // header band bottom
        $yPnr   = $headB + 8;                   // PNR row 128
        $yRoute = $yPnr + 128;                  // route block 196
        $yTiles = $yRoute + 196 + 18;           // 3 tile rows of 112 + 16 gaps
        $yStat  = $yTiles + 3 * 112 + 2 * 16 + 18;
        $ySave  = $yStat + 84 + 16;             // the saving strip (only when something was saved), PNG_NOTE_H
        $yList  = $ySave + ($tSave['kind'] !== '' ? self::PNG_NOTE_H : 0);
        $yNote  = $yList + ($listH > 0 ? $listH + 16 : 0);
        $yTear  = $yNote + ($tNote !== '' ? self::PNG_NOTE_H : 0) + ($tExtra !== '' ? self::PNG_NOTE_H : 0) + 10;   // one strip = PNG_NOTE_H, as on the old layout
        $yQ1    = $yTear + 34;                  // pickup + pay / stamp cards, 360
        $yQ2    = $yQ1 + 360 + 22;              // barcode + security code | Nepal office, 250
        $yFoot  = $yQ2 + 250 + 20;              // booked-by tile 72
        $cardB  = $yFoot + 72 + 22 + 30 + 28 + 26 + ($address !== '' ? 24 : 0) + 2 * 21 + 24;
        $H      = $cardB + 40;

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        $navy  = DocArt::col($im, DocArt::NAVY);
        $royal = DocArt::col($im, DocArt::ROYAL);
        $ink   = DocArt::col($im, DocArt::INK);
        $mut   = imagecolorallocate($im, 84, 96, 118);
        $green = imagecolorallocate($im, 23, 122, 72);
        $orgD  = DocArt::col($im, self::P_ORG_D);
        $orgL  = DocArt::col($im, self::P_ORG_L);
        $edge  = DocArt::col($im, self::P_EDGE);
        $tile  = DocArt::col($im, self::PNG_TILE);
        imagefilledrectangle($im, 0, 0, $W, $H, DocArt::col($im, self::PNG_PAGE));

        /* ---- header: emblem, company, one big flag ---------------------- */
        DocArt::softShadow($im, 36, 36, $W - 36, $headB, 24, 14, 8, [10, 42, 143], 16);
        DocArt::gradBox($im, 36, 36, $W - 36, $headB, 24, self::P_DEEP, DocArt::ROYAL);
        imagefilledrectangle($im, 36, $headB - 14, $W - 36, $headB, $royal);
        $flagCc = self::flagByDirection($booking);
        $flagH  = 132;
        $flagW  = DocArt::flagWidth($flagCc, $flagH);
        $flagX  = $X2 - $flagW;
        $flagY  = 36 + intdiv($headB - 36 - $flagH, 2);
        imagefilledrectangle($im, $flagX - 5, $flagY - 5, $flagX + $flagW + 4, $flagY + $flagH + 4, $white);
        DocArt::flag($im, $flagCc, $flagX, $flagY, $flagH, true);
        $hd = self::ownLogo($co) === '' ? self::ticketMarkFile() : null;
        $embW = 196; $embH = 104;
        if ($hd !== null) {
            DocArt::placeImage($im, $hd, $X1 - 14, 78 - 14, $embW + 28, $embH + 28, 'center');
        } else {
            DocArt::mark($im, $X1, 78, $embW, $embH, true, self::ownLogo($co), 'center');
        }
        [$brand, $legal] = self::brandParts((string) $co['name']);
        $legal = trim((string) preg_replace('/^PRIVATE LIMITED/', 'PVT. LTD.', $legal));
        $tx    = $X1 + $embW + 22;
        $tRoom = $flagX - 28 - $tx;
        [$bSz, $bTxt] = self::v2Fit(46, 22, $brand, $tRoom - self::gdWidth(18, self::TM) - 6);
        self::gdTextP($im, $bSz, $tx, 122, $white, $bTxt, 'Bold');
        self::gdText($im, 18, $tx + self::gdWidthP($bSz, $bTxt, 'Bold') + 6, 122 - (int) round($bSz * 0.55), $white, self::TM, true);
        if ($legal !== '') {
            [$lSz, $lTxt] = self::v2Fit(26, 14, $legal, $tRoom, 'SemiBold');
            self::gdTextP($im, $lSz, $tx, 160, $orgL, $lTxt, 'SemiBold');
        }
        $eLine = 'ई-टिकट · E-TICKET / BOARDING PASS';
        $eSz = 18;
        while ($eSz > 12 && self::gdWidth($eSz, $eLine) > $tRoom) { $eSz--; }
        self::gdText($im, $eSz, $tx, 204, imagecolorallocate($im, 196, 210, 240), $eLine, false);
        $cName = $flagCc === 'NP' ? 'NEPAL' : 'INDIA';
        self::gdTextP($im, 13, $flagX + intdiv($flagW - self::gdWidthP(13, $cName, 'SemiBold'), 2), $flagY + $flagH + 22, imagecolorallocate($im, 214, 224, 244), $cName, 'SemiBold');

        /* ---- the white card --------------------------------------------- */
        DocArt::softShadow($im, 36, $headB + 30, $W - 36, $cardB, 24, 16, 10, [10, 42, 143], 16);
        self::gdRounded($im, 36, $headB - 2, $W - 36, $cardB, 24, $white);
        imagefilledrectangle($im, 36, $headB - 2, $W - 36, $headB + 30, $white);
        self::pngAccent($im, 36, $headB - 4, $W - 36, $headB + 4);
        $pnr = (string) $booking['pnr'];
        $wmName = strtoupper((string) (preg_split('/\s+/', self::latin((string) ($paxes[0]['full_name'] ?? ''))) ?: [''])[0]);
        self::gdWatermark($im, 40, $headB + 8, $W - 40, $cardB - 8,
            implode('  ·  ', array_filter([$pnr, $wmName, (string) ($ticket['ticket_number'] ?? '')], static fn(string $s): bool => $s !== '')));

        /* ---- PNR row ------------------------------------------------------ */
        self::gdText($im, 18, $X1, $yPnr + 40, $mut, 'टिकट नं. · PNR', false);
        [$pSz, $pTxt] = self::v2Fit(52, 30, $pnr, 560);
        self::gdTextP($im, $pSz, $X1, $yPnr + 100, $navy, $pTxt, 'Bold');
        if (!empty($ticket['ticket_number'])) {
            $tn = 'Ticket ' . (string) $ticket['ticket_number'];
            self::gdTextP($im, 18, $X2 - self::gdWidthP(18, $tn, 'Medium'), $yPnr + 40, $mut, $tn, 'Medium');
        }
        $stCol = $stamp['state'] === 'paid' ? [23, 122, 72] : ($stamp['state'] === 'board' ? self::P_ORG_D : [180, 40, 40]);
        $pillT = $stamp['state'] === 'paid' ? 'PAID' : ($stamp['state'] === 'board' ? 'PAY ON BOARDING' : 'PAYMENT DUE');
        $pillW = self::gdWidthP(22, $pillT, 'Bold') + 44;
        self::gdRounded($im, $X2 - $pillW, $yPnr + 58, $X2, $yPnr + 106, 24, DocArt::col($im, $stCol));
        self::gdTextP($im, 22, $X2 - $pillW + 22, $yPnr + 92, $white, $pillT, 'Bold');

        /* ---- FROM -> TO --------------------------------------------------- */
        $ends  = self::tripEnds($booking);
        $fromN = self::latin($ends['fromName']) !== '' ? self::latin($ends['fromName']) : self::latin((string) ($booking['from_city'] ?? ''));
        $toN   = self::latin($ends['toName']) !== '' ? self::latin($ends['toName']) : self::latin((string) ($booking['to_city'] ?? ''));
        $rY1 = $yRoute; $rY2 = $yRoute + 196;
        DocArt::softShadow($im, $X1, $rY1, $X2, $rY2, 20, 10, 5, [10, 42, 143], 12);
        self::gdRounded($im, $X1, $rY1, $X2, $rY2, 20, DocArt::col($im, [236, 243, 255]));
        DocArt::roundOutline($im, $X1, $rY1, $X2, $rY2, 20, $edge, 1);
        $half  = 380;
        $midX1 = $X1 + 24 + $half + 12;
        $midX2 = $X2 - 24 - $half - 12;
        self::gdText($im, 18, $X1 + 26, $rY1 + 38, $mut, 'चढ्ने ठाउँ · From', true);
        $toLab = 'झर्ने ठाउँ · To';
        self::gdText($im, 18, $X2 - 26 - self::gdWidth(18, $toLab), $rY1 + 38, $mut, $toLab, true);
        [$fs, $fl] = self::v2Wrap($fromN !== '' ? $fromN : '-', $half, 48, 24);
        [$ts, $tl] = self::v2Wrap($toN !== '' ? $toN : '-', $half, 48, 24);
        $ns = min($fs, $ts);
        if ($ns < $fs) { [$ns, $fl] = self::v2Wrap($fromN !== '' ? $fromN : '-', $half, $ns, 24); }
        if ($ns < $ts) { [$ns, $tl] = self::v2Wrap($toN !== '' ? $toN : '-', $half, $ns, 24); }
        $lineH = (int) round($ns * 1.3);
        $nTop  = $rY1 + 50 + (int) round($ns * 1.05) + (count($fl) === 1 && count($tl) === 1 ? 10 : 0);
        foreach ($fl as $i => $ln) {
            self::gdTextP($im, $ns, $X1 + 26, $nTop + $i * $lineH, $navy, $ln, 'Bold');
        }
        foreach ($tl as $i => $ln) {
            self::gdTextP($im, $ns, $X2 - 26 - self::gdWidthP($ns, $ln, 'Bold'), $nTop + $i * $lineH, $navy, $ln, 'Bold');
        }
        $codeY = $rY2 - 22;
        $dep   = $ends['dep'] !== '' ? $ends['dep'] : substr((string) ($booking['dep_time'] ?? ''), 0, 5);
        $fSub  = $ends['fromCode'] . ($dep !== '' ? '  ·  ' . self::zonedTime($dep, $co) : '');
        self::gdTextP($im, 18, $X1 + 26, $codeY, $orgD, $fSub, 'SemiBold');
        self::gdTextP($im, 18, $X2 - 26 - self::gdWidthP(18, $ends['toCode'], 'SemiBold'), $codeY, $orgD, $ends['toCode'], 'SemiBold');
        // the arrow: an orange line with a head, the coach direction
        $ay = $rY1 + 104;
        imagesetthickness($im, 6);
        imageline($im, $midX1, $ay, $midX2 - 6, $ay, $orgD);
        imagesetthickness($im, 1);
        imagefilledpolygon($im, [$midX2 - 22, $ay - 15, $midX2 + 4, $ay, $midX2 - 22, $ay + 15], $orgD);
        imagefilledellipse($im, $midX1, $ay, 18, 18, $orgD);
        imagefilledellipse($im, $midX1, $ay, 8, 8, $white);

        /* ---- detail tiles ---------------------------------------------- */
        $cw   = intdiv($X2 - $X1 - 20, 2);
        $cxL  = $X1; $cxR = $X1 + $cw + 20;
        $lead = self::displayOr((string) ($paxes[0]['full_name'] ?? ''));
        $phone = (string) ($booking['contact_phone'] ?? '');
        if ($phone === '0000000000') { $phone = ''; }
        $heroSeats = array_values(array_unique(array_filter(array_map(static fn($p): string => (string) ($p['seat_no'] ?? ''), $paxes), 'strlen')));
        $restSeats = array_diff(array_map('strval', $seats), $heroSeats);
        natsort($restSeats);
        $sh   = self::seatHero($booking, array_merge($heroSeats, array_values($restSeats)));
        $seatTxt = $sh['text'] !== '' ? ($sh['count'] > 4 ? implode(' · ', array_slice(explode(' · ', $sh['text']), 0, 4)) . ' +' . ($sh['count'] - 4) : $sh['text']) : '-';
        $bus  = trim((string) ($booking['bus_number'] ?? ''));
        $coachW = strtolower((string) ($booking['coach_type'] ?? 'sleeper')) === 'seater' ? 'Seater' : 'Sleeper';
        $dt   = formatDate((string) ($booking['travel_date'] ?? ''), 'D, j M Y');
        $bs   = self::bsDate((string) ($booking['travel_date'] ?? ''));
        $y = $yTiles;
        self::v2Tile($im, $cxL, $y, $cxL + $cw, $y + 112, 'यात्रु · Passenger', $lead, $paxN > 1 ? '+' . ($paxN - 1) . ' more · जम्मा ' . self::nepaliDigits((string) $paxN) . ' जना' : '');
        self::v2Tile($im, $cxR, $y, $X2, $y + 112, 'मोबाइल · Mobile', $phone !== '' ? $phone : '-');
        $y += 128;
        self::v2Tile($im, $cxL, $y, $cxL + $cw, $y + 112, $sh['count'] > 1 ? 'सिट नं. · Seats' : 'सिट नं. · Seat', $seatTxt, $sh['floor'], self::P_ORG);
        self::v2Tile($im, $cxR, $y, $X2, $y + 112, 'बस नं. · Bus', $bus !== '' ? $bus : '-', $coachW);
        $y += 128;
        self::v2Tile($im, $cxL, $y, $cxL + $cw, $y + 112, 'मिति · Date', $dt, $bs);
        $report = '';
        if ($dep !== '' && ($dts = strtotime($dep)) !== false) {
            $report = 'रिपोर्टिङ · Report by ' . date('g:i A', $dts - max(0, Settings::getInt('report_before_min', 60)) * 60);
        }
        self::v2Tile($im, $cxR, $y, $X2, $y + 112, 'समय · Departure', $dep !== '' ? self::zonedTime($dep, $co) : '-', $report);

        /* ---- payment status bar ---------------------------------------- */
        $isPaid = $stamp['state'] === 'paid';
        $barA = $isPaid ? [30, 140, 84] : ($stamp['state'] === 'board' ? self::P_ORG : [206, 62, 52]);
        $barB = $isPaid ? [18, 104, 60] : ($stamp['state'] === 'board' ? self::P_ORG_D : [160, 32, 40]);
        DocArt::softShadow($im, $X1, $yStat, $X2, $yStat + 84, 18, 10, 6, $barB, 16);
        DocArt::gradBox($im, $X1, $yStat, $X2, $yStat + 84, 18, $barA, $barB);
        self::gdText($im, 15, $X1 + 26, $yStat + 28, imagecolorallocate($im, 226, 244, 232), 'भुक्तानी · Payment', false);
        $amtInr = $isPaid ? (float) ($booking['total_amount'] ?? 0) : (float) $sum['due'];
        $amtTxt = ($isPaid ? 'जम्मा ' : 'बाँकी ') . self::money($booking, $amtInr, false);
        $amtW   = self::gdWidth(26, $amtTxt);
        self::gdText($im, 26, $X2 - 26 - $amtW, $yStat + 56, $white, $amtTxt, true);
        $stTxt = $stamp['text'];
        $stSz  = 24;
        while ($stSz > 14 && self::gdWidth($stSz, $stTxt) > $X2 - $X1 - 80 - $amtW) { $stSz--; }
        self::gdText($im, $stSz, $X1 + 26, $yStat + 66, $white, $stTxt, true);

        /* ---- what the fare saved (review): the normal fare struck through + the saving,
               or the discount with its % and source - the old fare band's line ---------- */
        if ($tSave['kind'] !== '') {
            $sy1 = $ySave;
            self::gdRounded($im, $X1, $sy1, $X2, $sy1 + 60, 14, DocArt::col($im, [232, 247, 238]));
            DocArt::roundOutline($im, $X1, $sy1, $X2, $sy1 + 60, 14, DocArt::col($im, [150, 210, 175]), 1);
            $svLab = 'बचत · Saved';
            self::gdText($im, 16, $X1 + 22, $sy1 + 38, $green, $svLab, true);
            $sx   = $X1 + 22 + self::gdWidth(16, $svLab) + 18;
            $sMax = $X2 - 22 - $sx;
            if ($tSave['kind'] === 'nf') {
                $pre = 'सामान्य भाडा ';
                $mid = '  ·  ';
                $sSz = 21;
                $wOf = static fn(int $z): int => self::gdWidth($z, $pre) + self::gdWidth($z, $tSave['normal']) + self::gdWidth($z, $mid) + self::gdWidth($z, $tSave['save']);
                while ($sSz > 14 && $wOf($sSz) > $sMax) { $sSz--; }
                if ($wOf($sSz) > $sMax) { $pre = ''; }
                $cx = $sx;
                if ($pre !== '') { self::gdText($im, $sSz, $cx, $sy1 + 39, $mut, $pre, false); $cx += self::gdWidth($sSz, $pre); }
                $nW = self::gdWidth($sSz, $tSave['normal']);
                self::gdText($im, $sSz, $cx, $sy1 + 39, $mut, $tSave['normal'], false);
                imagesetthickness($im, 3);
                imageline($im, $cx - 2, $sy1 + 39 - (int) round($sSz * 0.36), $cx + $nW + 2, $sy1 + 39 - (int) round($sSz * 0.36), DocArt::col($im, [224, 72, 72]));
                imagesetthickness($im, 1);
                $cx += $nW;
                self::gdText($im, $sSz, $cx, $sy1 + 39, $mut, $mid, false);
                $cx += self::gdWidth($sSz, $mid);
                self::gdText($im, $sSz, $cx, $sy1 + 39, $green, $tSave['save'], true);
            } else {
                $sv  = $tSave['text'];
                $sSz = 21;
                while ($sSz > 14 && self::gdWidth($sSz, $sv) > $sMax) { $sSz--; }
                while ($sv !== '' && self::gdWidth($sSz, $sv) > $sMax) { $sv = rtrim(mb_substr($sv, 0, mb_strlen($sv) - 2)) . '…'; }
                self::gdText($im, $sSz, $sx, $sy1 + 39, $green, $sv, true);
            }
        }

        /* ---- passenger list (groups only) ------------------------------- */
        if ($listN > 0) {
            $py = $yList;
            self::gdRounded($im, $X1, $py, $X2, $py + $listH, 16, $tile);
            DocArt::roundOutline($im, $X1, $py, $X2, $py + $listH, 16, $edge, 1);
            self::gdText($im, 17, $X1 + 24, $py + 38, $mut, 'यात्रु र सिट · Passengers & seats', false);
            $ly = $py + 58;
            for ($i = 0; $i < $listN; $i++) {
                $nm    = self::displayOr((string) ($paxes[$i]['full_name'] ?? ''));
                $canon = (string) ($paxes[$i]['seat_no'] ?? ($seats[$i] ?? ''));
                $sn    = self::display(self::seatLabel($canon, $booking));
                $fl    = self::floorCode($canon);
                if ($sn !== '' && $fl !== '') { $sn .= ' · ' . ($fl === '2F' ? 'माथि 2F' : 'तल 1F'); }
                $chipW = $sn !== '' ? self::gdWidth(20, $sn) + 32 : 0;
                if ($sn !== '') {
                    DocArt::gradBox($im, $X2 - 20 - $chipW, $ly + 2, $X2 - 20, $ly + 40, 12, DocArt::ROYAL, DocArt::NAVY);
                    self::gdText($im, 20, $X2 - 20 - $chipW + 16, $ly + 30, $white, $sn, true);
                }
                if ($i > 0) { imageline($im, $X1 + 24, $ly - 2, $X2 - 30 - $chipW, $ly - 2, DocArt::col($im, [220, 230, 250])); }
                self::gdText($im, 18, $X1 + 24, $ly + 30, $mut, self::nepaliDigits((string) ($i + 1)) . '.', false);
                $nmMax = $X2 - 20 - $chipW - 24 - ($X1 + 72);
                $nmSz  = 22;
                while ($nmSz > 16 && self::gdWidth($nmSz, $nm) > $nmMax) { $nmSz--; }
                while ($nm !== '' && self::gdWidth($nmSz, $nm) > $nmMax) { $nm = mb_substr($nm, 0, mb_strlen($nm) - 2) . '…'; }
                self::gdText($im, $nmSz, $X1 + 72, $ly + 30, $ink, $nm, true);
                $ly += 44;
            }
            if ($paxN > $listN) {
                self::gdText($im, 17, $X1 + 72, $ly + 20, $mut, '+ ' . self::nepaliDigits((string) ($paxN - $listN)) . ' जना थप · ' . ($paxN - $listN) . ' more', false);
            }
        }

        /* ---- note / extras ---------------------------------------------- */
        $sy = $yNote;
        foreach ([[$tNote, 'टिप्पणी · Note', [255, 243, 230], [246, 190, 140], [180, 83, 9]], [$tExtra, 'थप · Extras', [234, 241, 255], [160, 186, 236], [26, 68, 200]]] as [$txt, $lab, $bg, $ln, $tc]) {
            if ($txt === '') { continue; }
            self::gdRounded($im, $X1, $sy, $X2, $sy + 60, 14, DocArt::col($im, $bg));
            DocArt::roundOutline($im, $X1, $sy, $X2, $sy + 60, 14, DocArt::col($im, $ln), 1);
            self::gdText($im, 16, $X1 + 22, $sy + 38, DocArt::col($im, $tc), $lab, true);
            $nx = $X1 + 22 + self::gdWidth(16, $lab) + 18;
            $nMax = $X2 - 22 - $nx;
            $nSz = 21;
            while ($nSz > 15 && self::gdWidth($nSz, $txt) > $nMax) { $nSz--; }
            while ($txt !== '' && self::gdWidth($nSz, $txt) > $nMax) { $txt = rtrim(mb_substr($txt, 0, mb_strlen($txt) - 2)) . '…'; }
            self::gdText($im, $nSz, $nx, $sy + 39, $ink, $txt, true);
            $sy += self::PNG_NOTE_H;
        }

        self::pngTear($im, 36, $W - 36, $yTear + 8, self::PNG_PAGE, 18);

        /* ---- Q1: pickup location QR | pay QR (only while due) or PAID stamp ---- */
        $qL1 = $X1; $qL2 = $X1 + $cw; $qR1 = $cxR; $qR2 = $X2;
        $qy1 = $yQ1; $qy2 = $yQ1 + 360;
        self::v2Card($im, $qL1, $qy1, $qL2, $qy2, 'चढ्ने ठाउँ · PICKUP LOCATION', [34, 150, 92], [18, 104, 60]);
        $placeTxt = $board !== null ? (string) $board['place'] : self::latin(self::stopForPrint((string) ($booking['boarding_stop'] ?? '')));
        $placeTxt = trim((string) (preg_split('/\s+·\s+|\s+-\s+/u', $placeTxt)[0] ?? $placeTxt));
        $pPhone   = self::pickupPhone($booking);
        $qMax     = 206;
        if ($board !== null) {
            $side = self::gdQrSide($board['payload'], $qMax, QrCode::ECC_M, 2);
            $qx = $qL1 + intdiv(($qL2 - $qL1) - $side, 2);
            $qy = $qy1 + 64;
            if (self::gdQr($im, $board['payload'], $qx, $qy, $qMax, QrCode::ECC_M, 2) > 0) {
                self::gdScanFrame($im, $qx - 6, $qy - 6, $qx + $side + 6, $qy + $side + 6, 24, 3, $green);
            }
        } else {
            // no pin and no office place in that town: a drawn pin, never a guessed map
            $mx = intdiv($qL1 + $qL2, 2); $my = $qy1 + 150;
            imagefilledellipse($im, $mx, $my - 20, 92, 92, $green);
            imagefilledpolygon($im, [$mx - 40, $my, $mx + 40, $my, $mx, $my + 70], $green);
            imagefilledellipse($im, $mx, $my - 20, 36, 36, $white);
        }
        [$plSz, $plTxt] = self::v2Fit(21, 14, $placeTxt !== '' ? $placeTxt : '-', $qL2 - $qL1 - 36);
        self::gdTextP($im, $plSz, $qL1 + intdiv(($qL2 - $qL1) - self::gdWidthP($plSz, $plTxt, 'Bold'), 2), $qy2 - 46, $ink, $plTxt, 'Bold');
        if ($pPhone !== '') {
            $phT = 'फोन · Phone  ' . $pPhone;
            $phSz = 18;
            while ($phSz > 13 && self::gdWidth($phSz, $phT) > $qL2 - $qL1 - 36) { $phSz--; }
            self::gdText($im, $phSz, $qL1 + intdiv(($qL2 - $qL1) - self::gdWidth($phSz, $phT), 2), $qy2 - 16, $green, $phT, true);
        }

        $payable = $sum['due'] > 0 && in_array((string) ($booking['status'] ?? ''), ['pending', 'confirmed'], true);
        $payImg  = $payQ['mode'] === 'npr' ? (string) ($payQ['esewaImg'] ?? '') : '';
        $hasPayQr = $payable && ($payQ['qr'] !== '' || $payImg !== '');
        if ($hasPayQr) {
            $isNp = $payQ['mode'] === 'npr';
            /* review: a Nepal-side pay-at-boarding / pending ticket keeps the LIVE pay link
               (pay.php: UPI or eSewa + "I paid, here is the UTR") - say so, with the रू too. */
            $liveNp = !$isNp && ($payQ['country'] ?? 'IN') === 'NP' && $payQ['qr'] !== '' && $payQ['qr'] === $payQ['payload'];
            self::v2Card($im, $qR1, $qy1, $qR2, $qy2, $isNp ? 'भुक्तानी QR · eSewa / Fonepay' : ($liveNp ? 'भुक्तानी QR · UPI / eSewa' : 'भुक्तानी QR · SCAN & PAY UPI'), self::P_ORG, self::P_ORG_D);
            $qy = $qy1 + 64;
            if ($payImg !== '') {
                $src = @imagecreatefromstring((string) @file_get_contents($payImg));
                if ($src !== false) {
                    $sw = imagesx($src); $sh2 = imagesy($src);
                    $sc = min($qMax / max(1, $sw), $qMax / max(1, $sh2));
                    $dw = (int) round($sw * $sc); $dh = (int) round($sh2 * $sc);
                    $qx = $qR1 + intdiv(($qR2 - $qR1) - $dw, 2);
                    imagecopyresampled($im, $src, $qx, $qy + intdiv($qMax - $dh, 2), 0, 0, $dw, $dh, $sw, $sh2);
                    imagedestroy($src);
                    self::gdScanFrame($im, $qx - 6, $qy - 6, $qx + $dw + 6, $qy + $qMax + 6, 24, 3, $orgD);
                }
            } else {
                $side = self::gdQrSide($payQ['qr'], $qMax, QrCode::ECC_L, 2);
                $qx = $qR1 + intdiv(($qR2 - $qR1) - $side, 2);
                if (self::gdQr($im, $payQ['qr'], $qx, $qy, $qMax, QrCode::ECC_L, 2) > 0) {
                    self::gdScanFrame($im, $qx - 6, $qy - 6, $qx + $side + 6, $qy + $side + 6, 24, 3, $orgD);
                }
            }
            $amt = $isNp ? 'रू ' . indianNumber(self::nprDue($booking, (float) $sum['due'])) : '₹' . indianNumber((float) $sum['due']);
            if ($liveNp) {
                $amt .= ' (रू ' . indianNumber(self::nprDue($booking, (float) $sum['due'])) . ')';
            }
            $l1  = 'तिर्न बाँकी · Due  ' . $amt;
            $l1s = 21;
            while ($l1s > 14 && self::gdWidth($l1s, $l1) > $qR2 - $qR1 - 36) { $l1s--; }
            self::gdText($im, $l1s, $qR1 + intdiv(($qR2 - $qR1) - self::gdWidth($l1s, $l1), 2), $qy2 - 46, $orgD, $l1, true);
            $l2 = $isNp ? ($payQ['esewa'] !== '' ? 'eSewa: ' . self::latin((string) $payQ['esewa']) : 'Nepal eSewa / Fonepay QR')
                        : ($liveNp ? 'Scan, pay, send the UTR there' : ($payQ['vpa'] !== '' ? 'UPI: ' . self::latin((string) $payQ['vpa']) : 'UPI'));
            [$l2s, $l2t] = self::v2Fit(17, 12, $l2, $qR2 - $qR1 - 36, 'Medium');
            self::gdTextP($im, $l2s, $qR1 + intdiv(($qR2 - $qR1) - self::gdWidthP($l2s, $l2t, 'Medium'), 2), $qy2 - 16, $mut, $l2t, 'Medium');
        } else {
            /* No pay QR: the PAID stamp (rule A), or the due in words when there is no QR to give. */
            $sCol  = $isPaid ? [23, 122, 72] : [212, 87, 12];
            $sColI = DocArt::col($im, $sCol);
            self::v2Card($im, $qR1, $qy1, $qR2, $qy2, $isPaid ? 'भुक्तानी · PAYMENT' : 'तिर्न बाँकी · PAYMENT DUE', $isPaid ? [34, 150, 92] : self::P_ORG, $isPaid ? [18, 104, 60] : self::P_ORG_D);
            $mx = intdiv($qR1 + $qR2, 2); $my = $qy1 + 208;
            imagefilledellipse($im, $mx, $my, 262, 262, DocArt::col($im, $isPaid ? [232, 247, 238] : [255, 241, 228]));
            imagesetthickness($im, 6);
            imagearc($im, $mx, $my, 262, 262, 0, 360, $sColI);
            imagesetthickness($im, 2);
            imagearc($im, $mx, $my, 238, 238, 0, 360, $sColI);
            imagesetthickness($im, 1);
            if ($isPaid) {
                self::gdTextP($im, 46, $mx - intdiv(self::gdWidthP(46, 'PAID', 'Bold'), 2), $my - 6, $sColI, 'PAID', 'Bold');
                $s1 = $stamp['ne'];
                $s1s = 20;
                while ($s1s > 13 && self::gdWidth($s1s, $s1) > 196) { $s1s--; }
                self::gdText($im, $s1s, $mx - intdiv(self::gdWidth($s1s, $s1), 2), $my + 32, $sColI, $s1, true);
                [$s2s, $s2] = self::v2Fit(17, 11, $stamp['en'], 176, 'SemiBold');
                self::gdTextP($im, $s2s, $mx - intdiv(self::gdWidthP($s2s, $s2, 'SemiBold'), 2), $my + 62, $sColI, $s2, 'SemiBold');
            } else {
                $amt = $payQ['mode'] === 'npr' ? 'रू ' . indianNumber(self::nprDue($booking, (float) $sum['due'])) : '₹' . indianNumber((float) $sum['due']);
                self::gdText($im, 20, $mx - intdiv(self::gdWidth(20, $stamp['ne']), 2), $my - 34, $sColI, $stamp['ne'], true);
                $aSz = 40;
                while ($aSz > 20 && self::gdWidth($aSz, $amt) > 196) { $aSz--; }
                self::gdText($im, $aSz, $mx - intdiv(self::gdWidth($aSz, $amt), 2), $my + 20, $sColI, $amt, true);
                $how = $payQ['esewa'] !== '' ? 'eSewa: ' . self::latin((string) $payQ['esewa'])
                    : ($payQ['wa'] !== '' ? 'WhatsApp +' . $payQ['wa'] : ($stamp['state'] === 'board' ? 'Cash on bus' : 'Pay at counter'));
                [$hs, $ht] = self::v2Fit(16, 11, $how, 186, 'SemiBold');
                self::gdTextP($im, $hs, $mx - intdiv(self::gdWidthP($hs, $ht, 'SemiBold'), 2), $my + 58, $sColI, $ht, 'SemiBold');
            }
        }

        /* ---- Q2: barcode + SECURITY CODE | Nepal office (smaller) ---------- */
        $secCode  = self::securityCode($pnr, (string) ($ticket['qr_hash'] ?? ''));
        $scanCode = self::scanCode((int) ($booking['id'] ?? 0), $secCode);
        $bL1 = $X1; $bL2 = $nepal !== null ? $X1 + 560 : $X2;
        try {
            if (!class_exists('Barcode')) { require_once __DIR__ . '/barcode.php'; }
            $bMods = Barcode::modules($scanCode) + 20;
            $bMod  = $bMods * 2 <= ($bL2 - $bL1 - 20) ? 2 : 1;
            $bw    = $bMods * $bMod;
            $bx    = $bL1 + intdiv(($bL2 - $bL1) - $bw, 2);
            self::gdRounded($im, $bL1, $yQ2, $bL2, $yQ2 + 150, 14, $royal);
            self::gdRounded($im, $bL1 + 2, $yQ2 + 2, $bL2 - 2, $yQ2 + 148, 12, $white);
            Barcode::draw($im, $scanCode, $bx + 10 * $bMod, $yQ2 + 14, $bMod, 96, imagecolorallocate($im, 0, 0, 0));
            self::gdText($im, 18, $bL1 + intdiv(($bL2 - $bL1) - self::gdWidth(18, $scanCode), 2), $yQ2 + 136, $navy, $scanCode, true);
        } catch (Throwable $e) {
            Logger::error('Ticket v2 barcode: ' . $e->getMessage());
        }
        self::gdSecurityTile($im, $bL1, $yQ2 + 166, $bL2, $yQ2 + 248, $secCode);
        if ($nepal !== null) {
            $nx1 = $X1 + 580; $nx2 = $X2; $ny1 = $yQ2; $ny2 = $yQ2 + 248;
            self::gdRounded($im, $nx1, $ny1, $nx2, $ny2, 16, $tile);
            DocArt::roundOutline($im, $nx1, $ny1, $nx2, $ny2, 16, $edge, 1);
            $nt = 'नेपाल काउन्टर · NEPAL OFFICE';
            $ntS = 16;
            while ($ntS > 12 && self::gdWidth($ntS, $nt) > $nx2 - $nx1 - 32) { $ntS--; }
            self::gdText($im, $ntS, $nx1 + 16, $ny1 + 30, $green, $nt, true);
            $nq = 132;
            $nSide = self::gdQrSide($nepal['payload'], $nq, QrCode::ECC_L, 2);
            if (self::gdQr($im, $nepal['payload'], $nx1 + 14, $ny1 + 44, $nq, QrCode::ECC_L, 2) > 0) {
                self::gdScanFrame($im, $nx1 + 10, $ny1 + 40, $nx1 + 18 + $nSide, $ny1 + 48 + $nSide, 14, 2, $green);
            }
            $tX = $nx1 + 14 + max($nSide, 100) + 14;
            $tW = $nx2 - 12 - $tX;
            $parts = array_values(array_filter(array_map('trim', explode('|', (string) $nepal['place'])), 'strlen'));
            $ly2 = $ny1 + 66;
            foreach (array_slice($parts, 0, 5) as $pi => $pt) {
                [$ps, $ptx] = self::v2Fit($pi === 0 ? 15 : 14, 10, $pt, $tW, $pi === 0 ? 'SemiBold' : 'Medium');
                self::gdTextP($im, $ps, $tX, $ly2, $pi === 0 ? $ink : $mut, $ptx, $pi === 0 ? 'SemiBold' : 'Medium');
                $ly2 += 24;
            }
        }

        /* ---- booked by ---------------------------------------------------- */
        $fy = $yFoot;
        self::gdRounded($im, $X1, $fy, $X2, $fy + 72, 16, $tile);
        DocArt::roundOutline($im, $X1, $fy, $X2, $fy + 72, 16, $edge, 1);
        imagefilledrectangle($im, $X1, $fy + 14, $X1 + 6, $fy + 58, DocArt::col($im, self::P_ORG));
        $bbLab = 'टिकट काट्ने · Booked by:';
        self::gdText($im, 18, $X1 + 24, $fy + 45, $mut, $bbLab, true);
        $bbX = $X1 + 24 + self::gdWidth(18, $bbLab) + 14;
        $bbT = self::bookedByText($booking);
        $bbS = 22;
        while ($bbS > 13 && self::gdWidth($bbS, $bbT) > $X2 - 20 - $bbX) { $bbS--; }
        while ($bbT !== '' && self::gdWidth($bbS, $bbT) > $X2 - 20 - $bbX) { $bbT = rtrim(mb_substr($bbT, 0, mb_strlen($bbT) - 2)) . '…'; }
        self::gdText($im, $bbS, $bbX, $fy + 46, $ink, $bbT, true);

        /* ---- support + the legal lines ---------------------------------- */
        $cy = $fy + 72 + 34;
        $supPhone = (string) (($co['desk']['phone'] ?? '') !== '' ? $co['desk']['phone'] : ($co['phone'] ?? ''));
        $sup = array_filter(['Support: ' . self::latin($supPhone),
            is_array($co['otherHelp'] ?? null) && ($co['otherHelp']['phone'] ?? '') !== '' ? self::latin((string) $co['otherHelp']['label']) . ': ' . self::latin((string) $co['otherHelp']['phone']) : '',
            self::latin((string) ($co['web'] ?? '')),
            (string) ($co['whatsapp'] ?? '') !== '' ? 'WhatsApp +' . $co['whatsapp'] : ''], static fn(string $s): bool => $s !== '' && $s !== 'Support: ');
        $supL = implode('  ·  ', $sup);
        $sS = 17;
        while ($sS > 11 && self::gdWidthP($sS, $supL, 'SemiBold') > $X2 - $X1) { $sS--; }
        self::gdTextP($im, $sS, intdiv($W - self::gdWidthP($sS, $supL, 'SemiBold'), 2), $cy, $royal, $supL, 'SemiBold');
        $cy += 30;
        $n1 = 'सरकारी परिचयपत्र अनिवार्य · बस छुट्नु ६० मिनेट अगाडि आउनुहोस्';
        self::gdText($im, 16, intdiv($W - self::gdWidth(16, $n1), 2), $cy, $mut, $n1, false);
        $cy += 28;
        $n2 = self::display(trim((string) ($co['legal'] ?? '')) !== '' ? (string) $co['legal'] : (string) $co['name'])
            . ((string) ($co['regLine'] ?? '') !== '' ? '  ·  ' . self::latin((string) $co['regLine'])
                : ((string) ($co['regNo'] ?? '') !== '' ? '  ·  ' . $co['regLabel'] . ' ' . self::latin((string) $co['regNo']) : ''))
            . ((string) ($co['gstin'] ?? '') !== '' ? '  ·  GSTIN ' . self::latin((string) $co['gstin']) : '');
        $n2S = 15;
        while ($n2S > 11 && self::gdWidth($n2S, $n2) > $X2 - $X1) { $n2S--; }
        self::gdText($im, $n2S, intdiv($W - self::gdWidth($n2S, $n2), 2), $cy, $mut, $n2, false);
        $cy += 26;
        if ($address !== '') {
            [$aS, $aT] = self::v2Fit(14, 11, $address, $X2 - $X1, 'Medium');
            self::gdTextP($im, $aS, intdiv($W - self::gdWidthP($aS, $aT, 'Medium'), 2), $cy, $mut, $aT, 'Medium');
            $cy += 24;
        }
        foreach (self::statutoryParts($booking, $ticket, $co) as $n4) {
            if ($n4 === '') { continue; }
            [$s4, $t4] = self::v2Fit(12, 10, $n4, $X2 - $X1, 'Regular');
            self::gdTextP($im, $s4, intdiv($W - self::gdWidthP($s4, $t4, 'Regular'), 2), $cy, $mut, $t4, 'Regular');
            $cy += 21;
        }

        /* CORRECTED / DUPLICATE band in the 36 px margin above the header. */
        $markTxt = self::markText($ticket, $mark);
        if ($markTxt !== '') {
            imagefilledrectangle($im, 0, 0, $W, 34, $mark === 'dup' ? imagecolorallocate($im, 70, 78, 94) : imagecolorallocate($im, 160, 32, 40));
            $mSz = 16;
            while ($mSz > 11 && self::gdWidth($mSz, $markTxt) > $W - 40) { $mSz--; }
            self::gdText($im, $mSz, intdiv($W - self::gdWidth($mSz, $markTxt), 2), 24, $white, $markTxt, true);
        }

        $tmp = $path . '.tmp';
        imagepng($im, $tmp, 4);
        imagedestroy($im);
        @rename($tmp, $path);
    }

    /** A single flag on a PDF page, $h tall, top-left at ($x,$y). Returns the width drawn. */
    private static function pdfOneFlag(Pdf $pdf, string $cc, float $x, float $y, float $h): float
    {
        try {
            $w = $h * DocArt::flagWidth($cc, 1000) / 1000;
            $f = DocArt::flagFile($cc);
            if ($f === null) {
                return 0.0;
            }
            $small = DocArt::derivative($f, (int) ceil($w * 4), (int) ceil($h * 4), 'contain') ?? $f;
            $pdf->rect($x - 1.5, $y - 1.5, $w + 3, $h + 3, [255, 255, 255], true);
            $pdf->image($small, $x, $y, $w, $h);
            if ($cc === 'IN') {
                $pdf->rect($x, $y, $w, $h, [196, 204, 218], false);
            }
            return $w;
        } catch (Throwable) {
            return 0.0;
        }
    }
}
