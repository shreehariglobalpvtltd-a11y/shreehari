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
require_once INCLUDE_PATH . '/goldsheet.php';   // the GOLD template + payment QR (9 Oct 2026)
require_once INCLUDE_PATH . '/chalanistrip.php'; // 25 Nov 2026 (release ch): the Total | Cash | Online | Due strip

final class ChalaniPng
{
    /** A4 landscape shape (1:1.4142) at a size that stays readable on a phone. */
    public const WIDTH  = 2000;
    public const HEIGHT = 1414;

    /** Bump whenever the drawing changes, so every cached page re-renders once. */
    public const LAYOUT_VERSION = 19;  // 19 = strip review: a fifth OTHER PAID chip when not zero, an office-marked-due row counted in DUE (25 Nov 2026)   // 18 = uniform chalani (release ch): Total | Cash | Online | Due strip under the route line, PAID / DUE row chips (25 Nov 2026)   // 17 = desk copy (NP-2): ?desk= filter, an NPR desk draws NPR (Rs beside the net), the desk in the number (19 Nov 2026)   // 16 = pay at boarding: ON BOARDING Rs X / PAID / AGENT / ONLINE PENDING marks per row + a two-column summary with "To collect on boarding" (16 Nov 2026)   // 15 = special (desk-extra) payments: row remark, infants, 2 summary lines (16 Nov 2026)   // 14 = single column drawn LEFT of the aisle (6 Nov 2026)   // 13 was:  // 13 = berth numbers: single side first (A1 A2 | A3..A6) (6 Nov 2026)   // 12 = place no. in BOARDING, women on a single sleeper in pink, place strip, seat-chart page (6 Nov 2026)   // 11 = TRUST: "Computer-generated" + GSTIN (when set) on the official-document line, IST on the Generated stamp (10 Oct 2026)   // 10 = GOLD template: mantra strip, gold seal + foil, cream mandala paper, payment QR on every page, blessing band (9 Oct 2026)   // 9 = emblem without the white chip, India + Nepal flags, heritage art in the band (9 Oct 2026)   // 8 = seat column prints the two-floor grid A1-F6 / A7-F12 (23 Sep 2026)   // 7 = chalani number carries the extra-bus slot (17 Sep 2026)   // 6 = seat column prints the LA1/UA1 row-letter grid   // 5 = the cache key covers every drawn field   // 2 = passenger names print in their own script

    private const M         = 48;     // page margin
    private const ROW_H     = 42;
    private const HEAD_H    = 42;
    private const TABLE_Y   = 430;    // first table head top (under the payment-QR card, 9 Oct 2026)
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

    /* ------------------------------------------------------------------ */
    /*  16 Nov 2026 - PAY AT BOARDING (owner: "pay at boarding vane option  */
    /*  ticket chalani ma add gardeu - cash ho ki online ho; ani redirect    */
    /*  payment lina sab suvidha hunu paryo, free wala"). ONE rule and ONE   */
    /*  set of words for every chalani surface: the manifest screen, the     */
    /*  print sheet, the CSV, the PDF, these PNG pages, the public link.     */
    /* ------------------------------------------------------------------ */

    /** Verified methods whose money sits in the अनलाइन column (manifest $money reads this too). */
    public const ONLINE_METHODS = ['upi', 'esewa', 'bank', 'wallet', 'online'];

    /**
     * HOW a booking pays. Never a second paid rule: $state is OpsHub::payState()
     * (paid | agent | due - the row colour) and the method is the verified
     * payment's, the one the नगद / अनलाइन columns read.
     *
     *   boarding  pay at boarding (COD), nothing verified, not ticked paid  "बसमा ₹X"
     *   cash      verified cash / COD (COD: unless the office ticked online) "नगद ✓"
     *   online    verified UPI / eSewa / bank / wallet, or a COD the office
     *             ticked UPI / eSewa / Bank (paidWay)                        "अनलाइन ✓"
     *   agent     verified cash still with the agent (its old wording)      "एजेन्टसँग"
     *   pending   an online payment the office has not checked (not COD)    "अनलाइन जाँचमा"
     *   paid      an office tick with no verified payment row                "भुक्तानी ✓"
     *   due       anything else unpaid, or the office marked a paid row due "बाँकी"
     *
     * "boarding" needs the payment NOT verified, so one rupee is either in a
     * नगद / अनलाइन column or in "बसमा उठाउनु पर्ने" - never in both.
     *
     * Cash or online is paidWay() - the passenger's ticket's own rule, so a
     * pay-at-boarding fare the office ticked "UPI" / "eSewa" reads अनलाइन ✓ here
     * as it does on the ticket (review 16 Nov 2026: it read नगद ✓).
     *
     * @param array<string,mixed>      $r     needs is_cod, pay_status, pay_method (+ pay_note = payments.admin_note)
     * @param array<string,mixed>|null $tick  the booking's office tick (OpsHub::collections row: status, mode)
     */
    public static function payMode(array $r, string $state, ?array $tick = null): string
    {
        $verified = (string) ($r['pay_status'] ?? '') === 'verified';
        if ($verified) {
            if ($state === 'agent') { return 'agent'; }
            if ($state === 'due')   { return 'due'; }     // the office's "Mark due" on a paid row
            return match (self::paidWay($r, $tick)) { 'cash' => 'cash', 'online' => 'online', default => 'paid' };
        }
        if ($state === 'paid') { return 'paid'; }
        if ((int) ($r['is_cod'] ?? 0) === 1) { return 'boarding'; }
        return (string) ($r['pay_status'] ?? '') === 'pending' ? 'pending' : 'due';
    }

    /**
     * Cash or online for a VERIFIED payment: 'cash' | 'online' | '' (unknown).
     * The नगद / अनलाइन columns (manifest $money) and payMode() both read this.
     *
     * An explicit method decides: 'cash' = cash, UPI / eSewa / bank / wallet =
     * online. A settled pay-at-boarding row always says 'cod'
     * (BookingService::settleCod), so for 'cod' / no method the office's own
     * word decides - the chalani tick's mode, else the note that tick leaves on
     * the row ("Chalani tick: paid (UPI)") - through Ticket::paidWay(), the
     * passenger's ticket's rule, so the chalani and the ticket never disagree.
     * Any other method stays '' (it was never in either column).
     *
     * @param array<string,mixed>      $r     needs pay_method (+ pay_note = payments.admin_note)
     * @param array<string,mixed>|null $tick  OpsHub::collections row (status, mode)
     */
    public static function paidWay(array $r, ?array $tick = null): string
    {
        $method = strtolower(trim((string) ($r['pay_method'] ?? '')));
        if ($method === 'cash') {
            return 'cash';
        }
        if (in_array($method, self::ONLINE_METHODS, true)) {
            return 'online';
        }
        if ($method !== 'cod' && $method !== '') {
            return '';
        }
        // 16 Nov 2026 (mv6 lead): the UTR too - a pay-at-boarding row the office settled from the
        // Payments COD tab after an online claim keeps method 'cod'; the ticket reads its UTR as online.
        $way = (string) (Ticket::paidWay($method, $tick, (string) ($r['pay_note'] ?? ''), (string) ($r['pay_utr'] ?? ''))[0] ?? '');
        return in_array($way, ['cash', 'online'], true) ? $way : '';
    }

    /**
     * The words for a pay mode. $kind: 'ne' (print sheet, PDF, public link),
     * 'staff' (Nepali · English), 'csv', 'ro' (these GD pages - Roman).
     */
    public static function payModeLabel(string $mode, string $kind = 'ne'): string
    {
        $t = match ($mode) {
            'boarding' => ['बसमा', 'बसमा · On boarding', 'ON BOARDING DUE', 'ON BOARDING'],
            'cash'     => ['नगद ✓', 'नगद ✓ · Cash', 'CASH', 'CASH PAID'],
            'online'   => ['अनलाइन ✓', 'अनलाइन ✓ · Online', 'ONLINE', 'ONLINE PAID'],
            'agent'    => ['एजेन्टसँग', 'एजेन्टसँग · With agent', 'AGENT', 'WITH AGENT'],
            'pending'  => ['अनलाइन जाँचमा', 'अनलाइन जाँचमा · Online, office checking', 'ONLINE PENDING', 'ONLINE PENDING'],
            'paid'     => ['भुक्तानी ✓', 'भुक्तानी ✓ · Paid', 'PAID', 'PAID'],
            default    => ['बाँकी', 'बाँकी · Due', 'DUE', 'TO COLLECT'],
        };
        return $t[match ($kind) { 'staff' => 1, 'csv' => 2, 'ro' => 3, default => 0 }];
    }

    /**
     * A pay-at-boarding passenger already sent an online payment (a UTR on the
     * still-unsettled money row - CodPayClaim, pay.php). Still due: only the
     * office's check turns it into money; the crew just knows not to ask twice.
     *
     * @param array<string,mixed> $r  needs pay_status, pay_utr
     */
    public static function payClaimSent(array $r): bool
    {
        return in_array((string) ($r['pay_status'] ?? ''), ['cod_pending', 'pending'], true)
            && trim((string) ($r['pay_utr'] ?? '')) !== '';
    }

    /** The words for a sent-but-unchecked online payment; $kind as payModeLabel(). */
    public static function payClaimLabel(string $kind = 'ne'): string
    {
        return match ($kind) {
            'staff' => 'UTR जाँचमा · Online sent, office checking',
            'csv'   => 'UTR SENT',
            'ro'    => 'UTR SENT · CHECKING',
            default => 'UTR जाँचमा',
        };
    }

    /**
     * The passenger's own pay page (pay.php: UPI, UPI QR, eSewa, UTR claim - all
     * free). $withKey adds the ticket's own download key (k), as the ticket's pay
     * QR does, so the passenger can send the UTR without typing their mobile -
     * for the office screen only, never the login-free public link.
     */
    public static function payLink(string $pnr, bool $withKey = false): string
    {
        $pnr = strtoupper(trim($pnr));
        return appUrl('pay.php') . '?pnr=' . rawurlencode($pnr) . ($withKey ? '&k=' . rawurlencode(Ticket::downloadToken($pnr)) : '');
    }

    /**
     * The crew's "💳 QR": PNG bytes of a QR to payLink(), or null when this
     * booking has no pay-at-boarding money due (unknown PNR, cancelled, paid,
     * ticked paid, not COD...). Read-only - it never marks anything paid; an
     * online payment still lands as 'pending' for the office to verify.
     *
     * @param int|null    $sellerOnly an agent login: only its own sales
     * @param string|null $from       the public link: only a booking travelling
     * @param string|null $to         between these dates
     * @param bool        $withKey    the office screen: the link carries the ticket key (payLink)
     */
    public static function payQrPng(string $pnr, ?int $sellerOnly = null, ?string $from = null, ?string $to = null, bool $withKey = false): ?string
    {
        $pnr = strtoupper(trim($pnr));
        if (preg_match('/^[A-Z0-9-]{6,40}$/', $pnr) !== 1) {
            return null;
        }
        $b = Database::fetch(
            'SELECT b.id, b.pnr, b.is_cod, b.status, b.sold_by_admin_id, a.role AS agent_role,
                    p.status AS pay_status, p.method AS pay_method
               FROM bookings b
               LEFT JOIN admins a   ON a.id = b.sold_by_admin_id
               LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
              WHERE b.pnr = :p LIMIT 1',
            ['p' => $pnr]
        );
        if ($b === null || !in_array((string) $b['status'], ['confirmed', 'pending'], true)) {
            return null;
        }
        if ($sellerOnly !== null && (int) ($b['sold_by_admin_id'] ?? 0) !== $sellerOnly) {
            return null;
        }
        if ($from !== null && $to !== null && (int) Database::scalar(
            'SELECT COUNT(*) FROM booking_legs WHERE booking_id = :b AND travel_date BETWEEN :f AND :t',
            ['b' => (int) $b['id'], 'f' => $from, 't' => $to], 0) === 0) {
            return null;
        }
        require_once INCLUDE_PATH . '/opshub.php';
        $tick = OpsHub::collections([(int) $b['id']])[(int) $b['id']] ?? null;
        if (self::payMode($b, OpsHub::payState($b, $tick), $tick) !== 'boarding') {
            return null;
        }
        require_once INCLUDE_PATH . '/qr.php';
        $uri = QrCode::dataUri(self::payLink($pnr, $withKey), 8, 2);
        $png = base64_decode(substr($uri, (int) strpos($uri, ',') + 1), true);
        return is_string($png) && $png !== '' ? $png : null;
    }

    /* ------------------------------------------------------------------ */
    /*  19 Nov 2026 (AI-3) - THE chalani money rule, in one place.          */
    /*  Lifted verbatim out of admin/manifest.php (rows query, per-seat     */
    /*  fare split, desk extras, cash / online by ChalaniPng::paidWay, pay  */
    /*  at boarding still due, and the whole-bus totals), so the manifest   */
    /*  screen, the print sheet, the PDF, these PNG pages, the office       */
    /*  WhatsApp report and the driver's list can never print two numbers.  */
    /*  NP-2: an optional desk filter and the money in that desk's currency */
    /*  (NPR at the rate frozen on the sale, else the desk's rate).         */
    /* ------------------------------------------------------------------ */

    /** A desk code validated against CounterDesk::all(), or '' (no filter). */
    public static function deskCode(string $desk): string
    {
        $desk = strtoupper(trim($desk));
        if ($desk === '' || preg_match('/^[A-Z0-9_-]{1,16}$/', $desk) !== 1) {
            return '';
        }
        return isset(CounterDesk::all()[$desk]) ? $desk : '';
    }

    /** The desk's currency facts when it is NOT a rupee desk, else null. @return array{code:string,sym:string,latin:string,rate:float,desk:string}|null */
    public static function deskCurrency(string $desk): ?array
    {
        $desk = self::deskCode($desk);
        if ($desk === '' || CounterDesk::currency($desk) === 'INR') {
            return null;
        }
        $cur = CounterDesk::currency($desk);
        return ['code' => $cur, 'sym' => CounterDesk::SYMBOL[$cur] ?? $cur, 'latin' => $cur, 'rate' => CounterDesk::rate($desk), 'desk' => $desk];
    }

    /**
     * Local units per rupee for ONE sale: the rate frozen on the booking
     * (bookings.fx_rate first - the same order as Ticket::nprOf - and only
     * when that is missing fx_total / total_amount, when it was quoted in
     * that money), else the desk's rate today. 1.0 for a rupee desk. Never
     * guessed past that. fx_total is frozen once at sale time while
     * total_amount moves on a fare correction or a partial seat cancel, so
     * the ratio is only a fallback (review fix, 19 Nov 2026).
     *
     * @param array<string,mixed> $row needs total_amount (+ fx_currency, fx_rate, fx_total when frozen)
     */
    public static function rateFor(array $row, string $desk): float
    {
        $cur = self::deskCurrency($desk);
        if ($cur === null) {
            return 1.0;
        }
        $fxCur = strtoupper(trim((string) ($row['fx_currency'] ?? '')));
        if ($fxCur === $cur['code']) {
            $fxRate = (float) ($row['fx_rate'] ?? 0);
            if ($fxRate > 0) {
                return $fxRate;
            }
            $fx  = (float) ($row['fx_total'] ?? 0);
            $tot = (float) ($row['total_amount'] ?? 0);
            if ($fx > 0 && $tot > 0) {
                return $fx / $tot;
            }
        }
        return (float) $cur['rate'];
    }

    /**
     * An INR amount as $desk writes it: "रू 4,480 (₹2,800)" at an NPR desk
     * (the frozen rate of $row when it has one), "₹2,800" at a rupee desk or
     * with no desk. $latin = Roman for the GD pages ("NPR 4,480 (Rs 2,800)").
     */
    public static function deskMoney(float $inr, string $desk, ?array $row = null, bool $latin = false, bool $withInr = true): string
    {
        $inrTxt = $latin ? 'Rs ' . number_format($inr) : CounterDesk::format(round($inr, 2), 'INR');
        $cur    = self::deskCurrency($desk);
        if ($cur === null) {
            return $inrTxt;
        }
        $amt  = (float) round($inr * self::rateFor($row ?? [], $desk));
        $main = $latin ? $cur['latin'] . ' ' . number_format($amt) : CounterDesk::format($amt, $cur['code']);
        return $main . ($withInr ? ' (' . $inrTxt . ')' : '');
    }

    /**
     * Every passenger row of one departure and the money rule over them.
     *
     * @param int|null $scopeAdminId an agent login: only its own sales (the AGENT COPY)
     * @param string   $order        group | stop | seat (as admin/manifest.php)
     * @param string   $desk         a desk code: only the sales of that desk (NP-2)
     * @return array<string,mixed>   rows, ticks, state, paxTotal, legs, bids, xBook, xFirst, xInfants,
     *                               pmBook, pmDue, pmPax, pmLead, pmClaim, boardDue (raw), boardDueN,
     *                               closures extrasOf / extraOnRow / farePer / money, totals{passengers,
     *                               ticket, cash, online, discount, net, extras, extrasPaid, extrasDue,
     *                               infants, boardDue, boardDueN}, desk, cur (null | deskCurrency()),
     *                               local (null | the same totals in the desk's money), moneyLocal, rateOf
     */
    public static function departureMoney(int $sid, ?int $scopeAdminId = null, string $order = 'group', string $desk = ''): array
    {
        require_once INCLUDE_PATH . '/opshub.php';
        $order = in_array($order, ['group', 'stop', 'seat'], true) ? $order : 'group';
        $desk  = self::deskCode($desk);

        /* ---- the rows (admin/manifest.php, 29 Oct - 16 Nov 2026) ---- */
        $rows    = [];
        $routeId = $sid > 0 ? (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => $sid], 0) : 0;
        if ($sid > 0 && $routeId > 0) {
            $where  = ['bl.schedule_id = :sid', "b.status IN ('confirmed','pending','completed')"];
            $params = ['sid' => $sid];
            if ($scopeAdminId !== null) {
                $where[] = 'b.sold_by_admin_id = :scope';
                $params['scope'] = $scopeAdminId;
            }
            if ($desk !== '' && CounterDesk::stampColumn()) {
                $where[] = CounterDesk::scopeClause('b', 'deskF');
                $params['deskF'] = $desk;
            }
            $xCols = '';
            foreach (['luggage_pieces', 'luggage_fee', 'infant_count', 'infant_fee'] as $xc) {
                $xCols .= CounterDesk::hasColumn('bookings', $xc) ? ', b.' . $xc : ', 0 AS ' . $xc;
            }
            /* NP-2: the desk the sale was cut at, and the money frozen on it */
            $xCols .= CounterDesk::stampColumn() ? ', b.counter_code' : ", '' AS counter_code";
            foreach (['fx_currency', 'fx_rate', 'fx_total'] as $fc) {
                $xCols .= CounterDesk::hasColumn('bookings', $fc) ? ', b.' . $fc : ', NULL AS ' . $fc;
            }
            /* 25 Nov 2026 (cp1): where the ticket was cut (BookGeo) - the chalani page shows it */
            foreach (['booked_lat', 'booked_lng', 'booked_place'] as $gc) {
                $xCols .= CounterDesk::hasColumn('bookings', $gc) ? ', b.' . $gc : ', NULL AS ' . $gc;
            }
            $rows = Database::fetchAll(
                "SELECT bp.id AS pax_id, bp.seat_no, bp.full_name, bp.age, bp.gender, bp.boarded_at,
                        bp.id_type, bp.id_number, bp.special_need,
                        b.booking_mode" . $xCols . ",
                        b.id AS booking_id, b.pnr, b.contact_phone, b.status AS booking_status,
                        b.source, b.is_cod, b.total_amount, b.base_total, b.sold_by_admin_id, b.created_at,
                        bl.boarding_stop, bl.drop_stop,
                        p.status AS pay_status, p.method AS pay_method, p.utr_number AS pay_utr, p.admin_note AS pay_note,
                        t.ticket_number, t.scanned_at, t.is_void,
                        ad.full_name AS agent_name, ad.username AS agent_user, ad.role AS agent_role
                   FROM booking_passengers bp
                   JOIN bookings b      ON b.id = bp.booking_id
                   JOIN booking_legs bl ON bl.id = bp.leg_id
                   LEFT JOIN payments p ON p.id = (
                         SELECT p2.id FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1)
                   LEFT JOIN tickets t  ON t.booking_id = b.id
                   LEFT JOIN admins ad  ON ad.id = b.sold_by_admin_id
                  WHERE " . implode(' AND ', $where) . "
                  ORDER BY " . ($order !== 'seat'
                      ? "COALESCE((SELECT MIN(rs.sort_order) FROM route_stops rs
                                    WHERE rs.route_id = :rid AND rs.stop_type = 'boarding' AND rs.stop_name <> ''
                                      AND bl.boarding_stop LIKE CONCAT(rs.stop_name, '%')), 9999), "
                        . ($order === 'group' ? 'bl.boarding_stop, b.id, ' : '')
                      : '') . "LENGTH(bp.seat_no), bp.seat_no",
                $params + ($order !== 'seat' ? ['rid' => $routeId] : [])
            );
        }

        /* paid · with agent · due - one rule (OpsHub::payState) */
        $mfTicks = OpsHub::collections(array_map(static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows));
        $mfState = [];
        foreach ($rows as $r) {
            $bid = (int) ($r['booking_id'] ?? 0);
            if (!isset($mfState[$bid])) {
                $mfState[$bid] = OpsHub::payState($r, $mfTicks[$bid] ?? null);
            }
        }

        /* the per-person divisor: every passenger-seat on the WHOLE booking (both legs) */
        $paxTotalPerBooking = [];
        $bids = array_values(array_unique(array_map(static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows)));
        $bids = array_values(array_filter($bids, static fn(int $i): bool => $i > 0));
        if ($bids !== []) {
            $in = implode(',', $bids);   // ints from the DB - safe to inline
            foreach (Database::fetchAll("SELECT booking_id, COUNT(*) c FROM booking_passengers WHERE booking_id IN ($in) GROUP BY booking_id") as $pr) {
                $paxTotalPerBooking[(int) $pr['booking_id']] = (int) $pr['c'];
            }
        }

        /* the desk extras (luggage / lap infants), once per booking, split across its legs */
        $legsPerBooking = [];
        if ($bids !== []) {
            foreach (Database::fetchAll('SELECT booking_id, COUNT(*) c FROM booking_legs WHERE booking_id IN (' . implode(',', $bids) . ') GROUP BY booking_id') as $lr) {
                $legsPerBooking[(int) $lr['booking_id']] = max(1, (int) $lr['c']);
            }
        }
        $extrasOf = static fn(array $r): float => round(max(0.0, (float) ($r['luggage_fee'] ?? 0)) + max(0.0, (float) ($r['infant_fee'] ?? 0)), 2);
        $xBook  = [];
        $xFirst = [];
        foreach ($rows as $r) {
            $bid = (int) ($r['booking_id'] ?? 0);
            if (!isset($xFirst[$bid])) { $xFirst[$bid] = (int) ($r['pax_id'] ?? 0); }
            $lug = max(0, (int) ($r['luggage_pieces'] ?? 0));
            $inf = max(0, (int) ($r['infant_count'] ?? 0));
            if ($lug === 0 && $inf === 0 && $extrasOf($r) <= 0) { continue; }
            if (!isset($xBook[$bid])) {
                $xBook[$bid] = [
                    'pnr' => (string) $r['pnr'], 'lead' => (string) $r['full_name'], 'phone' => (string) ($r['contact_phone'] ?? ''),
                    'mode' => (string) (($r['booking_mode'] ?? '') ?: 'sharing'),
                    'seats' => [], 'lug' => $lug, 'lugFee' => max(0.0, (float) ($r['luggage_fee'] ?? 0)),
                    'inf' => $inf, 'infFee' => max(0.0, (float) ($r['infant_fee'] ?? 0)),
                    'amt' => round($extrasOf($r) / ($legsPerBooking[$bid] ?? 1), 2),
                    'state' => $extrasOf($r) > 0 ? ($mfState[$bid] ?? 'due') : 'free',
                ];
            }
            $xBook[$bid]['seats'][] = (string) $r['seat_no'];
        }
        $xInfants = array_sum(array_map(static fn(array $x): int => $x['inf'], $xBook));
        $extraOnRow = static function (array $r) use ($xBook, $xFirst): float {
            $bid = (int) ($r['booking_id'] ?? 0);
            return (isset($xBook[$bid]) && ($xFirst[$bid] ?? -1) === (int) ($r['pax_id'] ?? 0)) ? (float) $xBook[$bid]['amt'] : 0.0;
        };
        $farePer = static function (array $r) use ($paxTotalPerBooking, $extrasOf): float {
            $n = max(1, (int) ($paxTotalPerBooking[(int) ($r['booking_id'] ?? 0)] ?? 1));
            return round(max(0.0, (float) $r['total_amount'] - $extrasOf($r)) / $n);
        };
        /* money per ROW: ticket = the seat's share; नगद / अनलाइन by paidWay(); the
           extras ride with the fare on the booking's first row, only where they came in */
        $money = static function (array $r) use ($farePer, $extraOnRow, $mfState, $mfTicks): array {
            $fare   = (float) $farePer($r);
            $extra  = (float) $extraOnRow($r);
            $bid    = (int) ($r['booking_id'] ?? 0);
            $way    = (($r['pay_status'] ?? '') === 'verified') ? self::paidWay($r, $mfTicks[$bid] ?? null) : '';
            $xIn    = (($mfState[$bid] ?? 'due') !== 'due') ? $extra : 0.0;
            $cash   = $way === 'cash' ? $fare + $xIn : 0.0;
            $online = $way === 'online' ? $fare + $xIn : 0.0;
            return ['ticket' => $fare, 'extra' => $extra, 'cash' => $cash, 'online' => $online];
        };

        /* pay at boarding: the booking's fare share + extras still due on THIS bus */
        $pmBook = []; $pmDue = []; $pmPax = []; $pmLead = []; $pmClaim = [];
        foreach ($rows as $r) {
            $bid = (int) ($r['booking_id'] ?? 0);
            if (!isset($pmBook[$bid])) {
                $pmBook[$bid]  = self::payMode($r, $mfState[$bid] ?? 'due', $mfTicks[$bid] ?? null);
                $pmDue[$bid]   = 0.0;
                $pmPax[$bid]   = 0;
                $pmLead[$bid]  = (string) ($r['full_name'] ?? '');
                $pmClaim[$bid] = $pmBook[$bid] === 'boarding' && self::payClaimSent($r);
            }
            $pmPax[$bid]++;
            if ($pmBook[$bid] === 'boarding') {
                $pmDue[$bid] += $farePer($r) + $extraOnRow($r);
            }
        }
        $boardDue = 0.0; $boardDueN = 0;
        foreach ($pmBook as $bid => $pmMode) {
            if ($pmMode === 'boarding') { $boardDue += $pmDue[$bid]; $boardDueN += $pmPax[$bid]; }
        }

        /* NP-2: the same money in the desk's own currency, sale by sale.
           No ?desk= but EVERY row was cut at one and the same non-rupee desk
           (an NPJ agent's copy, a bus sold only at Nepalgunj): that desk's
           money too - INR and NPR are still never added together, because a
           single online / other-desk row keeps the copy in rupees.
           Setting chalani_auto_desk_currency (default on). */
        $deskAuto = false;
        if ($desk === '' && $rows !== [] && Settings::getBool('chalani_auto_desk_currency', true)) {
            $codes = array_values(array_unique(array_map(static fn(array $r): string => strtoupper(trim((string) ($r['counter_code'] ?? ''))), $rows)));
            if (count($codes) === 1 && $codes[0] !== '' && self::deskCurrency($codes[0]) !== null) {
                $desk     = self::deskCode($codes[0]);
                $deskAuto = $desk !== '';
            }
        }
        $cur    = self::deskCurrency($desk);
        $bRate  = [];
        foreach ($rows as $r) {
            $bid = (int) ($r['booking_id'] ?? 0);
            if (!isset($bRate[$bid])) { $bRate[$bid] = $cur !== null ? self::rateFor($r, $desk) : 1.0; }
        }
        $rateOf = static fn(array $r): float => $bRate[(int) ($r['booking_id'] ?? 0)] ?? ($cur !== null ? (float) $cur['rate'] : 1.0);
        $moneyLocal = static function (array $r) use ($money, $rateOf): array {
            $m = $money($r);
            $k = $rateOf($r);
            return array_map(static fn(float $v): float => (float) round($v * $k), $m);
        };

        /* the whole-bus totals (manifest chalani branch, 16 Nov 2026) */
        $tTicket = 0.0; $tCash = 0.0; $tOnline = 0.0; $tDisc = 0.0; $tExtra = 0.0; $seenB = [];
        $lTicket = 0.0; $lCash = 0.0; $lOnline = 0.0; $lDisc = 0.0; $lExtra = 0.0;
        foreach ($rows as $r) {
            $m = $money($r);
            $tTicket += $m['ticket']; $tCash += $m['cash']; $tOnline += $m['online']; $tExtra += $m['extra'];
            $k = $rateOf($r);
            $lTicket += round($m['ticket'] * $k); $lCash += round($m['cash'] * $k); $lOnline += round($m['online'] * $k); $lExtra += round($m['extra'] * $k);
            $bid = (int) ($r['booking_id'] ?? 0);
            if ($bid > 0 && !isset($seenB[$bid])) {
                $seenB[$bid] = true;
                $baseAmt = (float) ($r['base_total'] ?? 0);
                if ($baseAmt > 0) {
                    $d = max(0.0, $baseAmt - max(0.0, (float) ($r['total_amount'] ?? 0) - $extrasOf($r)));
                    $tDisc += $d;
                    $lDisc += round($d * $k);
                }
            }
        }
        $xPaid = 0.0; $xDue = 0.0; $lxPaid = 0.0; $lxDue = 0.0;
        foreach ($xBook as $xbid => $xb) {
            $k = $bRate[(int) $xbid] ?? 1.0;
            if ($xb['state'] === 'due') { $xDue += $xb['amt']; $lxDue += round($xb['amt'] * $k); }
            else { $xPaid += $xb['amt']; $lxPaid += round($xb['amt'] * $k); }
        }
        $lBoard = 0.0;
        foreach ($pmBook as $bid => $pmMode) {
            if ($pmMode === 'boarding') { $lBoard += round($pmDue[$bid] * ($bRate[(int) $bid] ?? 1.0)); }
        }
        $totals = [
            'passengers' => count($rows), 'ticket' => $tTicket, 'cash' => $tCash,
            'online' => $tOnline, 'discount' => $tDisc, 'net' => $tCash + $tOnline,
            'extras' => $tExtra, 'extrasPaid' => $xPaid, 'extrasDue' => $xDue, 'infants' => $xInfants,
            'boardDue' => round($boardDue, 2), 'boardDueN' => $boardDueN,
        ];
        $local = $cur === null ? null : [
            'passengers' => count($rows), 'ticket' => $lTicket, 'cash' => $lCash,
            'online' => $lOnline, 'discount' => $lDisc, 'net' => $lCash + $lOnline,
            'extras' => $lExtra, 'extrasPaid' => $lxPaid, 'extrasDue' => $lxDue, 'infants' => $xInfants,
            'boardDue' => $lBoard, 'boardDueN' => $boardDueN,
        ];

        return [
            'sid' => $sid, 'routeId' => $routeId, 'rows' => $rows, 'ticks' => $mfTicks, 'state' => $mfState,
            'paxTotal' => $paxTotalPerBooking, 'bids' => $bids, 'legs' => $legsPerBooking,
            'xBook' => $xBook, 'xFirst' => $xFirst, 'xInfants' => $xInfants,
            'pmBook' => $pmBook, 'pmDue' => $pmDue, 'pmPax' => $pmPax, 'pmLead' => $pmLead, 'pmClaim' => $pmClaim,
            'boardDue' => $boardDue, 'boardDueN' => $boardDueN,
            'extrasOf' => $extrasOf, 'extraOnRow' => $extraOnRow, 'farePer' => $farePer, 'money' => $money,
            'totals' => $totals, 'desk' => $desk, 'deskAuto' => $deskAuto, 'cur' => $cur, 'local' => $local,
            'moneyLocal' => $moneyLocal, 'rateOf' => $rateOf,
        ];
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
            'cur'  => (string) ($fmt['cur']['code'] ?? 'INR'),   // 19 Nov 2026 (NP-2)
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
                // 16 Nov 2026: desk extras (luggage / infant) are drawn too
                $r['luggage_pieces'] ?? 0, $r['luggage_fee'] ?? 0, $r['infant_count'] ?? 0, $r['infant_fee'] ?? 0,
            ], $rows),
            // 16 Nov 2026: the pay-at-boarding / cash / online mark each row carries
            'paymark' => isset($fmt['payMark']) && is_callable($fmt['payMark'])
                ? array_map(static fn(array $r): string => implode('|', array_map('strval', (array) $fmt['payMark']($r))), $rows) : [],
            'xstate' => isset($fmt['rowState']) && is_callable($fmt['rowState'])
                ? array_map(static fn(array $r): string => (string) $fmt['rowState']($r), $rows) : [],
            'seller' => $seller,
            'stops'  => (string) ($fmt['stopsLineRoman'] ?? $fmt['stopsLine'] ?? ''),
            'totals' => $totals,
            'places' => $fmt['placeList'] ?? [],          // 6 Nov 2026
            'bus'    => $fmt['busName'] ?? '',
            'genders' => array_map(static fn(array $r): string => (string) ($r['gender'] ?? ''), $rows),
            'strip'  => ChalaniStrip::on(),   // 25 Nov 2026 (release ch): the switch redraws the page
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
     * 6 Nov 2026 (owner: seat chart "bichma khali gap rakhera" on the chalani):
     * the LAST picture page — lower | upper floor, DOUBLE (2 berth) | aisle |
     * SINGLE (1 berth), each berth with the passenger's name and pickup place
     * number; a woman on a single sleeper in pink. admin/manifest.php serves
     * it as page pageCount()+1. Cached by the same fingerprint as the sheet.
     *
     * @param array<string,mixed> $fmt  needs layout, seatInfo (+ busName, placeList)
     * @return array{path:string,file:string,page:int,pages:int,fresh:bool,fingerprint:string}
     */
    public static function renderChart(array $trip, array $rows, string $date, array $totals, array $fmt, int $page, int $pages): array
    {
        $fp   = self::fingerprint($trip, $rows, $date, $totals, $fmt);
        $dir  = rtrim(UPLOAD_PATH, '/\\') . '/chalani/' . $date;
        $file = self::busLabel($trip) . '-S' . (int) ($trip['schedule_id'] ?? 0) . '-seatchart-' . substr($fp, 0, 8) . '.png';
        $path = $dir . '/' . $file;
        if (is_file($path) && filesize($path) > 0) {
            return ['path' => $path, 'file' => $file, 'page' => $page, 'pages' => $pages, 'fresh' => false, 'fingerprint' => $fp];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the chalani folder.');
        }
        foreach ((array) @glob($dir . '/' . self::busLabel($trip) . '-S' . (int) ($trip['schedule_id'] ?? 0) . '-seatchart-*.png') as $old) {
            if (is_string($old)) { @unlink($old); }
        }

        $W = self::WIDTH; $H = self::HEIGHT; $M = self::M;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $c = static fn(string $hex): int => imagecolorallocate($im, (int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
        $white = $c('#FFFFFF'); $navy = $c('#0A2A8F'); $ink = $c('#1A1A2E'); $muted = $c('#5F5747'); $goldL = $c('#F3DE8A');
        $rose = $c('#C2185B'); $head = $c('#E4E8EF'); $cream = $c('#FFF8E7');
        $pal = [
            'free' => [$c('#EAF7EE'), $c('#9FD3AE'), $c('#14532D')],
            'busy' => [$c('#DCE8FB'), $c('#6E95D8'), $navy],
            'w'    => [$c('#FDE7F2'), $c('#E06AA8'), $c('#9D1C5E')],
            'ws'   => [$c('#F8BBD9'), $rose, $c('#880E4F')],
        ];
        imagefilledrectangle($im, 0, 0, $W, $H, $cream);
        imagefilledrectangle($im, 0, 0, $W, 92, $navy);
        $coach = (string) ($trip['coach_type'] ?? 'sleeper');
        $ws = (int) ($fmt['womenSingleCount'] ?? 0);
        self::t($im, 30, $M, 14, $white, 'SEAT CHART  ·  ' . self::chalaniNo($trip, $date), true);
        self::t($im, 17, $M, 56, $goldL, self::fit(17, self::ro((string) ($fmt['busName'] ?? '')) . '  ·  ' . strtoupper(formatDate($date, 'D, j M Y'))
            . '  ·  ' . count($rows) . ' PASSENGERS' . ((int) ($totals['infants'] ?? 0) > 0 ? ' + ' . (int) $totals['infants'] . ' INFANT' : '') . ($ws > 0 ? '  ·  WOMEN SINGLE ' . $ws : ''), $W - 2 * $M - 260), false);
        self::tR($im, 18, $W - $M, 34, $goldL, 'PAGE ' . $page . ' OF ' . $pages, true);

        $layout = (array) ($fmt['layout'] ?? []);
        $info   = (array) ($fmt['seatInfo'] ?? []);
        $decks  = (array) ($layout['decks'] ?? []);
        $gap = 36; $aisle = 56;
        $deckW = (int) (($W - 2 * $M - $gap * max(0, count($decks) - 1)) / max(1, count($decks)));
        // 6 Nov 2026: groups in DRAWN order (single column left of the aisle) — Seats::rowGroups
        $g0 = Seats::rowGroups((array) ($decks[0]['rows'][0] ?? []), $layout);
        $nL = max(1, count($g0[0][0]));
        $nR = max(1, count($g0[1][0]));
        $hs = ['single' => 'SINGLE SLEEPER · 1 BERTH', 'double' => 'DOUBLE SLEEPER · 2 BERTHS', 'seat' => ''];
        $cellW = (int) (($deckW - 28 - $aisle) / ($nL + $nR));
        $maxRows = $decks !== [] ? max(array_map(static fn(array $d): int => count($d['rows']), $decks)) : 1;
        $top = 120;
        $cellH = (int) min(150, max(70, ($H - $top - 70 - 90) / max(1, $maxRows) - 12));
        foreach ($decks as $di => $dk) {
            $dx = $M + $di * ($deckW + $gap);
            $deckH = 70 + count($dk['rows']) * ($cellH + 12) + 6;
            self::roundRect($im, $dx, $top, $dx + $deckW, $top + $deckH, 16, $white, $c('#C9D2E6'));
            self::t($im, 22, $dx + 18, $top + 10, $navy, self::ro((string) $dk['label']), true);
            if ($coach === 'sleeper') {
                $lx2 = $dx + 14 + $nL * $cellW;
                self::roundRect($im, $dx + 14, $top + 44, $lx2, $top + 64, 8, $head, null);
                self::tC($im, 13, $dx + 14, $lx2, $top + 46, $ink, $hs[$g0[0][1]], true);
                self::roundRect($im, $lx2 + $aisle, $top + 44, $lx2 + $aisle + $nR * $cellW, $top + 64, 8, $head, null);
                self::tC($im, 13, $lx2 + $aisle, $lx2 + $aisle + $nR * $cellW, $top + 46, $ink, $hs[$g0[1][1]], true);
            }
            $ry = $top + 72;
            foreach ($dk['rows'] as $ri => $rw) {
                $cells = [];
                [$ga, $gb] = Seats::rowGroups($rw, $layout);
                foreach ($ga[0] as $k => $bed) { $cells[] = [$dx + 14 + $k * $cellW, $bed]; }
                foreach ($gb[0] as $k => $bed) { $cells[] = [$dx + 14 + $nL * $cellW + $aisle + $k * $cellW, $bed]; }
                foreach ($cells as [$x0, $bed]) {
                    $i = $info[$bed] ?? null;
                    $k = $i === null ? 'free' : (!empty($i['ws']) ? 'ws' : (!empty($i['woman']) ? 'w' : 'busy'));
                    [$fill, $edge, $txtc] = $pal[$k];
                    self::roundRect($im, $x0 + 4, $ry, $x0 + $cellW - 4, $ry + $cellH, 12, $fill, null);
                    imagesetthickness($im, $k === 'ws' ? 4 : 2);
                    imagerectangle($im, $x0 + 4, $ry, $x0 + $cellW - 4, $ry + $cellH, $edge);
                    imagesetthickness($im, 1);
                    self::tC($im, 22, $x0, $x0 + $cellW, $ry + 8, $txtc, Seats::displayLabel((string) $bed, $coach, 'sharing'), true);
                    if ($i !== null && $cellH >= 80) {
                        $first = Ticket::display((string) (explode(' ', trim((string) $i['name']))[0] ?? ''));
                        self::tC($im, 15, $x0, $x0 + $cellW, $ry + 44, $ink, self::fit(15, $first, $cellW - 16), false);
                        $tag = ((int) $i['place'] > 0 ? 'P' . (int) $i['place'] : '') . (!empty($i['ws']) ? ' · WOMAN' : '');
                        self::tC($im, 13, $x0, $x0 + $cellW, $ry + 70, !empty($i['ws']) ? $rose : $navy, self::fit(13, trim($tag, ' ·'), $cellW - 12), true);
                    }
                }
                if ($ri === intdiv(count($dk['rows']), 2)) {
                    $ax = $dx + 14 + $nL * $cellW;
                    foreach (str_split('AISLE') as $q => $ch) { self::tC($im, 13, $ax, $ax + $aisle, $ry - 8 + $q * 18, $muted, $ch, true); }
                }
                $ry += $cellH + 12;
            }
        }
        // legend
        $ly = $H - 70; $lx = $M;
        foreach ([['free', 'Free'], ['busy', 'Booked'], ['w', 'Woman'], ['ws', 'Woman on single sleeper'], ['', 'P n = pickup place no.']] as [$k, $lab]) {
            if ($k !== '') {
                [$fill, $edge] = $pal[$k];
                self::roundRect($im, $lx, $ly, $lx + 34, $ly + 22, 5, $fill, $edge);
                $lx += 42;
            }
            self::t($im, 15, $lx, $ly + 2, $ink, $lab, false);
            $lx += self::w(15, $lab) + 36;
        }

        $tmp = $path . '.tmp';
        if (!imagepng($im, $tmp, 6)) { imagedestroy($im); @unlink($tmp); throw new RuntimeException('Could not write the seat chart.'); }
        imagedestroy($im);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not place the seat chart.'); }
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
        /* The GOLD template (9 Oct 2026) — the palette the ticket shares. */
        $white  = $c('#FFFFFF'); $ink   = $c('#1A1A2E'); $muted = $c('#5F5747'); $hair = $c('#DCC27C');
        $navy   = $c('#0A2A8F'); $royal = $c('#1A44C8'); $maroon = $c('#C2540A');
        $goldD  = $c('#8A6A1F'); $gold  = $c('#C9A227'); $goldL = $c('#F3DE8A'); $cream = $c('#FFF8E7');
        $green  = $c('#157A47'); $pale  = $c('#EFE3BF'); $dim   = $c('#C9D2E6'); $sub = $c('#F7EBC2');

        imagefilledrectangle($im, 0, 0, $W, $H, $cream);
        /* Faint mandala behind the table — baked into the paper. */
        GoldSheet::watermark($im, (int) ($W / 2), 880, 430, 112);

        $co        = Settings::company();
        $gstin     = trim(Settings::getString('company_gstin', ''));   // 10 Oct 2026 (trust): printed only once the office has one
        $roman     = static fn(string $s): string => self::ro($s);
        $pages     = count($chunks);
        $pagesShown = $pages + (((array) ($fmt['layout']['decks'] ?? [])) !== [] ? 1 : 0);   // + the seat-chart page (6 Nov 2026)
        $chalaniNo = self::chalaniNo($trip, $date);

        /* ================= mantra strip ================= */
        imagefilledrectangle($im, 0, 0, $W, 30, $c('#071E6B'));
        imageline($im, 0, 30, $W, 30, $goldD);
        $mantra = GoldSheet::mantra();
        if ($mantra !== '') {
            $mw = self::w(14, $mantra);
            self::t($im, 14, (int) (($W - $mw) / 2), 5, $goldL, $mantra, true);
            GoldSheet::flourish($im, (int) (($W - $mw) / 2) - 150, 16, 220);
            GoldSheet::flourish($im, (int) (($W + $mw) / 2) + 150, 16, 220);
        }

        /* ================= header band ================= */
        GoldSheet::headerGradient($im, 0, 31, $W, 196);
        /* Sagarmatha, the prayer flags, Gautam Buddha and the stupa as a faint
           wash behind the right half of the band (9 Oct 2026). */
        if (DocArt::heritageOn()) {
            DocArt::bandWash($im, 1040, 31, $W, 196, 20, 0.5, 0.7);
        }
        GoldSheet::foilRule($im, 0, $W, 196, GoldSheet::NAVY);

        /* The emblem on an embossed gold seal. */
        GoldSheet::seal($im, $M + 73, 113, 146);
        $tx = $M + 146 + 30;
        $l1 = $roman($co['legal'] !== '' ? $co['legal'] : $co['name']);
        $l2 = $roman($co['address']);
        $l3 = $roman('Phone ' . $co['phone'] . '   |   Operator ' . $co['operator'] . '   |   ' . $co['email']);
        $l4 = $roman('CIN ' . $co['cin'] . '   |   ' . $co['web']);
        self::t($im, 36, $tx + 2, 42, $c('#050B1A'), $l1, true);      // engraved shadow
        self::t($im, 36, $tx, 40, $goldL, $l1, true);
        self::t($im, 16, $tx, 96, $pale, $l2, false);
        self::t($im, 16, $tx, 122, $pale, $l3, false);
        self::t($im, 15, $tx, 148, $dim, $l4, false);

        /* Right: what this document IS, then the three fields the office
           fills in by hand on the paper form — printed here already. */
        $rx = $W - $M;
        self::tR($im, 32, $rx, 36, $white, 'BUS CHALANI', true);
        $flH = 30;
        DocArt::flagPair($im, $rx - self::w(32, 'BUS CHALANI') - 22 - DocArt::flagPairWidth($flH, 8), 44, $flH, 8, 'IN');
        $artL = $tx + max(self::w(36, $l1), self::w(16, $l2), self::w(16, $l3), self::w(15, $l4)) + 40;
        $artR = $rx - 300 - 140;
        if (DocArt::heritageOn() && $artR - $artL >= 330) {
            $aw  = min(460, $artR - $artL);
            $ax1 = $artR - $aw;
            DocArt::band($im, $ax1, 40, $artR, 150, 14, [10, 42, 143], 0.5, 0.8);
            self::tC($im, 13, $ax1, $artR, 158, $goldL, 'सगरमाथा · Sagarmatha  ·  लुम्बिनी · Lumbini', false);
        }
        $coach = strtoupper($roman(trim((string) ($trip['coach_type'] ?? ''))));
        self::tR($im, 15, $rx, 80, $goldL,
            'PASSENGER SHEET' . ($coach !== '' ? '  ·  ' . $coach . ' COACH' : ''), false);

        $ky = 106;
        foreach ([
            ['CHALANI NO.', $chalaniNo],
            ['DATE',        strtoupper(formatDate($date, 'D, j M Y'))],
            ['BUS NO.',     strtoupper(trim((string) ($trip['bus_number'] ?? '')) ?: '-')],
        ] as [$k, $v]) {
            self::tR($im, 14, $rx - 300, $ky + 5, $dim, $k, false);
            imageline($im, $rx - 292, $ky + 28, $rx, $ky + 28, $goldD);
            self::t($im, 19, $rx - 288, $ky, $white, $roman($v), true);
            $ky += 30;
        }

        /* ================= payment QR (EVERY page) ================= */
        /* Owner, 9 Oct 2026: the chalani always carries a payment QR. The
           company UPI with this chalani as the note; else the office. */
        $pay  = GoldSheet::payment($chalaniNo);
        $side = GoldSheet::qrSide($pay['payload'], 200);
        $pw   = 540;
        $px1  = $W - $M - $pw;
        $py1  = 212;
        $py2  = 420;
        GoldSheet::card($im, $px1, $py1, $W - $M, $py2, 14, [255, 253, 246]);
        $qx = $W - $M - 14 - $side;
        $qy = $py1 + intdiv($py2 - $py1 - $side, 2);
        GoldSheet::foilRound($im, $qx - 5, $qy - 5, $qx + $side + 4, $qy + $side + 4, 6);
        imagefilledrectangle($im, $qx - 1, $qy - 1, $qx + $side, $qy + $side, $white);
        GoldSheet::qr($im, $pay['payload'], $qx, $qy, 200);
        $tcx = $px1 + 20;
        $tcw = $qx - 18 - $tcx;
        self::t($im, 18, $tcx, $py1 + 14, $maroon, self::fit(18, $pay['title'], $tcw), true);
        GoldSheet::flourish($im, $tcx + 70, $py1 + 52, 120);
        self::t($im, 12, $tcx, $py1 + 64, $goldD, $pay['kind'] === 'upi' ? 'UPI ID' : 'OFFICE', true);
        $l1p = $pay['kind'] === 'upi' ? (string) preg_replace('/^UPI:\s*/', '', $pay['line1']) : $pay['line1'];
        self::t($im, 16, $tcx, $py1 + 82, $ink, self::fit(16, $roman($l1p), $tcw), true);
        self::t($im, 13, $tcx, $py1 + 110, $muted, self::fit(13, $roman($pay['line2']), $tcw), false);
        self::t($im, 12, $tcx, $py1 + 138, $goldD, 'REF', true);
        self::t($im, 15, $tcx, $py1 + 155, $navy, self::fit(15, $chalaniNo, $tcw), true);
        self::t($im, 12, $tcx, $py1 + 182, $muted,
            self::fit(12, $pay['kind'] === 'upi' ? 'Scan with any UPI app' : 'Scan to reach the office', $tcw), false);

        /* ================= trip strip ================= */
        $y = 212;
        $stripW = $px1 - 16 - $M;
        $boxW = (int) (($stripW - 3 * 12) / 4);
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
            GoldSheet::card($im, $bx, $y, $bx + $boxW, $y + 84, 10, [255, 253, 246], GoldSheet::GOLD, 6);
            imagefilledrectangle($im, $bx + 2, $y + 12, $bx + 5, $y + 72, $gold);   // gold tab
            self::t($im, 13, $bx + 18, $y + 12, $goldD, $k, true);
            $vs = self::w(24, $v) <= $boxW - 34 ? 24 : 20;
            self::t($im, $vs, $bx + 18, $y + ($vs === 24 ? 38 : 42), $navy, self::fit($vs, $v, $boxW - 34), true);
        }
        $y += 96;

        /* Boarding points, in travel order (Roman-digit twin of the PDF line). */
        $stopsLine = $roman((string) ($fmt['stopsLineRoman'] ?? $fmt['stopsLine'] ?? ''));
        $stopsLine = trim((string) preg_replace('/\(\s*:?\s*\)/', '', $stopsLine));
        /* 6 Nov 2026: the place-wise head-count replaces the plain stops line —
           "BUS ... · 23 PASSENGERS · 1 Surat 8 · 2 Kamrej 3 ..." */
        $places = (array) ($fmt['placeList'] ?? []);
        if ($places !== []) {
            $ws  = (int) ($fmt['womenSingleCount'] ?? 0);
            $hd  = 'BUS ' . $roman((string) ($fmt['busName'] ?? '')) . '  ·  ' . count($rows) . ' PASSENGERS' . ((int) ($totals['infants'] ?? 0) > 0 ? ' + ' . (int) $totals['infants'] . ' INFANT (LAP)' : '') . ($ws > 0 ? '  ·  WOMEN SINGLE ' . $ws : '') . '  ·  ';
            $pl  = implode('   ', array_map(static fn(array $p): string => (int) $p['no'] . '. ' . self::ro((string) $p['name']) . ' ' . (int) $p['n'], $places));
            self::t($im, 15, $M, $y, $navy, self::fit(15, $hd, $stripW), true);
            self::t($im, 15, $M + self::w(15, $hd) + 4, $y, $ink, self::fit(15, $pl, max(40, $stripW - self::w(15, $hd) - 4)), false);
        } elseif ($stopsLine !== '') {
            self::t($im, 15, $M, $y, $muted, self::fit(15, 'Boarding points: ' . $stopsLine, $stripW), false);
        }
        $y += 28;

        self::t($im, 20, $M, $y, $navy, strtoupper(
            $roman((string) ($trip['from_city'] ?? 'Gujarat')) . ' - '
            . $roman((string) ($trip['to_city'] ?? 'Rupaidiha')) . '  ·  PASSENGER RECORD'
        ), true);
        self::tR($im, 16, $px1 - 16, $y + 3, $maroon, 'PAGE ' . $page . ' OF ' . $pagesShown, true);
        /* 25 Nov 2026 (release ch, uniform chalani): the money strip - जम्मा यात्री Total |
           नगद Cash | अनलाइन Online | ड्यू बाँकी Due - on every page, from the same $money /
           payMark the table reads (ChalaniStrip: each rupee in one bucket). Switch
           chalani_money_strip_on; off = the gold flourish, as before. */
        $strip = (ChalaniStrip::on() && isset($fmt['money'], $fmt['payMark']) && is_callable($fmt['money']) && is_callable($fmt['payMark']))
            ? ChalaniStrip::fromRows($rows, $fmt['money'], $fmt['payMark']) : null;
        if ($strip !== null) {
            $devOk = class_exists('DevShape');   // Devanagari labels only where HarfBuzz shapes them (rule 5: never broken glyphs)
            $lab   = $devOk ? ['जम्मा यात्री · TOTAL', 'नगद · CASH', 'अनलाइन · ONLINE', 'ड्यू बाँकी · DUE'] : ['TOTAL PASSENGERS', 'CASH', 'ONLINE', 'DUE'];
            $cl0   = (string) ($fmt['cur']['latin'] ?? 'Rs');
            $val   = [
                $strip['passengers'] . '  ·  ' . $cl0 . ' ' . number_format($strip['amount']),
                $cl0 . ' ' . number_format($strip['cash']),
                $cl0 . ' ' . number_format($strip['online']),
                $cl0 . ' ' . number_format($strip['due']) . ($strip['dueN'] > 0 ? '  ·  ' . $strip['dueN'] . ' PAX' : ''),
            ];
            $dueHot = $strip['due'] > 0;
            $tone = [[$navy, [255, 253, 246]], [$green, [236, 253, 243]], [$green, [236, 253, 243]],
                     [$dueHot ? $maroon : $green, $dueHot ? [254, 242, 242] : [236, 253, 243]]];
            /* review 25 Nov 2026: settled money that is neither cash nor online (an office
               tick with no verified payment) gets a fifth chip, so the chips add up to TOTAL */
            if (ChalaniStrip::hasOther($strip)) {
                $lab[]  = $devOk ? 'अन्य भुक्तानी · OTHER PAID' : 'OTHER PAID';
                $val[]  = $cl0 . ' ' . number_format($strip['other']);
                $tone[] = [$goldD, [255, 253, 246]];
            }
            $nChip = count($lab);
            $chipW = (int) (($stripW - ($nChip - 1) * 12) / $nChip);
            $sy1 = $y + 31; $sy2 = self::TABLE_Y - 7;
            foreach ($lab as $i => $lb) {
                $bx = $M + $i * ($chipW + 12);
                GoldSheet::card($im, $bx, $sy1, $bx + $chipW, $sy2, 10, $tone[$i][1], GoldSheet::GOLD, 4);
                imagefilledrectangle($im, $bx + 2, $sy1 + 8, $bx + 6, $sy2 - 8, $tone[$i][0]);
                self::t($im, 12, $bx + 16, $sy1 + 4, $goldD, self::fit(12, $lb, $chipW - 24), true);
                self::t($im, 19, $bx + 16, $sy1 + 22, $tone[$i][0], self::fit(19, $val[$i], $chipW - 24), true);
            }
        } else {
            GoldSheet::flourish($im, $M + (int) ($stripW / 2), $y + 52, $stripW - 40);
        }

        /* ================= table ================= */
        [$startIdx, $rowCount] = $chunks[$page - 1];
        $drawRows = $page === $pages ? max($rowCount, self::ROWS_LAST) : $rowCount;

        $cl    = (string) ($fmt['cur']['latin'] ?? 'Rs');   // 19 Nov 2026 (NP-2): NPR on a Nepal desk copy
        $heads = ['#', 'SEAT', 'PASSENGER NAME', 'MOBILE', 'BOARDING', 'DROP',
                  'FARE ' . $cl, 'CASH ' . $cl, 'ONLINE ' . $cl, 'AGENT / CODE', 'REMARKS'];
        $cw    = [52, 84, 430, 176, 196, 196, 116, 116, 130, 190];
        $cw[]  = $inner - array_sum($cw);                 // REMARKS takes the rest
        $align = ['c', 'c', 'l', 'l', 'l', 'l', 'r', 'r', 'r', 'l', 'l'];
        $cx    = [];
        $x     = $M;
        foreach ($cw as $i => $wd) { $cx[$i] = $x; $x += $wd; }

        $ty  = self::TABLE_Y;
        $tbT = $ty;
        /* Gold-foil head row, navy letters. */
        GoldSheet::foil($im, $M, $ty, $W - $M, $ty + self::HEAD_H);
        foreach ($heads as $i => $h) {
            $hy = $ty + 11;
            if ($align[$i] === 'c')     { self::tC($im, 16, $cx[$i], $cx[$i] + $cw[$i], $hy, $navy, $h, true); }
            elseif ($align[$i] === 'r') { self::tR($im, 16, $cx[$i] + $cw[$i] - 10, $hy, $navy, $h, true); }
            else                        { self::t($im, 16, $cx[$i] + 10, $hy, $navy, $h, true); }
            if ($i > 0) { imageline($im, $cx[$i], $ty + 6, $cx[$i], $ty + self::HEAD_H - 6, $goldD); }
        }
        $ty += self::HEAD_H;

        $money    = $fmt['money'];
        $stopShrt = $fmt['stopShort'];
        $isLate   = $fmt['isLate'];
        $bookedBy = $fmt['bookedBy'];

        $pTicket = 0.0; $pCash = 0.0; $pOnline = 0.0; $pPax = 0;
        $zebraA = GoldSheet::col($im, [255, 255, 255], 72);
        $zebraB = GoldSheet::col($im, [244, 228, 184], 68);
        $pink   = $c('#F8BBD9');   // 6 Nov 2026: woman on a single sleeper
        $rose   = $c('#C2185B');
        $placeNo = isset($fmt['placeNo']) && is_callable($fmt['placeNo']) ? $fmt['placeNo'] : static fn(string $s): int => 0;

        for ($n = 0; $n < $drawRows; $n++) {
            $idx = $startIdx + $n;
            $r   = $rows[$idx] ?? null;
            $ry  = $ty + $n * self::ROW_H;

            $wsRow = $r !== null && isset($fmt['womanSingle']) && is_callable($fmt['womanSingle']) && $fmt['womanSingle']($r);
            imagefilledrectangle($im, $M, $ry, $W - $M, $ry + self::ROW_H - 1, $wsRow ? $pink : ($n % 2 === 0 ? $zebraA : $zebraB));
            imageline($im, $M, $ry + self::ROW_H, $W - $M, $ry + self::ROW_H, $hair);
            foreach ($cw as $i => $wd) {
                if ($i > 0) { imageline($im, $cx[$i], $ry, $cx[$i], $ry + self::ROW_H, $hair); }
            }

            $vy = $ry + 10;
            self::tC($im, 15, $cx[0], $cx[0] + $cw[0], $vy + 2, $goldD, (string) ($idx + 1), true);
            if ($r === null) {
                continue;              // a clean ruled blank, for the pen
            }
            $pPax++;
            $m = $money($r);
            $pTicket += $m['ticket']; $pCash += $m['cash']; $pOnline += $m['online'];
            $late = (bool) $isLate($r);

            self::tC($im, 19, $cx[1], $cx[1] + $cw[1], $vy, $wsRow ? $rose : $navy, Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing')), true);

            /* Name and ticket number share ONE line (a Devanagari name is
               taller and its descenders ran through a stacked number). */
            $name = Ticket::display((string) ($r['full_name'] ?? ''));
            $tkt  = $roman((string) (($r['ticket_number'] ?? '') ?: ($r['pnr'] ?? '')));
            if ($name === '') { $name = $tkt !== '' ? $tkt : '(name not in Latin or Devanagari)'; }
            $rInf = isset($fmt['infantsOf']) && is_callable($fmt['infantsOf']) ? (int) $fmt['infantsOf']($r) : 0;   // 16 Nov 2026
            if ($rInf > 0) { $name .= ' +' . $rInf . ' infant'; }
            $tktW = $tkt !== '' ? self::w(11, $tkt) + 14 : 0;
            self::t($im, 18, $cx[2] + 10, $vy + 1, $ink, self::fit(18, $name, $cw[2] - 20 - $tktW), false);
            if ($tkt !== '') {
                self::tR($im, 11, $cx[2] + $cw[2] - 8, $vy + 9, $late ? $maroon : $muted, $tkt, false);
            }

            $ph = (string) ($r['contact_phone'] ?? '');
            self::t($im, 18, $cx[3] + 10, $vy + 2, $ink,
                self::fit(18, $ph === '0000000000' ? '' : $roman($ph), $cw[3] - 20), false);

            $pk  = $r['boarding_stop'] ? (string) $stopShrt((string) $r['boarding_stop']) : '—';
            $pno = (int) $placeNo($pk);
            self::t($im, 17, $cx[4] + 10, $vy + 3, $ink,
                self::fit(17, ($pno > 0 ? $pno . ' · ' : '') . $roman($pk), $cw[4] - 20), false);
            self::t($im, 17, $cx[5] + 10, $vy + 3, $ink,
                self::fit(17, $roman((string) $stopShrt((string) $r['drop_stop'])), $cw[5] - 20), false);

            self::tR($im, 18, $cx[6] + $cw[6] - 10, $vy + 2, $ink, number_format($m['ticket']), false);
            if ($m['cash']   > 0) { self::tR($im, 18, $cx[7] + $cw[7] - 10, $vy + 2, $ink,   number_format($m['cash']),   false); }
            if ($m['online'] > 0) { self::tR($im, 18, $cx[8] + $cw[8] - 10, $vy + 2, $green, number_format($m['online']), false); }
            /* 16 Nov 2026 (pay at boarding): HOW the row pays (ChalaniPng::payMode via
               $fmt['payMark']). Money in a column gets a small PAID / AGENT / DUE word
               beside it; an empty pair reads ON BOARDING Rs X (the booking's amount, on
               its first row), ONLINE PENDING or TO COLLECT across both columns. */
            $pm   = isset($fmt['payMark']) && is_callable($fmt['payMark']) ? (array) $fmt['payMark']($r) : [];
            $mode = (string) ($pm['mode'] ?? '');
            if ($mode !== '' && ($m['cash'] > 0 || $m['online'] > 0)) {
                $tagCol = $m['cash'] > 0 ? 7 : 8;
                $tagTxt = $mode === 'agent' ? 'AGENT' : ($mode === 'due' ? 'DUE' : 'PAID');
                $tagC   = $mode === 'agent' ? $goldD : ($mode === 'due' ? $maroon : $green);
                if ($strip !== null) {
                    /* 25 Nov 2026 (release ch): a filled chip - PAID green, DUE red, AGENT gold -
                       sized to stay clear of the amount in the same cell */
                    $room = $cw[$tagCol] - 10 - self::w(18, number_format($tagCol === 7 ? $m['cash'] : $m['online'])) - 12;
                    $ts   = self::w(10, $tagTxt) + 8 <= $room ? 10 : 8;
                    $tw   = self::w($ts, $tagTxt);
                    self::roundRect($im, $cx[$tagCol] + 4, $vy + ($ts === 10 ? 4 : 6), $cx[$tagCol] + 10 + $tw, $vy + ($ts === 10 ? 22 : 20), 5, $tagC, null);
                    self::t($im, $ts, $cx[$tagCol] + 7, $vy + ($ts === 10 ? 7 : 8), $white, $tagTxt, true);
                } else {
                    self::t($im, 10, $cx[$tagCol] + 8, $vy + 8, $tagC, $tagTxt, true);
                }
            }
            if ($m['cash'] <= 0 && $m['online'] <= 0) {
                /* Nothing in either money column = the crew still collects (or the
                   office is checking an online payment). Written ACROSS both so it
                   never reads as an amount. */
                $across = $mode !== '' ? self::payModeLabel($mode, 'ro') : 'TO COLLECT';
                if ($mode === 'boarding' && !empty($pm['first']) && (float) ($pm['amt'] ?? 0) > 0) {
                    $across .= ' ' . $cl . ' ' . number_format((float) $pm['amt']);
                }
                $acrossCol = in_array($mode, ['', 'boarding', 'due'], true) ? $maroon : ($mode === 'pending' || $mode === 'agent' ? $goldD : $green);
                self::tC($im, 15, $cx[7], $cx[8] + $cw[8], $vy + 3, $acrossCol, self::fit(15, $across, $cw[7] + $cw[8] - 12), true);
            }

            /* WHO CUT THE TICKET (10 Sep 2026). A numbered agent gets a
               maroon code chip with a gold edge; office / online rows stay
               plain so the chips mean something. */
            $code  = strtoupper($roman((string) $bookedBy($r)));
            $who   = $roman((string) (($r['agent_name'] ?? '') ?: ($r['agent_user'] ?? '')));
            $codeY = $who !== '' ? $ry + 3 : $ry + 11;
            if (str_starts_with($code, 'SHG-')) {
                /* a 21px chip, so the seller name under it keeps its own line */
                $chW = self::w(14, $code) + 20;
                self::roundRect($im, $cx[9] + 9, $codeY - 2, $cx[9] + 11 + $chW, $codeY + 20, 9, $gold, null);
                self::roundRect($im, $cx[9] + 10, $codeY - 1, $cx[9] + 10 + $chW, $codeY + 19, 8, $maroon, null);
                self::t($im, 14, $cx[9] + 20, $codeY + 1, $white, $code, true);
            } else {
                self::t($im, 15, $cx[9] + 10, $codeY + 1, $royal, self::fit(15, $code, $cw[9] - 20), true);
            }
            if ($who !== '') {
                self::t($im, 12, $cx[9] + 10, $ry + 25, $muted, self::fit(12, $who, $cw[9] - 20), false);
            }

            /* 16 Nov 2026 (owner: "bisesh payment bhayo ki nai"): the booking's desk extras, paid or
               due - first, so fit() never trims the money off; a late / woman-single marker follows
               in short form (review: a late row used to lose its due extra here) */
            $xAmt = (float) ($m['extra'] ?? 0);
            $xSt  = isset($fmt['rowState']) && is_callable($fmt['rowState']) ? (string) $fmt['rowState']($r) : 'due';
            $parts = [];
            if ($xAmt > 0)  { $parts[] = 'Extra ' . $cl . ' ' . number_format($xAmt) . ' ' . ($xSt === 'due' ? 'DUE' : 'PAID'); }
            if (!empty($pm['claim'])) { $parts[] = self::payClaimLabel('ro'); }   // 16 Nov 2026: a UTR sent, the office checks it
            if ($late)      { $parts[] = $xAmt > 0 ? 'late' : 'Booked after departure'; }
            elseif ($wsRow) { $parts[] = $xAmt > 0 ? 'WOMAN single' : 'WOMAN · single sleeper'; }
            $rem = implode(' · ', $parts);
            if ($rem === '' && !empty($r['boarded_at']))     { $rem = 'Boarded'; }
            if ($rem === '' && !empty($r['special_need']))   { $rem = $roman((string) $r['special_need']); }
            if ($rem === '' && (string) ($r['booking_status'] ?? '') === 'pending') { $rem = 'Booking pending'; }
            $xDueRow = $xAmt > 0 && $xSt === 'due';
            self::t($im, 15, $cx[10] + 10, $vy + 3, ($late || $xDueRow) ? $maroon : ($wsRow ? $rose : $muted), self::fit(15, $rem, $cw[10] - 20), $wsRow || $xDueRow);
        }

        /* Page subtotal — a crew counting cash counts THIS sheet, not the trip. */
        $sy = $ty + $drawRows * self::ROW_H;
        imagefilledrectangle($im, $M, $sy, $W - $M, $sy + self::ROW_H, $sub);
        imageline($im, $M, $sy, $W - $M, $sy, $gold);
        self::t($im, 17, $cx[2] + 10, $sy + 11, $navy,
            'PAGE ' . $page . ' SUBTOTAL  ·  ' . $pPax . ' passenger' . ($pPax === 1 ? '' : 's'), true);
        self::tR($im, 18, $cx[6] + $cw[6] - 10, $sy + 11, $navy, number_format($pTicket), true);
        self::tR($im, 18, $cx[7] + $cw[7] - 10, $sy + 11, $navy, number_format($pCash),   true);
        self::tR($im, 18, $cx[8] + $cw[8] - 10, $sy + 11, $navy, number_format($pOnline), true);
        $sy += self::ROW_H;
        /* Gold frame round the whole table, with a soft lower-right shade. */
        imageline($im, $M + 3, $sy + 1, $W - $M + 1, $sy + 1, GoldSheet::col($im, GoldSheet::NAVY, 96));
        imageline($im, $W - $M + 1, $tbT + 3, $W - $M + 1, $sy + 1, GoldSheet::col($im, GoldSheet::NAVY, 96));
        imagerectangle($im, $M, $tbT, $W - $M, $sy, $gold);

        /* ================= summary + signatures (last page) ================= */
        if ($page === $pages) {
            $by = self::FOOT_Y - 8 - self::BOXES_H;      // anchored to the sheet, not to the table
            $halfW = (int) (($inner - 16) / 2);

            GoldSheet::card($im, $M, $by, $M + $halfW, $by + self::BOXES_H, 12, [255, 253, 246]);
            GoldSheet::foilRound($im, $M + 2, $by + 2, $M + $halfW - 2, $by + 38, 10);
            self::t($im, 17, $M + 18, $by + 9, $navy, 'PAYMENT SUMMARY  ·  ' . (isset($fmt['cur']['desk']) ? self::ro((string) $fmt['cur']['desk']) . ' DESK COPY · ' . $cl : 'WHOLE BUS'), true);
            /* 16 Nov 2026: 9 lines no longer fit one column of this 200px box, so it
               reads in two - the booking side left; the money side right (cash,
               online, and the pay-at-boarding money the crew still collects, never
               also counted in cash / online) - and NET COLLECTION across the foot. */
            $cols = [
                [
                    ['Total passengers',        number_format((float) ($totals['passengers'] ?? 0))],
                    ['Total ticket value',      $cl . ' ' . number_format((float) ($totals['ticket'] ?? 0))],
                    ['Discount / concession',   $cl . ' ' . number_format((float) ($totals['discount'] ?? 0))],
                    ['Special pay (luggage/infant)', $cl . ' ' . number_format((float) ($totals['extras'] ?? 0))],
                    ['   paid / due',           $cl . ' ' . number_format((float) ($totals['extrasPaid'] ?? 0)) . ' / ' . number_format((float) ($totals['extrasDue'] ?? 0))],
                ],
                [
                    ['Cash collected',          $cl . ' ' . number_format((float) ($totals['cash'] ?? 0))],
                    ['Online / UPI received',   $cl . ' ' . number_format((float) ($totals['online'] ?? 0))],
                    ['To collect on boarding',  (int) ($totals['boardDueN'] ?? 0) . ' pax  ·  ' . $cl . ' ' . number_format((float) ($totals['boardDue'] ?? 0)), 'due'],
                ],
            ];
            $colW = (int) (($halfW - 44 - 40) / 2);
            foreach ($cols as $ci => $lines) {
                $x0 = $M + 22 + $ci * ($colW + 40);
                $x1 = $x0 + $colW;
                $ly = $by + 48;
                foreach ($lines as $line) {
                    [$lab, $val] = $line;
                    $hot = ($line[2] ?? '') === 'due' && (float) ($totals['boardDue'] ?? 0) > 0;   // still to collect: maroon
                    self::t($im, 14, $x0, $ly, $hot ? $maroon : $muted, $lab, $hot);
                    imageline($im, $x1 - 170, $ly + 20, $x1, $ly + 20, $hair);
                    self::tR($im, 15, $x1 - 2, $ly - 1, $hot ? $maroon : $ink, $val, true);
                    $ly += 22;
                }
            }
            $ly = $by + 48 + 5 * 22 + 8;
            self::t($im, 17, $M + 22, $ly, $maroon, 'NET COLLECTION', true);
            imageline($im, $M + $halfW - 260, $ly + 22, $M + $halfW - 22, $ly + 22, $hair);
            self::tR($im, 18, $M + $halfW - 24, $ly - 1, $maroon, $cl . ' ' . number_format((float) ($totals['net'] ?? 0)) . (isset($totals['inr']['net']) ? '  (Rs ' . number_format((float) $totals['inr']['net']) . ')' : ''), true);

            $ax = $M + $halfW + 16;
            GoldSheet::card($im, $ax, $by, $W - $M, $by + self::BOXES_H, 12, [255, 253, 246]);
            GoldSheet::foilRound($im, $ax + 2, $by + 2, $W - $M - 2, $by + 38, 10);
            self::t($im, 17, $ax + 18, $by + 9, $navy, 'AUTHORISATION  ·  SIGNATURES', true);
            $sw = (int) (($halfW - 60) / 2);
            foreach (['Conductor', 'Driver', 'Agent / Office', 'Verified by (Manager)'] as $i => $lab) {
                $col = $i % 2; $row = intdiv($i, 2);
                $sx  = $ax + 20 + $col * ($sw + 20);
                $ly2 = $by + 104 + $row * 60;
                imageline($im, $sx, $ly2, $sx + $sw, $ly2, $goldD);
                self::tC($im, 15, $sx, $sx + $sw, $ly2 + 7, $muted, $lab, false);
            }
        }

        /* ================= footer ================= */
        $fy = self::FOOT_Y;
        GoldSheet::foilRule($im, $M, $W - $M, $fy, GoldSheet::CREAM);
        $pg = 'PAGE ' . $page . ' OF ' . $pagesShown;
        self::tR($im, 17, $W - $M, $fy + 13, $navy, $pg, true);
        self::t($im, 14, $M, $fy + 15, $muted,
            self::fit(14, $roman('Booking counters: ' . $co['counters']), $inner - self::w(17, $pg) - 30), false);
        self::t($im, 12, $M, $fy + 36, $muted, self::fit(12, $roman(
            // 10 Oct 2026 (trust): computer-generated stated, the GSTIN beside the CIN once the office has one
            'Official travel document of ' . $co['legal'] . '. Computer-generated; valid with authorised signature / stamp. Keep for company records. '
            . 'Route: ' . ($trip['from_city'] ?? '') . ' - ' . ($trip['to_city'] ?? '') . ' (India-Nepal). CIN ' . $co['cin'] . ($gstin !== '' ? ' | GSTIN ' . $gstin : '')
        ), $inner), false);

        /* Navy blessing band at the foot of the sheet. */
        $bb = $fy + 56;
        GoldSheet::gradientV($im, 0, $bb, $W, $H, GoldSheet::ROYAL, GoldSheet::NAVY);
        imageline($im, 0, $bb, $W, $bb, $gold);
        $bless = GoldSheet::blessing();
        $bw    = $bless !== '' ? self::w(16, $bless) : 0;
        if ($bless !== '') {
            self::t($im, 16, (int) (($W - $bw) / 2), $bb + 5, $goldL, $bless, true);
        }
        $leftW  = (int) (($W - $bw) / 2) - $M - 30;
        self::t($im, 11, $M + 40, $bb + 11, $dim, self::fit(11,
            'Generated ' . date('D, j M Y g:i A') . ' IST  ·  ' . $chalaniNo, $leftW - 40), false);
        self::tR($im, 11, $W - $M - 40, $bb + 11, $dim, self::fit(11,
            'Crew copy, names as typed - signed Nepali chalani = PDF  ·  ' . $roman($co['web']), $leftW - 40), false);

        /* Gold corner ornaments over everything. */
        GoldSheet::corners($im, 10, 10, $W - 11, $H - 11, 54);

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
