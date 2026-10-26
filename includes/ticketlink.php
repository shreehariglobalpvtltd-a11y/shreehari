<?php
/**
 * includes/ticketlink.php — one short link per ticket (10 Oct 2026).
 *
 *     https://www.shreehariglobal.in/ticket/view.php?code=Xk3p9QaT
 *
 * The keyed ticket URLs (Ticket::imageUrl / downloadUrl) are ~150
 * characters: fine inside our own messages, clumsy when an AGENT forwards
 * the ticket by hand from their own WhatsApp, reads it out on the phone or
 * types it into an SMS. The short code stands for the booking; the page
 * (ticket/view.php) builds the CURRENT keyed links on every open, so a
 * corrected ticket (new revision, new image URL) never leaves a stale link.
 *
 * The code is 10 random base-58 characters (~58 bits): it is the key, the
 * way the &k= token is the key of the long link. A link dies at
 * expires_at = travel date + ticket_link_days.
 *
 * Never throws to its callers.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class TicketLink
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    private const LEN = 10;

    private static ?bool $ready = null;

    private function __construct() {}

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'ticket_links'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** The short URL for this booking ('' when unavailable). Reuses a live code. */
    public static function forBooking(int $bookingId): string
    {
        $code = self::code($bookingId);
        return $code !== '' ? self::url($code) : '';
    }

    public static function url(string $code): string
    {
        return appUrl('ticket/view.php?code=' . rawurlencode($code));
    }

    /** The live short code for this booking, minted on first use. '' on failure. */
    public static function code(int $bookingId): string
    {
        if ($bookingId <= 0 || !self::ready()) {
            return '';
        }
        try {
            $live = (string) Database::scalar(
                'SELECT short_code FROM ticket_links WHERE booking_id = :b AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
                ['b' => $bookingId], ''
            );
            if ($live !== '') {
                return $live;
            }
            $b = Database::fetch('SELECT id, pnr FROM bookings WHERE id = :b', ['b' => $bookingId]);
            if ($b === null || (string) $b['pnr'] === '') {
                return '';
            }
            if (!class_exists('Ticket')) {
                foreach (['qr', 'pdf', 'ticket'] as $f) {
                    require_once INCLUDE_PATH . '/' . $f . '.php';
                }
            }
            $full = Ticket::imageUrl((string) $b['pnr']);
            $exp  = self::expiryFor($bookingId);
            for ($i = 0; $i < 4; $i++) {
                $code = self::random();
                $ok = Database::run(
                    'INSERT IGNORE INTO ticket_links (booking_id, short_code, full_url, expires_at) VALUES (:b, :c, :u, :e)',
                    ['b' => $bookingId, 'c' => $code, 'u' => mb_substr($full, 0, 500), 'e' => $exp]
                );
                if ($ok > 0) {
                    return $code;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('ticket link mint failed: ' . $e->getMessage(), ['booking' => $bookingId], 'whatsapp');
        }
        return '';
    }

    /**
     * The booking a code stands for, or null (unknown / expired / malformed).
     * Counts the open.
     *
     * @return array<string,mixed>|null bookings row
     */
    public static function resolve(string $code): ?array
    {
        $code = trim($code);
        if (!self::ready() || strlen($code) !== self::LEN || strspn($code, self::ALPHABET) !== self::LEN) {
            return null;
        }
        try {
            $row = Database::fetch(
                'SELECT id, booking_id FROM ticket_links WHERE short_code = :c AND expires_at > NOW() LIMIT 1',
                ['c' => $code]
            );
            if ($row === null) {
                return null;
            }
            Database::run('UPDATE ticket_links SET hits = hits + 1, last_hit_at = NOW() WHERE id = :i', ['i' => (int) $row['id']]);
            return Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $row['booking_id']]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** travel date + ticket_link_days (default 30), at least 2 days from now. */
    private static function expiryFor(int $bookingId): string
    {
        $days = max(1, min(365, Settings::getInt('ticket_link_days', 30)));
        $travel = (string) Database::scalar(
            "SELECT MAX(travel_date) FROM booking_legs WHERE booking_id = :b",
            ['b' => $bookingId], ''
        );
        $base = $travel !== '' ? strtotime($travel . ' 23:59:59') : time();
        $at = max((int) $base + $days * 86400, time() + 2 * 86400);
        return date('Y-m-d H:i:s', $at);
    }

    private static function random(): string
    {
        $out = '';
        $n = strlen(self::ALPHABET);
        for ($i = 0; $i < self::LEN; $i++) {
            $out .= self::ALPHABET[random_int(0, $n - 1)];
        }
        return $out;
    }
}
