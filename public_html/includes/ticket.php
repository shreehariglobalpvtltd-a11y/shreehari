<?php
/**
 * =====================================================================
 *  Ticket — issues ticket records and renders the branded PDF ticket,
 *  boarding pass and GST invoice.
 *
 *  A ticket is only ever created for a confirmed booking (payment
 *  verified). The QR payload is HMAC-signed so a forged or altered
 *  ticket is detected the moment it is scanned at boarding.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Ticket
{
    private const NAVY   = [26, 58, 106];
    private const BLUE   = [46, 95, 168];
    private const ORANGE = [240, 124, 31];
    private const GOLD   = [255, 200, 40];
    private const INK    = [30, 30, 40];
    private const MUTE   = [120, 120, 130];
    private const LINE   = [222, 222, 230];
    private const PANEL  = [246, 247, 251];

    /**
     * Build the signed QR payload for a booking, matching the pipe-format
     * used by the original single-file build.
     */
    public static function qrPayload(array $booking, array $seats): string
    {
        $core = implode('|', [
            'SHG-TICKET',
            $booking['pnr'],
            $booking['travel_date'] ?? ($booking['date'] ?? ''),
            implode('+', $seats),
            ($booking['from_city'] ?? '') . '->' . ($booking['to_city'] ?? ''),
            $booking['contact_phone'] ?? '',
        ]);

        $signature = substr(Security::sign($core), 0, 16);

        return $core . '|' . $signature;
    }

    /**
     * Verify a scanned QR payload. Returns the PNR when valid, null when
     * the signature does not match (tampered or foreign ticket).
     */
    public static function verifyQrPayload(string $payload): ?string
    {
        $parts = explode('|', $payload);

        if (count($parts) < 7 || $parts[0] !== 'SHG-TICKET') {
            return null;
        }

        $signature = array_pop($parts);
        $core      = implode('|', $parts);

        if (!hash_equals(substr(Security::sign($core), 0, 16), $signature)) {
            return null;
        }

        return $parts[1]; // the PNR
    }

    /**
     * Create the ticket row for a confirmed booking (idempotent).
     *
     * @return array<string, mixed> the ticket row
     */
    public static function issue(int $bookingId): array
    {
        $existing = Database::fetch('SELECT * FROM tickets WHERE booking_id = :b LIMIT 1', ['b' => $bookingId]);
        if ($existing !== null) {
            return $existing;
        }

        $booking = self::loadBooking($bookingId);
        $seats   = self::seatNumbers($bookingId);

        $payload = self::qrPayload($booking, $seats);
        $hash    = Security::sign($payload);

        $ticketId = Database::insert('tickets', [
            'booking_id'    => $bookingId,
            'ticket_number' => generateTicketNumber(),
            'qr_payload'    => $payload,
            'qr_hash'       => $hash,
            'issued_at'     => date('Y-m-d H:i:s'),
        ]);

        Logger::audit('ticket.issue', 'booking', $booking['pnr'], null, null, 'Ticket issued');

        return Database::fetch('SELECT * FROM tickets WHERE id = :id', ['id' => $ticketId]) ?? [];
    }

    /**
     * Generate (and cache) the ticket PDF, returning its absolute path.
     */
    public static function pdfPath(int $bookingId, bool $force = false): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);

        $filename = 'ticket_' . $booking['pnr'] . '.pdf';
        $path     = TICKET_PATH . '/' . $filename;

        if (!$force && is_file($path) && !empty($ticket['pdf_path'])) {
            return $path;
        }

        self::renderWithLock($path, !$force, static function () use ($booking, $ticket, $path, $filename) {
            self::renderTicketPdf($booking, $ticket)->save($path);
            Database::update('tickets', ['pdf_path' => $filename], 'id = :id', ['id' => $ticket['id']]);
        });

        return $path;
    }

    /**
     * Generate (and cache) the GST invoice PDF.
     */
    public static function invoicePath(int $bookingId, bool $force = false): string
    {
        $ticket  = self::issue($bookingId);
        $booking = self::loadBooking($bookingId);

        $filename = 'invoice_' . $booking['pnr'] . '.pdf';
        $path     = INVOICE_PATH . '/' . $filename;

        if (!$force && is_file($path) && !empty($ticket['invoice_path'])) {
            return $path;
        }

        self::renderWithLock($path, !$force, static function () use ($booking, $ticket, $path, $filename) {
            self::renderInvoicePdf($booking)->save($path);
            Database::update('tickets', ['invoice_path' => $filename], 'id = :id', ['id' => $ticket['id']]);
        });

        return $path;
    }

    /**
     * Serialize concurrent first-time renders of the same PDF (e.g. a
     * WhatsApp link-preview crawler and the actual customer both hitting
     * download-ticket.php within the same second) so two requests never
     * render and write the same file at once. Uses an exclusive flock on
     * a sibling .lock file, with a double-checked is_file() re-read once
     * the lock is held so the second caller just reuses the first's
     * output instead of rendering again.
     */
    private static function renderWithLock(string $path, bool $skipIfExists, callable $render): void
    {
        ensureDir(dirname($path));
        $fp = @fopen($path . '.lock', 'c');
        if ($fp === false) {
            // Locking unavailable (e.g. restrictive shared-hosting perms) —
            // fall back to rendering directly rather than failing the download.
            $render();
            return;
        }
        flock($fp, LOCK_EX);
        try {
            if (!$skipIfExists || !is_file($path)) {
                $render();
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }


    /* =================================================================
     *  Data loading
     * ================================================================= */

    /**
     * @return array<string, mixed>
     */
    private static function loadBooking(int $bookingId): array
    {
        $booking = Database::fetch(
            'SELECT b.*, l.travel_date, l.boarding_stop, l.drop_stop, l.boarding_time,
                    r.from_city, r.to_city, r.route_code, r.dep_time, r.arr_time,
                    r.crew_name, r.crew_phone, r.duration_text,
                    bus.bus_name, bus.bus_number, bus.coach_type
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = \'outbound\'
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bus ON bus.id = s.bus_id
              WHERE b.id = :id
              LIMIT 1',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw new RuntimeException('Booking not found for ticket generation.');
        }

        $booking['passengers'] = Database::fetchAll(
            'SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id',
            ['b' => $bookingId]
        );

        return $booking;
    }

    /**
     * @return array<int, string>
     */
    private static function seatNumbers(int $bookingId): array
    {
        return array_map('strval', pluck(
            Database::fetchAll(
                'SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY seat_no',
                ['b' => $bookingId]
            ),
            'seat_no'
        ));
    }


    /* =================================================================
     *  PDF layouts
     * ================================================================= */

    private static function renderTicketPdf(array $booking, array $ticket): Pdf
    {
        $pdf = new Pdf();
        $W   = $pdf->width();
        $seats = self::seatNumbers((int) $booking['id']);

        $company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
        $cin     = Settings::getString('company_cin', '');
        $mantra  = Settings::getString('company_mantra', '');

        /* ---- Header band ---- */
        $pdf->rect(0, 0, $W, 96, self::NAVY, true);
        $pdf->rect(0, 96, $W, 4, self::ORANGE, true);
        $pdf->text(40, 26, strtoupper($company), 19, 'F2', [255, 255, 255]);
        $pdf->text(40, 54, $booking['from_city'] . '  ->  ' . $booking['to_city'] . '   |   International E-Ticket', 11, 'F1', self::GOLD);
        if ($cin !== '') {
            $pdf->text(40, 72, 'CIN: ' . $cin, 8, 'F1', [200, 210, 230]);
        }
        $pdf->textRight($W - 40, 30, 'PNR', 9, 'F1', [190, 200, 220]);
        $pdf->textRight($W - 40, 44, $booking['pnr'], 15, 'F2', [255, 255, 255]);

        /* ---- Journey summary card ---- */
        $cardY = 122;
        $pdf->roundedRect(40, $cardY, $W - 80, 150, 10, self::PANEL, true);
        $pdf->roundedRect(40, $cardY, $W - 80, 150, 10, self::LINE, false);

        $pdf->text(60, $cardY + 18, 'FROM', 8, 'F2', self::MUTE);
        $pdf->text(60, $cardY + 32, $booking['from_city'], 16, 'F2', self::NAVY);
        $pdf->text(60, $cardY + 56, 'Dep ' . formatTime($booking['dep_time']), 10, 'F1', self::INK);

        $pdf->textCenter(60, $W - 60, $cardY + 34, '--------  ' . ($booking['duration_text'] ?? '') . '  -------->', 10, 'F1', self::ORANGE);

        $pdf->textRight($W - 60, $cardY + 18, 'TO', 8, 'F2', self::MUTE);
        $pdf->textRight($W - 60, $cardY + 32, $booking['to_city'], 16, 'F2', self::NAVY);
        $pdf->textRight($W - 60, $cardY + 56, 'Arr ' . formatTime($booking['arr_time']), 10, 'F1', self::INK);

        $pdf->line(60, $cardY + 80, $W - 60, $cardY + 80, self::LINE, 0.5);

        $pdf->text(60, $cardY + 92, 'DATE OF JOURNEY', 8, 'F2', self::MUTE);
        $pdf->text(60, $cardY + 106, formatDate($booking['travel_date'], 'l, j F Y'), 12, 'F2', self::INK);

        $pdf->text(60, $cardY + 128, 'BUS', 8, 'F2', self::MUTE);
        $pdf->text(60, $cardY + 140, ($booking['bus_name'] ?? '') . '  ·  ' . ($booking['bus_number'] ?? ''), 10, 'F1', self::INK);

        /* ---- QR block, right side ---- */
        $qrBinary = self::qrBinary($ticket['qr_payload'], 8);
        $pdf->imageFromString($qrBinary, $W - 180, $cardY + 86, 100, 100);
        $pdf->textCenter($W - 180, $W - 80, $cardY + 190, 'Scan at boarding', 8, 'F1', self::MUTE);

        /* ---- Passenger table ---- */
        $tableY = $cardY + 172;
        $pdf->text(40, $tableY, 'PASSENGERS', 10, 'F2', self::NAVY);
        $tableY += 20;

        $pdf->rect(40, $tableY, $W - 80, 22, self::NAVY, true);
        $pdf->text(52, $tableY + 6, 'Name', 9, 'F2', [255, 255, 255]);
        $pdf->text(300, $tableY + 6, 'Age', 9, 'F2', [255, 255, 255]);
        $pdf->text(360, $tableY + 6, 'Gender', 9, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 52, $tableY + 6, 'Seat', 9, 'F2', [255, 255, 255]);
        $tableY += 22;

        foreach ($booking['passengers'] as $i => $pax) {
            if ($i % 2 === 1) {
                $pdf->rect(40, $tableY, $W - 80, 20, [240, 242, 247], true);
            }
            $pdf->text(52, $tableY + 5, (string) $pax['full_name'], 10, 'F1', self::INK);
            $pdf->text(300, $tableY + 5, $pax['age'] !== null ? (string) $pax['age'] : '-', 10, 'F1', self::INK);
            $pdf->text(360, $tableY + 5, (string) ($pax['gender'] ?? '-'), 10, 'F1', self::INK);
            $pdf->textRight($W - 52, $tableY + 5, (string) $pax['seat_no'], 10, 'F2', self::NAVY);
            $tableY += 20;
        }

        $pdf->line(40, $tableY + 2, $W - 40, $tableY + 2, self::LINE, 0.5);

        /* ---- Boarding + fare ---- */
        $boardY = $tableY + 18;
        $pdf->text(40, $boardY, 'BOARDING POINT', 8, 'F2', self::MUTE);
        $pdf->textBlock(40, $boardY + 12, 300, (string) ($booking['boarding_stop'] ?? '-'), 10, 'F1', self::INK);
        $pdf->text(40, $boardY + 44, 'DROP POINT', 8, 'F2', self::MUTE);
        $pdf->textBlock(40, $boardY + 56, 300, (string) ($booking['drop_stop'] ?? '-'), 10, 'F1', self::INK);

        $pdf->roundedRect($W - 220, $boardY, 180, 70, 8, self::NAVY, true);
        $pdf->text($W - 205, $boardY + 12, 'TOTAL PAID', 9, 'F1', self::GOLD);
        $pdf->text($W - 205, $boardY + 28, inr((float) $booking['total_amount']), 22, 'F2', [255, 255, 255]);
        $pdf->text($W - 205, $boardY + 56, count($seats) . ' seat(s) · ' . strtoupper((string) $booking['status']), 8, 'F1', [200, 210, 230]);

        /* ---- Footer / checklist ---- */
        $footY = $pdf->height() - 96;
        $pdf->line(40, $footY, $W - 40, $footY, self::LINE, 0.5);
        $checklist = Settings::getArray('travel_checklist', []);
        $footText  = 'Carry: ' . implode('  ·  ', array_map('strval', array_slice($checklist, 0, 3)));
        $pdf->textBlock(40, $footY + 8, $W - 80, $footText !== 'Carry: ' ? $footText : 'Carry a valid government photo ID and this ticket.', 8, 'F1', self::MUTE);

        $pdf->textCenter(40, $W - 40, $pdf->height() - 30, $mantra . '   ·   Safe journey with ' . $company, 9, 'F3', self::ORANGE);

        return $pdf;
    }

    private static function renderInvoicePdf(array $booking): Pdf
    {
        $pdf = new Pdf();
        $W   = $pdf->width();

        $company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
        $address = Settings::getString('company_address', '');
        $cin     = Settings::getString('company_cin', '');
        $gstin   = Settings::getString('company_gstin', '');

        $pdf->rect(0, 0, $W, 70, self::NAVY, true);
        $pdf->text(40, 20, strtoupper($company), 16, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 40, 22, 'TAX INVOICE', 16, 'F2', self::GOLD);

        $pdf->textBlock(40, 84, 320, $address, 9, 'F1', self::MUTE);
        if ($cin !== '')   $pdf->text(40, 120, 'CIN: ' . $cin, 9, 'F1', self::INK);
        if ($gstin !== '') $pdf->text(40, 134, 'GSTIN: ' . $gstin, 9, 'F1', self::INK);

        $pdf->textRight($W - 40, 84, 'Invoice for PNR: ' . $booking['pnr'], 10, 'F2', self::INK);
        $pdf->textRight($W - 40, 100, 'Date: ' . formatDate(substr((string) $booking['created_at'], 0, 10), 'j M Y'), 9, 'F1', self::MUTE);

        $pdf->line(40, 156, $W - 40, 156, self::LINE, 0.5);

        $pdf->text(40, 168, 'Billed to', 9, 'F2', self::MUTE);
        $pdf->text(40, 182, maskPhone((string) $booking['contact_phone']), 11, 'F1', self::INK);

        /* Line items */
        $y = 220;
        $pdf->rect(40, $y, $W - 80, 24, self::NAVY, true);
        $pdf->text(52, $y + 7, 'Description', 10, 'F2', [255, 255, 255]);
        $pdf->textRight($W - 52, $y + 7, 'Amount', 10, 'F2', [255, 255, 255]);
        $y += 24;

        $rows = [
            ['Base fare (' . $booking['from_city'] . ' -> ' . $booking['to_city'] . ')', (float) $booking['base_total']],
        ];
        if ((float) $booking['group_discount'] > 0) $rows[] = ['Group discount', -(float) $booking['group_discount']];
        if ((float) $booking['coupon_discount'] > 0) $rows[] = ['Coupon ' . ($booking['coupon_code'] ?? ''), -(float) $booking['coupon_discount']];
        if ((float) $booking['tier_discount'] > 0)   $rows[] = [($booking['tier_name'] ?? 'Member') . ' discount', -(float) $booking['tier_discount']];
        if ((float) $booking['points_value'] > 0)    $rows[] = ['Loyalty points redeemed', -(float) $booking['points_value']];
        if ((float) $booking['tax_amount'] > 0)      $rows[] = ['Taxes', (float) $booking['tax_amount']];
        if ((float) $booking['booking_fee'] > 0)     $rows[] = ['Booking fee', (float) $booking['booking_fee']];

        foreach ($rows as $i => $row) {
            if ($i % 2 === 1) $pdf->rect(40, $y, $W - 80, 22, [242, 244, 249], true);
            $pdf->text(52, $y + 6, (string) $row[0], 10, 'F1', self::INK);
            $pdf->textRight($W - 52, $y + 6, ($row[1] < 0 ? '- ' : '') . inr(abs($row[1])), 10, 'F1', self::INK);
            $y += 22;
        }

        $pdf->line(40, $y + 4, $W - 40, $y + 4, self::LINE, 0.8);
        $y += 14;
        $pdf->text($W - 260, $y, 'TOTAL', 13, 'F2', self::NAVY);
        $pdf->textRight($W - 52, $y, inr((float) $booking['total_amount']), 15, 'F2', self::NAVY);

        if ($gstin === '') {
            $pdf->text(40, $y + 40, 'Note: This is a fare receipt. GST is not charged as the operator is not currently GST-registered for this service.', 8, 'F3', self::MUTE);
        }

        $pdf->textCenter(40, $W - 40, $pdf->height() - 40, 'Thank you for travelling with ' . $company, 10, 'F1', self::MUTE);

        return $pdf;
    }

    /**
     * QR PNG bytes for embedding, resilient to a QR failure so ticket
     * generation never dies over an unreadable payload.
     */
    private static function qrBinary(string $payload, int $scale): string
    {
        try {
            $uri = QrCode::dataUri($payload, $scale, 2, QrCode::ECC_M);
            return base64_decode(explode(',', $uri)[1] ?? '');
        } catch (Throwable $e) {
            Logger::error('QR generation failed for ticket: ' . $e->getMessage());
            return '';
        }
    }
}
