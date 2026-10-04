<?php
/**
 * includes/sellermail.php — the ticket, by email, to the agent / counter
 * who sold it (4 Oct 2026, owner: "agent wa counter le chalako xa, tesko
 * email entry hunx — tini haru lai email ma jane system").
 *
 * Fires from includes/events.php booking.approved, after the customer
 * notifications. The seller is bookings.sold_by_admin_id; the address is
 * the email saved on their agent / counter profile (admins.email). The
 * ticket PNG is attached (Ticket::pngPath) and the download link is in the
 * body. Never throws, never blocks a sale — a failure is a log line.
 *
 * Switch: setting seller_ticket_email (default on). Uses the ordinary SMTP
 * settings (smtp_host / smtp_user / smtp_pass) through Notify::email().
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class SellerMail
{
    /** @return array{ok: bool, to: string, reason: string} */
    public static function ticketCopy(array $booking, ?string $toOverride = null): array
    {
        $out = ['ok' => false, 'to' => '', 'reason' => ''];
        try {
            if (!Settings::getBool('seller_ticket_email', true)) {
                $out['reason'] = 'switched off';
                return $out;
            }
            $bid = (int) ($booking['id'] ?? 0);
            $pnr = (string) ($booking['pnr'] ?? '');
            $sid = (int) ($booking['sold_by_admin_id'] ?? 0);
            if ($bid <= 0 || $pnr === '' || $sid <= 0) {
                $out['reason'] = 'no seller on this booking';
                return $out;
            }

            $seller = Database::fetch(
                'SELECT id, role, email, full_name, username FROM admins WHERE id = :i AND is_active = 1 LIMIT 1',
                ['i' => $sid]
            );
            $email = $seller !== null ? Security::email((string) ($seller['email'] ?? '')) : '';
            if ($toOverride !== null) {
                $email = Security::email($toOverride);   // test / manual resend to another address
            }
            if ($email === '') {
                $out['reason'] = 'seller has no email on their profile';
                Logger::info('Seller ticket email skipped: no email', ['pnr' => $pnr, 'seller' => $sid], 'mail');
                return $out;
            }
            $out['to'] = $email;

            require_once __DIR__ . '/notify.php';
            if (!class_exists('Ticket')) {
                require_once __DIR__ . '/ticket.php';
            }

            $attachments = [];
            try {
                $png = Ticket::pngPath($bid);
                if (is_file($png) && filesize($png) > 0 && filesize($png) < 8 * 1024 * 1024) {
                    $attachments[] = ['name' => 'ticket-' . $pnr . '.png', 'mime' => 'image/png', 'data' => (string) file_get_contents($png)];
                }
            } catch (Throwable $e) {
                Logger::warning('Seller ticket email: PNG render failed', ['pnr' => $pnr, 'err' => $e->getMessage()], 'mail');
            }

            $company = Settings::getString('company_name', defined('APP_NAME') ? APP_NAME : 'S Hari Global');
            $link    = Ticket::imageUrl($pnr);
            $name    = trim((string) ($seller['full_name'] ?? '')) ?: (string) ($seller['username'] ?? '');

            // Passenger / journey facts — read-only.
            $pax = Database::fetchAll('SELECT full_name, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bid]);
            $leg = Database::fetch(
                'SELECT bl.travel_date, bl.boarding_stop, bl.drop_stop, r.from_city, r.to_city, r.dep_time
                   FROM booking_legs bl
                   JOIN schedules s ON s.id = bl.schedule_id
                   JOIN routes    r ON r.id = s.route_id
                  WHERE bl.booking_id = :b ORDER BY bl.id ASC LIMIT 1',
                ['b' => $bid]
            );
            $rows = [
                'Ticket ID' => $pnr,
                'Route'     => $leg !== null ? trim((string) $leg['from_city']) . ' → ' . trim((string) $leg['to_city']) : '',
                'Date'      => $leg !== null ? (string) $leg['travel_date'] . (empty($leg['dep_time']) ? '' : ' · ' . substr((string) $leg['dep_time'], 0, 5)) : '',
                'Boarding'  => $leg !== null ? trim(explode('·', (string) ($leg['boarding_stop'] ?? ''))[0]) : '',
                'Drop'      => $leg !== null ? trim(explode('·', (string) ($leg['drop_stop'] ?? ''))[0]) : '',
                'Passengers'=> implode(', ', array_map(static fn($p) => trim((string) $p['full_name']) . ' (' . (string) $p['seat_no'] . ')', $pax)),
                'Phone'     => (string) ($booking['contact_phone'] ?? ''),
                'Amount'    => function_exists('inr') ? inr((float) ($booking['total_amount'] ?? 0)) : (string) ($booking['total_amount'] ?? ''),
            ];
            $table = '';
            foreach ($rows as $k => $v) {
                if (trim($v) === '') {
                    continue;
                }
                $table .= '<tr><td style="padding:6px 10px;color:#555">' . e($k) . '</td><td style="padding:6px 10px"><strong>' . e($v) . '</strong></td></tr>';
            }

            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;max-width:560px">'
                . '<h2 style="margin:0 0 8px">' . e($company) . ' — Ticket confirmed</h2>'
                . '<p>Namaste ' . e($name) . ', the ticket you booked is confirmed. The ticket picture is attached — '
                . 'forward it to the passenger on WhatsApp or print it.</p>'
                . '<table style="border-collapse:collapse;border:1px solid #ddd">' . $table . '</table>'
                . '<p style="margin-top:14px"><a href="' . e($link) . '" style="background:#c0392b;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Open / download ticket</a></p>'
                . '<p style="color:#888;font-size:12px">टिकट यसै इमेलमा संलग्न छ। यात्रुलाई WhatsApp मा पठाउनुहोस्।</p>'
                . '</div>';
            $text = $company . " - Ticket confirmed\n";
            foreach ($rows as $k => $v) {
                if (trim($v) !== '') {
                    $text .= $k . ': ' . $v . "\n";
                }
            }
            $text .= 'Ticket: ' . $link . "\n";

            $ok = Notify::email($email, 'Ticket ' . $pnr . ' confirmed', $html, $text, $attachments);
            $out['ok'] = $ok;
            $out['reason'] = $ok ? '' : 'SMTP send failed (see logs, channel mail)';
            Logger::info('Seller ticket email ' . ($ok ? 'sent' : 'FAILED'), [
                'pnr' => $pnr, 'seller' => $sid, 'to' => $email, 'png' => $attachments !== [],
            ], 'mail');
            return $out;
        } catch (Throwable $e) {
            $out['reason'] = $e->getMessage();
            Logger::warning('Seller ticket email error', ['err' => $e->getMessage(), 'pnr' => (string) ($booking['pnr'] ?? '')], 'mail');
            return $out;
        }
    }
}
