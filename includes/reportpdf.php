<?php
/**
 * =====================================================================
 *  ReportPdf — tabular PDF generator for manifests, agent reports,
 *  commission summaries and admin booking exports.
 *
 *  Built on top of the existing Pdf class (includes/pdf.php). Uses the
 *  same coordinate model (top-left origin, points) and standard-14
 *  fonts. Handles pagination, header/footer, summary cards and
 *  multi-page tables automatically.
 *
 *  Master prompt §13 (manifest PDF), §14 (agent report PDF), §28 (all
 *  admin report PDFs).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ReportPdf
{
    /* Brand colours — same palette the ticket uses. */
    private const NAVY   = [26, 58, 106];
    private const BLUE   = [46, 95, 168];
    private const WHITE  = [255, 255, 255];
    private const INK    = [30, 30, 40];
    private const MUTE   = [120, 120, 130];
    private const LINE   = [210, 210, 218];
    private const HEADER = [234, 238, 246];
    private const STRIPE = [248, 249, 252];
    private const GREEN  = [16, 130, 60];
    private const RED    = [180, 40, 40];
    private const ORANGE = [220, 120, 20];

    /* Page geometry (A4 landscape for tables). */
    private const PAGE_W  = 841.89;
    private const PAGE_H  = 595.28;
    private const MARGIN  = 36;
    private const TOP_Y   = 36;

    private Pdf $pdf;
    private string $title;
    private string $subtitle;
    private int $pageNum = 1;
    private float $cursorY;

    /** @var array<int, array{label: string, width: float, align: string}> */
    private array $columns = [];

    public function __construct(string $title, string $subtitle = '')
    {
        $this->pdf      = new Pdf(self::PAGE_W, self::PAGE_H);
        /* The Devanagari face, so a passenger who wrote their name in Nepali
           is not an empty cell on the office's own manifest (audit, 11 Sep
           2026). Helvetica is Latin-1: it cannot show the name at all, and
           tableRow() used to hand it one anyway. Optional — if the font file
           is missing the report still prints, in Roman. */
        $devFont = dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
        if (is_file($devFont)) {
            try { $this->pdf->registerTTF($devFont, 'F7'); } catch (\Throwable $e) {}
        }
        $this->title    = $title;
        $this->subtitle = $subtitle;
        $this->cursorY  = self::TOP_Y;
        $this->drawPageHeader();
    }

    /** Does this cell need the embedded Devanagari face rather than Helvetica? */
    private function isDev(string $text): bool
    {
        return $text !== ''
            && preg_match('/\p{Devanagari}/u', $text) === 1
            && $this->pdf->hasCIDFont('F7');
    }


    /* =================================================================
     *  Page header & footer
     * ================================================================= */

    private function drawPageHeader(): void
    {
        $pdf = $this->pdf;
        $w   = self::PAGE_W;
        $m   = self::MARGIN;

        // Navy banner
        $pdf->rect(0, 0, $w, 52, self::NAVY);

        // Company logo on a white chip (5 Sep 2026) — one draw here brands
        // all five report generators. Text shifts right only when present.
        $tx   = $m;
        $logo = self::logoFile();
        if ($logo !== null) {
            $pdf->rect($m - 4, 6, 58, 42, self::WHITE);
            $pdf->image($logo, $m - 1, 9, 52, 38);   // 440x321 source
            $tx = $m + 64;
        }

        // Company name
        $pdf->text($tx, 10, 'S HARI GLOBAL PVT. LTD.', 16, 'F2', self::WHITE);
        $pdf->text($tx, 30, 'Gujarat - Rupaidiha - Nepalgunj | International Bus Transport', 8, 'F1', [180, 200, 230]);

        // Report title (right side)
        $pdf->textRight($w - $m, 12, $this->title, 13, 'F2', self::WHITE);
        if ($this->subtitle !== '') {
            $pdf->textRight($w - $m, 30, $this->subtitle, 8, 'F1', [180, 200, 230]);
        }

        // Page number badge
        $pdf->textRight($w - $m, 42, 'Page ' . $this->pageNum, 7, 'F1', [160, 180, 210]);

        $this->cursorY = 62;
    }

    /**
     * The company logo file for the banner, or null when none is usable.
     * Mirrors Ticket::logoFile() (kept local so reports never depend on
     * ticket.php being loaded): admin-set company_logo path wins, then the
     * site logo; PNG/JPEG only — that is all Pdf::image() embeds.
     */
    private static function logoFile(): ?string
    {
        foreach ([Settings::getString('company_logo', ''), 'assets/img/logo.png'] as $cand) {
            if ($cand === '' || preg_match('#^https?://#i', $cand) === 1) {
                continue;
            }
            $ext = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                continue;
            }
            $path = dirname(__DIR__) . '/' . ltrim($cand, '/');
            if (is_file($path) && filesize($path) > 0 && filesize($path) < 600 * 1024) {
                return $path;
            }
        }

        return null;
    }

    private function drawPageFooter(): void
    {
        $pdf = $this->pdf;
        $w   = self::PAGE_W;
        $m   = self::MARGIN;
        $y   = self::PAGE_H - 24;

        $pdf->line($m, $y, $w - $m, $y, self::LINE, 0.3);
        $pdf->text($m, $y + 4, 'Generated ' . date('d M Y, H:i') . ' IST | shreehariglobal.in', 7, 'F3', self::MUTE);
        $pdf->textRight($w - $m, $y + 4, 'Page ' . $this->pageNum, 7, 'F1', self::MUTE);
    }

    private function newPage(): void
    {
        $this->drawPageFooter();
        $this->pageNum++;
        $this->pdf->newPage();
        $this->drawPageHeader();
    }

    /** Is there room for $needed points before the footer? */
    private function fits(float $needed): bool
    {
        return $this->cursorY + $needed < self::PAGE_H - 36;
    }

    private function ensureSpace(float $needed): void
    {
        if (!$this->fits($needed)) {
            $this->newPage();
        }
    }


    /* =================================================================
     *  Summary cards — the coloured stat boxes at the top
     * ================================================================= */

    /**
     * @param array<int, array{label: string, value: string, sub?: string, color?: array{0:int,1:int,2:int}}> $cards
     */
    public function summaryCards(array $cards): void
    {
        $this->ensureSpace(60);
        $count = count($cards);
        if ($count === 0) return;

        $m   = self::MARGIN;
        $gap = 10;
        $usable = self::PAGE_W - 2 * $m - ($count - 1) * $gap;
        $cardW  = $usable / $count;

        foreach ($cards as $i => $card) {
            $x    = $m + $i * ($cardW + $gap);
            $bg   = $card['color'] ?? self::BLUE;
            $this->pdf->roundedRect($x, $this->cursorY, $cardW, 48, 6, $bg);
            $this->pdf->text($x + 10, $this->cursorY + 6,  $card['label'], 7, 'F1', [220, 230, 240]);
            $this->pdf->text($x + 10, $this->cursorY + 18, $card['value'], 16, 'F2', self::WHITE);
            if (isset($card['sub'])) {
                $this->pdf->text($x + 10, $this->cursorY + 36, $card['sub'], 7, 'F1', [180, 200, 220]);
            }
        }

        $this->cursorY += 58;
    }


    /* =================================================================
     *  Section headings
     * ================================================================= */

    public function sectionHeading(string $text): void
    {
        $this->ensureSpace(30);
        $this->cursorY += 6;
        $this->pdf->text(self::MARGIN, $this->cursorY, $text, 11, 'F2', self::NAVY);
        $this->cursorY += 16;
        $this->pdf->line(self::MARGIN, $this->cursorY, self::PAGE_W - self::MARGIN, $this->cursorY, self::LINE, 0.5);
        $this->cursorY += 6;
    }


    /* =================================================================
     *  Tables
     * ================================================================= */

    /**
     * Define column layout for the upcoming table.
     *
     * @param array<int, array{label: string, width: float, align?: string}> $cols
     *   width is in pt; align is 'L' (default), 'R' or 'C'.
     */
    public function setColumns(array $cols): void
    {
        $this->columns = [];
        foreach ($cols as $c) {
            $this->columns[] = [
                'label' => $c['label'],
                'width' => $c['width'],
                'align' => strtoupper($c['align'] ?? 'L'),
            ];
        }
    }

    /** Draw the column headers. */
    public function tableHeader(): void
    {
        $this->ensureSpace(22);
        $m = self::MARGIN;
        $rowH = 16;
        $this->pdf->rect($m, $this->cursorY, self::PAGE_W - 2 * $m, $rowH, self::HEADER);

        $x = $m;
        foreach ($this->columns as $col) {
            $tx = $x + 4;
            $this->pdf->text($tx, $this->cursorY + 3, $col['label'], 7.5, 'F2', self::NAVY);
            $x += $col['width'];
        }
        $this->cursorY += $rowH;
    }

    /**
     * Draw one table row.
     *
     * @param array<int, string> $cells values in column order
     * @param bool $stripe alternate-row shading
     */
    public function tableRow(array $cells, bool $stripe = false): void
    {
        if (!$this->fits(16)) {
            $this->newPage();
            $this->tableHeader();
        }

        $m    = self::MARGIN;
        $rowH = 14;
        $font = 'F1';
        $size = 7.5;

        if ($stripe) {
            $this->pdf->rect($m, $this->cursorY, self::PAGE_W - 2 * $m, $rowH, self::STRIPE);
        }

        $x = $m;
        foreach ($this->columns as $i => $col) {
            $val = (string) ($cells[$i] ?? '');
            // Truncate to fit column width (approx). mb_*, not strlen/substr:
            // a byte cut lands inside a three-byte Devanagari codepoint and the
            // cell then renders as nothing at all.
            $maxChars = (int) ($col['width'] / ($size * 0.5));
            if (mb_strlen($val) > $maxChars + 3) {
                $val = mb_substr($val, 0, $maxChars) . '...';
            }

            $tx  = $x + 4;
            $dev = $this->isDev($val);
            if ($col['align'] === 'R') {
                if ($dev) { $this->pdf->textCIDRight($x + $col['width'] - 4, $this->cursorY + 3, $val, $size, 'F7', self::INK); }
                else      { $this->pdf->textRight($x + $col['width'] - 4, $this->cursorY + 3, $val, $size, $font, self::INK); }
            } elseif ($col['align'] === 'C') {
                if ($dev) { $this->pdf->textCIDCenter($x, $x + $col['width'], $this->cursorY + 3, $val, $size, 'F7', self::INK); }
                else      { $this->pdf->textCenter($x, $x + $col['width'], $this->cursorY + 3, $val, $size, $font, self::INK); }
            } else {
                if ($dev) { $this->pdf->textCID($tx, $this->cursorY + 3, $val, $size, 'F7', self::INK); }
                else      { $this->pdf->text($tx, $this->cursorY + 3, $val, $size, $font, self::INK); }
            }
            $x += $col['width'];
        }

        // Row bottom line
        $this->pdf->line($m, $this->cursorY + $rowH, self::PAGE_W - $m, $this->cursorY + $rowH, [230, 230, 236], 0.2);
        $this->cursorY += $rowH;
    }

    /**
     * Draw a totals row (bold, shaded).
     *
     * @param array<int, string> $cells values in column order
     */
    public function tableTotals(array $cells): void
    {
        $this->ensureSpace(20);
        $m    = self::MARGIN;
        $rowH = 16;

        $this->pdf->rect($m, $this->cursorY, self::PAGE_W - 2 * $m, $rowH, self::NAVY);

        $x = $m;
        foreach ($this->columns as $i => $col) {
            $val = $cells[$i] ?? '';
            $tx = $x + 4;
            if ($col['align'] === 'R') {
                $this->pdf->textRight($x + $col['width'] - 4, $this->cursorY + 3, $val, 8, 'F2', self::WHITE);
            } elseif ($col['align'] === 'C') {
                $this->pdf->textCenter($x, $x + $col['width'], $this->cursorY + 3, $val, 8, 'F2', self::WHITE);
            } else {
                $this->pdf->text($tx, $this->cursorY + 3, $val, 8, 'F2', self::WHITE);
            }
            $x += $col['width'];
        }

        $this->cursorY += $rowH + 4;
    }


    /* =================================================================
     *  Key-value info blocks (trip details, agent info, etc.)
     * ================================================================= */

    /**
     * @param array<int, array{0: string, 1: string}> $pairs [[label, value], ...]
     * @param int $perRow  how many pairs per row (default 2)
     */
    public function infoBlock(array $pairs, int $perRow = 2): void
    {
        $m = self::MARGIN;
        $colW = (self::PAGE_W - 2 * $m) / $perRow;

        foreach (array_chunk($pairs, $perRow) as $row) {
            $this->ensureSpace(18);
            foreach ($row as $i => $pair) {
                $x = $m + $i * $colW;
                $this->pdf->text($x, $this->cursorY, $pair[0] . ':', 7.5, 'F2', self::MUTE);
                $this->pdf->text($x + $this->pdf->textWidth($pair[0] . ':  ', 7.5, 'F2'), $this->cursorY, $pair[1], 7.5, 'F1', self::INK);
            }
            $this->cursorY += 14;
        }
        $this->cursorY += 2;
    }


    /* =================================================================
     *  Output
     * ================================================================= */

    /** Assemble and return the raw PDF bytes. */
    public function output(): string
    {
        $this->drawPageFooter();
        return $this->pdf->output();
    }

    /** Stream the PDF to the browser. */
    public function stream(string $filename): void
    {
        $bytes = $this->output();

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');

        echo $bytes;
    }


    /* =================================================================
     *  Pre-built report generators
     *
     *  Each is a static factory that queries the data it needs, builds
     *  the PDF, and streams it. The admin page calls one line:
     *    ReportPdf::manifest($scheduleId, $scopeId);
     * ================================================================= */

    /**
     * §13 — Passenger Manifest PDF.
     *
     * Company, bus, route, date, departure, seats summary, then the full
     * passenger table: seat, name, phone, booking ID, agent, status.
     */
    public static function manifest(int $scheduleId, ?int $scopeId = null): void
    {
        $trip = Database::fetch(
            "SELECT s.id, s.travel_date, s.total_seats, s.seats_booked, s.status AS sched_status,
                    r.from_city, r.to_city, r.dep_time, r.route_code, r.coach_type,
                    bu.bus_number, bu.bus_name,
                    d.full_name AS driver_name, d.phone AS driver_phone
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = s.bus_id
               LEFT JOIN drivers d ON d.id = s.driver_id
              WHERE s.id = :sid",
            ['sid' => $scheduleId]
        );

        if ($trip === null) {
            http_response_code(404);
            exit('Schedule not found.');
        }

        $date = (string) $trip['travel_date'];

        // Passenger rows
        $where  = ['bl.schedule_id = :sid', "b.status IN ('confirmed','pending','completed')"];
        $params = ['sid' => $scheduleId];
        if ($scopeId !== null) {
            $where[]         = 'b.sold_by_admin_id = :scope';
            $params['scope'] = $scopeId;
        }

        $rows = Database::fetchAll(
            "SELECT bp.seat_no, bp.full_name, bp.age, bp.gender,
                    b.pnr, b.contact_phone, b.status AS booking_status, b.total_amount, b.sold_by_admin_id, b.source,
                    bl.boarding_stop, bl.drop_stop,
                    p.status AS pay_status, p.method AS pay_method,
                    t.ticket_number,
                    ad.full_name AS agent_name, ad.username AS agent_user
               FROM booking_passengers bp
               JOIN bookings b      ON b.id = bp.booking_id
               JOIN booking_legs bl ON bl.id = bp.leg_id
               LEFT JOIN payments p ON p.id = (SELECT p2.id FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1)
               LEFT JOIN tickets t  ON t.booking_id = b.id
               LEFT JOIN admins ad  ON ad.id = b.sold_by_admin_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY LENGTH(bp.seat_no), bp.seat_no",
            $params
        );

        $paxCount = count($rows);
        $revenue  = 0.0;
        $seenPnr  = [];
        foreach ($rows as $r) {
            if (isset($seenPnr[$r['pnr']])) continue;
            $seenPnr[$r['pnr']] = true;
            $revenue += (float) $r['total_amount'];
        }

        $rpt = new self(
            'PASSENGER MANIFEST',
            formatDate($date) . ' | ' . ($trip['from_city'] ?? '') . ' -> ' . ($trip['to_city'] ?? '')
        );

        // Summary cards
        $rpt->summaryCards([
            ['label' => 'PASSENGERS',    'value' => (string) $paxCount, 'sub' => 'on board', 'color' => self::BLUE],
            ['label' => 'TOTAL SEATS',   'value' => (string) ($trip['total_seats'] ?? 0), 'sub' => ((int) ($trip['total_seats'] ?? 0) - $paxCount) . ' available', 'color' => self::GREEN],
            ['label' => 'BOOKED VALUE',  'value' => inr($revenue), 'sub' => 'confirmed + pending', 'color' => self::NAVY],
        ]);

        // Trip info
        $rpt->infoBlock([
            ['Route',     ($trip['from_city'] ?? '') . ' -> ' . ($trip['to_city'] ?? '')],
            ['Travel date', formatDate($date)],
            ['Departure', formatTime((string) ($trip['dep_time'] ?? ''))],
            ['Bus number', (string) ($trip['bus_number'] ?? 'not assigned')],
            ['Coach type', ucfirst((string) ($trip['coach_type'] ?? ''))],
            ['Driver',    ($trip['driver_name'] ?? 'not assigned') . ($trip['driver_phone'] ? ' (' . $trip['driver_phone'] . ')' : '')],
        ]);

        // Passenger table
        $rpt->sectionHeading('Passenger List — ' . $paxCount . ' passenger' . ($paxCount === 1 ? '' : 's'));

        $rpt->setColumns([
            ['label' => '#',          'width' => 24,  'align' => 'C'],
            ['label' => 'Seat',       'width' => 42,  'align' => 'C'],
            ['label' => 'Passenger',  'width' => 130],
            ['label' => 'Phone',      'width' => 85],
            ['label' => 'Boarding',   'width' => 80],
            ['label' => 'Destination','width' => 80],
            ['label' => 'PNR',        'width' => 100],
            ['label' => 'Payment',    'width' => 60,  'align' => 'C'],
            ['label' => 'Source',     'width' => 52,  'align' => 'C'],
            ['label' => 'Agent',      'width' => 110],
        ]);
        $rpt->tableHeader();

        foreach ($rows as $i => $r) {
            $payS = (string) ($r['pay_status'] ?? '');
            $pay  = 'PENDING';
            if ($payS === 'verified')    $pay = 'PAID';
            if ($payS === 'cod_pending') $pay = 'COD';
            if ($payS === 'refunded')    $pay = 'REFUND';

            $agent = '';
            if (!empty($r['sold_by_admin_id'])) {
                $code = AgentWallet::agentCodeLabel((int) $r['sold_by_admin_id']);
                $name = (string) ($r['agent_name'] ?: $r['agent_user']);
                $agent = trim(($code !== '' ? $code . ' ' : '') . $name);
            }

            $src = !empty($r['sold_by_admin_id']) ? 'AGENT' : strtoupper((string) ($r['source'] ?? 'web'));

            $rpt->tableRow([
                (string) ($i + 1),
                (string) $r['seat_no'],
                (string) $r['full_name'] . ($r['age'] ? ' (' . $r['age'] . ($r['gender'] ? '/' . substr((string) $r['gender'], 0, 1) : '') . ')' : ''),
                (string) $r['contact_phone'],
                (string) ($r['boarding_stop'] ?: ''),
                (string) ($r['drop_stop'] ?: ($trip['to_city'] ?? '')),
                (string) $r['pnr'],
                $pay,
                $src,
                $agent ?: '-',
            ], $i % 2 === 1);
        }

        if ($paxCount > 0) {
            $rpt->tableTotals([
                '', '', 'TOTAL: ' . $paxCount . ' passengers', '', '', '', '',
                '', '', inr($revenue),
            ]);
        }

        $filename = 'manifest-' . $date . '-' . ($trip['route_code'] ?? 'trip') . '.pdf';
        Logger::audit('manifest.pdf', 'schedule', (string) $scheduleId,
            null, null, $paxCount . ' passengers, ' . $date);

        $rpt->stream($filename);
        exit;
    }


    /**
     * §14 — Agent Sales Report PDF.
     *
     * Agent name/code, date range, summary cards (tickets, revenue,
     * commission, seats), then the booking table.
     */
    public static function agentReport(int $agentAdminId, string $from, string $to, string $rangeLabel = ''): void
    {
        $agent = Database::fetch(
            'SELECT id, username, full_name, role FROM admins WHERE id = :id',
            ['id' => $agentAdminId]
        );
        if ($agent === null) {
            http_response_code(404);
            exit('Agent not found.');
        }

        $agentCode = AgentWallet::agentCodeLabel($agentAdminId);
        $agentName = (string) ($agent['full_name'] ?: $agent['username']);

        // Report data — same queries as agent-sales.php
        $toExcl = addDaysISO($to, 1);

        $report = Database::fetch(
            "SELECT COUNT(*) AS tickets,
                    COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
                    COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
               FROM bookings b
              WHERE b.sold_by_admin_id = :agent AND b.created_at >= :from AND b.created_at < :to",
            ['agent' => $agentAdminId, 'from' => $from, 'to' => $toExcl]
        ) ?? ['tickets' => 0, 'confirmed' => 0, 'revenue' => 0, 'cancelled' => 0];

        $seatsSold = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
              WHERE b.sold_by_admin_id = :agent AND b.created_at >= :from AND b.created_at < :to AND b.status = 'confirmed'",
            ['agent' => $agentAdminId, 'from' => $from, 'to' => $toExcl],
            0
        );

        $commission = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
              WHERE agent_admin_id = :a AND account = 'commission'
                AND entry_type IN ('commission','commission_void')
                AND created_at >= :from AND created_at < :to",
            ['a' => $agentAdminId, 'from' => $from, 'to' => $toExcl],
            0
        );

        $bookings = Database::fetchAll(
            "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.source,
                    r.from_city, r.to_city, bl.travel_date,
                    (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                       FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
                    (SELECT bp.full_name FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger
               FROM bookings b
               LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
               LEFT JOIN schedules s ON s.id = bl.schedule_id
               LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.sold_by_admin_id = :agent AND b.created_at >= :from AND b.created_at < :to
              ORDER BY b.id DESC LIMIT 500",
            ['agent' => $agentAdminId, 'from' => $from, 'to' => $toExcl]
        );

        $sub = $rangeLabel !== '' ? $rangeLabel : (formatDate($from) . ' - ' . formatDate($to));
        $rpt = new self(
            'AGENT SALES REPORT',
            $agentName . ' (' . ($agentCode ?: 'Staff') . ') | ' . $sub
        );

        // Agent info
        $rpt->infoBlock([
            ['Agent name',  $agentName],
            ['Agent code',  $agentCode ?: '-'],
            ['Report period', formatDate($from) . ' to ' . formatDate($to)],
            ['Generated',   date('d M Y, H:i') . ' IST'],
        ]);

        // Summary cards
        $rpt->summaryCards([
            ['label' => 'TICKETS',    'value' => (string) (int) $report['tickets'], 'sub' => (int) $report['confirmed'] . ' confirmed, ' . (int) $report['cancelled'] . ' cancelled', 'color' => self::BLUE],
            ['label' => 'REVENUE',    'value' => inr((float) $report['revenue']), 'sub' => 'confirmed sales', 'color' => self::GREEN],
            ['label' => 'COMMISSION', 'value' => inr($commission), 'sub' => 'net of reversals', 'color' => [0, 137, 123]],
            ['label' => 'SEATS SOLD', 'value' => (string) $seatsSold, 'sub' => 'on confirmed tickets', 'color' => self::NAVY],
        ]);

        // Booking table
        $rpt->sectionHeading('Sales Detail — ' . count($bookings) . ' ticket' . (count($bookings) === 1 ? '' : 's'));

        $rpt->setColumns([
            ['label' => '#',         'width' => 24,  'align' => 'C'],
            ['label' => 'PNR',       'width' => 100],
            ['label' => 'Passenger', 'width' => 130],
            ['label' => 'Route',     'width' => 120],
            ['label' => 'Travel',    'width' => 80],
            ['label' => 'Seats',     'width' => 60,  'align' => 'C'],
            ['label' => 'Amount',    'width' => 70,  'align' => 'R'],
            ['label' => 'Status',    'width' => 60,  'align' => 'C'],
            ['label' => 'Sold',      'width' => 80],
        ]);
        $rpt->tableHeader();

        foreach ($bookings as $i => $b) {
            $rpt->tableRow([
                (string) ($i + 1),
                (string) $b['pnr'],
                (string) ($b['passenger'] ?: '-'),
                ($b['from_city'] ?? '-') . ' -> ' . ($b['to_city'] ?? '-'),
                $b['travel_date'] ? formatDate((string) $b['travel_date'], 'j M Y') : '-',
                (string) ($b['seat_count'] ?? 0),
                inr((float) $b['total_amount']),
                strtoupper((string) $b['status']),
                formatDate((string) $b['created_at'], 'j M, H:i'),
            ], $i % 2 === 1);
        }

        if ($bookings !== []) {
            $rpt->tableTotals([
                '', '', 'TOTAL', '', '', (string) $seatsSold,
                inr((float) $report['revenue']), (int) $report['confirmed'] . ' conf.', '',
            ]);
        }

        $filename = 'agent-report-' . ($agentCode ?: 'staff') . '-' . $from . '-to-' . $to . '.pdf';
        Logger::audit('agent.report.pdf', 'admin', (string) $agentAdminId,
            null, null, (int) $report['tickets'] . ' tickets, ' . $from . ' to ' . $to);

        $rpt->stream($filename);
        exit;
    }


    /**
     * §15/§28 — Admin Commission Report PDF.
     *
     * All agents, their ticket count, revenue, commission earned/paid/pending.
     */
    public static function commissionReport(string $from, string $to): void
    {
        $toExcl = addDaysISO($to, 1);

        // All agents with any activity in the period
        $agents = Database::fetchAll(
            "SELECT a.id, a.username, a.full_name, a.is_active,
                    COALESCE(bk.tickets, 0) AS tickets,
                    COALESCE(bk.revenue, 0) AS revenue,
                    COALESCE(bk.seats, 0)   AS seats
               FROM admins a
               LEFT JOIN (
                   SELECT b.sold_by_admin_id AS aid,
                          COUNT(*) AS tickets,
                          SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END) AS revenue,
                          (SELECT COUNT(*) FROM booking_seats bs2
                             JOIN bookings b2 ON b2.id = bs2.booking_id
                            WHERE b2.sold_by_admin_id = b.sold_by_admin_id
                              AND b2.status = 'confirmed'
                              AND b2.created_at >= :from1 AND b2.created_at < :to1) AS seats
                     FROM bookings b
                    WHERE b.created_at >= :from2 AND b.created_at < :to2
                    GROUP BY b.sold_by_admin_id
               ) bk ON bk.aid = a.id
              WHERE a.role = 'agent' AND (bk.tickets > 0 OR a.is_active = 1)
              ORDER BY bk.revenue DESC, a.full_name",
            ['from1' => $from, 'to1' => $toExcl, 'from2' => $from, 'to2' => $toExcl]
        );

        // Commission figures per agent from the ledger
        $commissions = [];
        if ($agents !== []) {
            $ledger = Database::fetchAll(
                "SELECT agent_admin_id,
                        SUM(CASE WHEN entry_type IN ('commission','commission_void') THEN amount ELSE 0 END) AS earned,
                        SUM(CASE WHEN entry_type = 'payout' THEN ABS(amount) ELSE 0 END) AS paid
                   FROM agent_ledger
                  WHERE account = 'commission'
                    AND created_at >= :from AND created_at < :to
                  GROUP BY agent_admin_id",
                ['from' => $from, 'to' => $toExcl]
            );
            foreach ($ledger as $l) {
                $commissions[(int) $l['agent_admin_id']] = $l;
            }
        }

        $totalTickets    = 0;
        $totalRevenue    = 0.0;
        $totalCommission = 0.0;
        $totalPaid       = 0.0;

        $rpt = new self(
            'COMMISSION REPORT',
            formatDate($from) . ' - ' . formatDate($to)
        );

        $rpt->sectionHeading('Agent Commission Summary');

        $rpt->setColumns([
            ['label' => '#',          'width' => 24,  'align' => 'C'],
            ['label' => 'Agent Code', 'width' => 70],
            ['label' => 'Agent Name', 'width' => 140],
            ['label' => 'Tickets',    'width' => 55,  'align' => 'R'],
            ['label' => 'Seats',      'width' => 50,  'align' => 'R'],
            ['label' => 'Revenue',    'width' => 90,  'align' => 'R'],
            ['label' => 'Commission', 'width' => 90,  'align' => 'R'],
            ['label' => 'Paid',       'width' => 90,  'align' => 'R'],
            ['label' => 'Pending',    'width' => 90,  'align' => 'R'],
            ['label' => 'Status',     'width' => 50,  'align' => 'C'],
        ]);
        $rpt->tableHeader();

        foreach ($agents as $i => $a) {
            $code   = AgentWallet::agentCodeLabel((int) $a['id']);
            $earned = (float) ($commissions[(int) $a['id']]['earned'] ?? 0);
            $paid   = (float) ($commissions[(int) $a['id']]['paid'] ?? 0);
            $pending = $earned - $paid;

            $totalTickets    += (int) $a['tickets'];
            $totalRevenue    += (float) $a['revenue'];
            $totalCommission += $earned;
            $totalPaid       += $paid;

            $rpt->tableRow([
                (string) ($i + 1),
                $code ?: '-',
                (string) ($a['full_name'] ?: $a['username']),
                (string) (int) $a['tickets'],
                (string) (int) $a['seats'],
                inr((float) $a['revenue']),
                inr($earned),
                inr($paid),
                inr($pending),
                $a['is_active'] ? 'Active' : 'Inactive',
            ], $i % 2 === 1);
        }

        $rpt->tableTotals([
            '', '', 'TOTAL (' . count($agents) . ' agents)',
            (string) $totalTickets, '',
            inr($totalRevenue),
            inr($totalCommission),
            inr($totalPaid),
            inr($totalCommission - $totalPaid),
            '',
        ]);

        $filename = 'commission-report-' . $from . '-to-' . $to . '.pdf';
        Logger::audit('commission.report.pdf', 'report', 'commission',
            null, null, count($agents) . ' agents, ' . $from . ' to ' . $to);

        $rpt->stream($filename);
        exit;
    }


    /**
     * Company-wide bookings PDF — the same data admin/export.php streams as
     * CSV/XLSX, presented as a printable PDF. Reuses the standard chrome
     * (navy banner + brand strip + table) and honours the same filter set.
     *
     * $filters keys (all optional):
     *   - scope  int|null   restrict to bookings sold by this admin id
     *   - q      string     free-text search (same arms as bookings.php)
     *   - status string     one of pending/confirmed/cancelled/rejected/expired
     *   - from   string     Y-m-d inclusive lower bound on b.created_at
     *   - to     string     Y-m-d inclusive upper bound on b.created_at
     *
     * @param PDO                                     $db      unused directly (Database wrapper drives the query)
     * @param array{scope?:?int,q?:string,status?:string,from?:?string,to?:?string} $filters
     * @return string  Absolute path of the generated PDF on disk.
     */
    public static function bookingsReport(PDO $db, array $filters): string
    {
        unset($db); // signature per master prompt; Database wrapper uses the same PDO singleton

        $scopeId = $filters['scope'] ?? null;
        $q       = (string) ($filters['q'] ?? '');
        $status  = (string) ($filters['status'] ?? '');
        $from    = (string) ($filters['from'] ?? '');
        $to      = (string) ($filters['to'] ?? '');
        // 3 Sep 2026: the on-screen list carries seven more filters (travel
        // dates, route, source, agent, bus, payment status); export.php now
        // forwards them so the PDF matches the screen. Same fragments as
        // admin/bookings.php.
        $routeFilter     = (string) ($filters['route'] ?? '');
        $travelFrom      = (string) ($filters['travel_from'] ?? '');
        $travelTo        = (string) ($filters['travel_to'] ?? '');
        $sourceFilter    = (string) ($filters['source'] ?? '');
        $agentFilter     = (string) ($filters['agent'] ?? '');
        $busFilter       = (string) ($filters['bus'] ?? '');
        $payStatusFilter = (string) ($filters['pay_status'] ?? '');
        $validPayStatus  = ['pending', 'verified', 'rejected', 'refunded', 'cod_pending'];
        $sourceMap       = ['online' => ['web', 'app'], 'agent' => ['agent'], 'counter' => ['counter'], 'admin' => ['admin']];

        $valid  = ['pending', 'confirmed', 'cancelled', 'rejected', 'expired'];
        $isDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        $where  = [];
        $params = [];
        if ($scopeId !== null) {
            $where[]         = 'b.sold_by_admin_id = :scope';
            $params['scope'] = (int) $scopeId;
        }
        if ($q !== '') {
            $where[] = sqlSearchClause([
                'b.pnr LIKE %s',
                'b.contact_phone LIKE %s',
                'b.contact_email LIKE %s',
                'EXISTS (SELECT 1 FROM booking_passengers bp2
                          WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
                'EXISTS (SELECT 1 FROM booking_seats bs2
                          WHERE bs2.booking_id = b.id AND bs2.seat_no LIKE %s)',
                'EXISTS (SELECT 1 FROM admins a2
                          WHERE a2.id = b.sold_by_admin_id
                            AND CONCAT_WS(\' \', a2.full_name, a2.username) LIKE %s)',
            ], $q, $params);
        }
        if (in_array($status, $valid, true)) {
            $where[]      = 'b.status = :st';
            $params['st'] = $status;
        }
        if ($isDate($from)) {
            $where[]        = 'b.created_at >= :from';
            $params['from'] = $from;
        }
        if ($isDate($to)) {
            $where[]         = 'b.created_at < :toEnd';
            $params['toEnd'] = addDaysISO($to, 1);
        }
        if ($routeFilter !== '' && ctype_digit($routeFilter)) {
            $where[] = 's.route_id = :routeFilter';
            $params['routeFilter'] = (int) $routeFilter;
        }
        if ($isDate($travelFrom)) {
            $where[] = 'bl.travel_date >= :travelFrom';
            $params['travelFrom'] = $travelFrom;
        }
        if ($isDate($travelTo)) {
            $where[] = 'bl.travel_date <= :travelTo';
            $params['travelTo'] = $travelTo;
        }
        if ($sourceFilter !== '' && isset($sourceMap[$sourceFilter])) {
            $srcParts = [];
            foreach ($sourceMap[$sourceFilter] as $si => $sv) {
                $srcParts[] = ':src' . $si;
                $params['src' . $si] = $sv;
            }
            $where[] = 'b.source IN (' . implode(',', $srcParts) . ')';
        }
        if ($scopeId === null && $agentFilter !== '' && ctype_digit($agentFilter)) {
            $where[] = 'b.sold_by_admin_id = :agentFlt';
            $params['agentFlt'] = (int) $agentFilter;
        }
        if ($busFilter !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM booking_legs bl3
                                  JOIN schedules s3 ON s3.id = bl3.schedule_id
                                  JOIN buses bu3 ON bu3.id = s3.bus_id
                                 WHERE bl3.booking_id = b.id AND bu3.bus_number = :busFlt)';
            $params['busFlt'] = $busFilter;
        }
        if ($payStatusFilter !== '' && in_array($payStatusFilter, $validPayStatus, true)) {
            $where[] = 'EXISTS (SELECT 1 FROM payments p2
                                 WHERE p2.booking_id = b.id AND p2.status = :payStFlt)';
            $params['payStFlt'] = $payStatusFilter;
        }

        $sql = "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.sold_by_admin_id,
                       r.from_city, r.to_city, bl.travel_date,
                       (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                          FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats,
                       (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
                       (SELECT bp.full_name FROM booking_passengers bp
                         WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger,
                       (SELECT p.method FROM payments p
                         WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
                       ad.full_name AS seller_name, ad.username AS seller_user
                  FROM bookings b
                  LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                  LEFT JOIN schedules s ON s.id = bl.schedule_id
                  LEFT JOIN routes r ON r.id = s.route_id
                  LEFT JOIN admins ad ON ad.id = b.sold_by_admin_id"
             . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY b.id DESC LIMIT 2000';

        $rows = Database::fetchAll($sql, $params);

        $rangeLabel = '';
        if ($isDate($from) && $isDate($to)) {
            $rangeLabel = formatDate($from) . ' - ' . formatDate($to);
        } elseif ($isDate($from)) {
            $rangeLabel = 'from ' . formatDate($from);
        } elseif ($isDate($to)) {
            $rangeLabel = 'until ' . formatDate($to);
        } else {
            $rangeLabel = 'All bookings';
        }

        $tickets    = count($rows);
        $confirmed  = 0;
        $revenue    = 0.0;
        foreach ($rows as $r) {
            if ((string) $r['status'] === 'confirmed') {
                $confirmed++;
                $revenue += (float) $r['total_amount'];
            }
        }

        $rpt = new self('BOOKINGS REPORT', $rangeLabel);

        $rpt->summaryCards([
            ['label' => 'BOOKINGS', 'value' => (string) $tickets, 'sub' => 'in report',       'color' => self::BLUE],
            ['label' => 'CONFIRMED','value' => (string) $confirmed, 'sub' => 'ready to travel', 'color' => self::GREEN],
            ['label' => 'REVENUE',  'value' => inr($revenue), 'sub' => 'confirmed sales',        'color' => self::NAVY],
        ]);

        $rpt->sectionHeading('Bookings — ' . $tickets . ($tickets === 1 ? ' record' : ' records'));

        $rpt->setColumns([
            ['label' => 'PNR',        'width' => 85],
            ['label' => 'Booked at',  'width' => 80],
            ['label' => 'Passenger',  'width' => 110],
            ['label' => 'Route',      'width' => 115],
            ['label' => 'Travel',     'width' => 65],
            ['label' => 'Seat(s)',    'width' => 70,  'align' => 'C'],
            ['label' => 'Amount',     'width' => 60,  'align' => 'R'],
            ['label' => 'Payment',    'width' => 50,  'align' => 'C'],
            ['label' => 'Seller',     'width' => 95],
            ['label' => 'Agent code', 'width' => 55,  'align' => 'C'],
            ['label' => 'Status',     'width' => 55,  'align' => 'C'],
        ]);
        $rpt->tableHeader();

        foreach ($rows as $i => $b) {
            $sellerId = (int) ($b['sold_by_admin_id'] ?? 0);
            $sellerName = $sellerId > 0
                ? (string) ($b['seller_name'] ?: $b['seller_user'])
                : 'Online';
            $agentCode = $sellerId > 0 ? AgentWallet::agentCodeLabel($sellerId) : '';

            $payS = (string) ($b['pay_method'] ?? '');
            $pay  = $payS !== '' ? strtoupper($payS) : '-';

            $rpt->tableRow([
                (string) $b['pnr'],
                $b['created_at'] ? formatDate((string) $b['created_at'], 'j M, H:i') : '-',
                (string) ($b['passenger'] ?: '-'),
                ($b['from_city'] ?? '-') . ' -> ' . ($b['to_city'] ?? '-'),
                $b['travel_date'] ? formatDate((string) $b['travel_date'], 'j M Y') : '-',
                (string) ($b['seats'] ?: ('x' . (int) $b['seat_count'])),
                inr((float) $b['total_amount']),
                $pay,
                $sellerName,
                $agentCode ?: '-',
                strtoupper((string) $b['status']),
            ], $i % 2 === 1);
        }

        if ($tickets > 0) {
            $rpt->tableTotals([
                'TOTAL', '', '', '', '',
                (string) $tickets,
                inr($revenue),
                '', '', '', $confirmed . ' conf.',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'shg_bookings_pdf_');
        if ($tmp === false) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('shg_bookings_', true);
        }
        // Rename to .pdf so browsers/OS pick the right MIME when the file is
        // handed on directly. tempnam gives us a bare handle; we replace it.
        $path = $tmp . '.pdf';
        @rename($tmp, $path);
        file_put_contents($path, $rpt->output());

        Logger::audit('bookings.report.pdf', 'report', 'bookings',
            null, null, $tickets . ' rows, ' . $rangeLabel);

        return $path;
    }


    /**
     * Per-customer trip history + total spend PDF. Bookings are matched by
     * contact_phone (unformatted); an optional date window narrows the range.
     *
     * @return string  Absolute path of the generated PDF on disk.
     */
    public static function customerReport(PDO $db, string $phone, ?string $from = null, ?string $to = null): string
    {
        unset($db);

        $phone = trim($phone);
        if ($phone === '') {
            throw new InvalidArgumentException('Customer phone is required.');
        }

        $isDate = static fn (?string $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

        $where  = ['b.contact_phone = :phone'];
        $params = ['phone' => $phone];
        if ($isDate($from)) {
            $where[]        = 'b.created_at >= :from';
            $params['from'] = $from;
        }
        if ($isDate($to)) {
            $where[]         = 'b.created_at < :toEnd';
            $params['toEnd'] = addDaysISO((string) $to, 1);
        }

        $rows = Database::fetchAll(
            "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.contact_email,
                    b.created_at, b.sold_by_admin_id,
                    r.from_city, r.to_city, bl.travel_date,
                    (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                       FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
                    (SELECT bp.full_name FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger,
                    (SELECT p.method FROM payments p
                      WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
                    ad.full_name AS seller_name, ad.username AS seller_user
               FROM bookings b
               LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
               LEFT JOIN schedules s ON s.id = bl.schedule_id
               LEFT JOIN routes r ON r.id = s.route_id
               LEFT JOIN admins ad ON ad.id = b.sold_by_admin_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY b.id DESC LIMIT 1000",
            $params
        );

        // Customer identity — try users first, fall back to the first booking.
        $user = Database::fetch(
            'SELECT full_name, email, country_code, loyalty_points, total_trips, created_at
               FROM users WHERE phone = :phone LIMIT 1',
            ['phone' => $phone]
        );

        $customerName = (string) (
            ($user['full_name'] ?? '') !== ''
                ? $user['full_name']
                : ($rows[0]['passenger'] ?? '')
        );
        if ($customerName === '') {
            $customerName = 'Customer';
        }
        $customerEmail = (string) (($user['email'] ?? '') ?: ($rows[0]['contact_email'] ?? ''));
        $countryCode   = (string) ($user['country_code'] ?? '91');

        $totalTickets   = count($rows);
        $totalConfirmed = 0;
        $totalSpend     = 0.0;
        $totalSeats     = 0;
        foreach ($rows as $r) {
            if ((string) $r['status'] === 'confirmed') {
                $totalConfirmed++;
                $totalSpend += (float) $r['total_amount'];
            }
            $totalSeats += (int) $r['seat_count'];
        }

        $sub = $isDate($from) && $isDate($to)
            ? formatDate((string) $from) . ' - ' . formatDate((string) $to)
            : ($isDate($from)
                ? 'from ' . formatDate((string) $from)
                : ($isDate($to) ? 'until ' . formatDate((string) $to) : 'All-time history'));

        $rpt = new self('CUSTOMER TRIP HISTORY', $customerName . ' | ' . $sub);

        $rpt->infoBlock([
            ['Customer',       $customerName],
            ['Phone',          '+' . $countryCode . ' ' . $phone],
            ['Email',          $customerEmail !== '' ? $customerEmail : '-'],
            ['Loyalty points', (string) (int) ($user['loyalty_points'] ?? 0)],
            ['Since',          !empty($user['created_at']) ? formatDate((string) $user['created_at']) : '-'],
            ['Report period',  $sub],
        ]);

        $rpt->summaryCards([
            ['label' => 'BOOKINGS',    'value' => (string) $totalTickets, 'sub' => $totalConfirmed . ' confirmed', 'color' => self::BLUE],
            ['label' => 'TOTAL SPEND', 'value' => inr($totalSpend),       'sub' => 'confirmed sales',              'color' => self::GREEN],
            ['label' => 'SEATS',       'value' => (string) $totalSeats,   'sub' => 'across all trips',             'color' => self::NAVY],
        ]);

        $rpt->sectionHeading('Trip Detail — ' . $totalTickets . ($totalTickets === 1 ? ' trip' : ' trips'));

        $rpt->setColumns([
            ['label' => '#',         'width' => 24,  'align' => 'C'],
            ['label' => 'PNR',       'width' => 90],
            ['label' => 'Booked at', 'width' => 85],
            ['label' => 'Route',     'width' => 140],
            ['label' => 'Travel',    'width' => 80],
            ['label' => 'Seat(s)',   'width' => 80,  'align' => 'C'],
            ['label' => 'Amount',    'width' => 70,  'align' => 'R'],
            ['label' => 'Payment',   'width' => 55,  'align' => 'C'],
            ['label' => 'Sold by',   'width' => 110],
            ['label' => 'Status',    'width' => 60,  'align' => 'C'],
        ]);
        $rpt->tableHeader();

        foreach ($rows as $i => $b) {
            $sellerId = (int) ($b['sold_by_admin_id'] ?? 0);
            if ($sellerId > 0) {
                $code       = AgentWallet::agentCodeLabel($sellerId);
                $sellerName = (string) ($b['seller_name'] ?: $b['seller_user']);
                $sold       = trim(($code !== '' ? $code . ' ' : '') . $sellerName);
            } else {
                $sold = 'Online';
            }
            $payS = (string) ($b['pay_method'] ?? '');
            $pay  = $payS !== '' ? strtoupper($payS) : '-';

            $rpt->tableRow([
                (string) ($i + 1),
                (string) $b['pnr'],
                $b['created_at'] ? formatDate((string) $b['created_at'], 'j M, H:i') : '-',
                ($b['from_city'] ?? '-') . ' -> ' . ($b['to_city'] ?? '-'),
                $b['travel_date'] ? formatDate((string) $b['travel_date'], 'j M Y') : '-',
                (string) ($b['seats'] ?: ('x' . (int) $b['seat_count'])),
                inr((float) $b['total_amount']),
                $pay,
                $sold ?: '-',
                strtoupper((string) $b['status']),
            ], $i % 2 === 1);
        }

        if ($totalTickets > 0) {
            $rpt->tableTotals([
                '', '', 'TOTAL', '', '',
                (string) $totalSeats,
                inr($totalSpend),
                '', '',
                $totalConfirmed . ' conf.',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'shg_customer_pdf_');
        if ($tmp === false) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('shg_customer_', true);
        }
        $path = $tmp . '.pdf';
        @rename($tmp, $path);
        file_put_contents($path, $rpt->output());

        Logger::audit('customer.report.pdf', 'user', $phone,
            null, null, $totalTickets . ' bookings, ' . $sub);

        return $path;
    }
}
