<?php
/**
 * =====================================================================
 *  includes/wachat.php — one-time WhatsApp ticket codes (26 Sep 2026).
 *
 *  THE RULE: S Hari never sends the first WhatsApp message. The passenger
 *  taps a button (ticket page) or scans a QR (agent desk) that opens OUR
 *  WhatsApp number with "TICKET K7QM2P" already typed. Their message opens
 *  WhatsApp's free 24 h window and the bot answers it with the ticket.
 *
 *  Why a code and not the PNR: WaBot releases a ticket for a PNR only to
 *  the booking's own mobile number. A family member or a friend who booked
 *  from another phone got the status and nothing else. The code is the
 *  proof instead: whoever the office or the ticket page handed it to may
 *  receive the ticket, once, within 30 minutes.
 *
 *  Security:
 *   - 6 characters from a 31-letter alphabet with no look-alikes
 *     (0/O, 1/I/L), from random_int(): about 887 million codes.
 *   - Only sha256(code) is stored. The code itself is shown once.
 *   - redeem() marks the row used in the same UPDATE that checks it, so two
 *     racing messages cannot both win. Expired, used and unknown codes all
 *     answer the same null — the sender learns nothing about why.
 *   - The link carries the code and nothing else: no PNR, no name, no phone.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class WaChat
{
    /** No 0/O, 1/I/L — a code read aloud over the phone survives. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH    = 6;

    /** The office switch (settings.wa_chat_on). */
    public static function enabled(): bool
    {
        return Settings::getBool('wa_chat_on', true);
    }

    /** Minutes a code stays valid (settings.wa_chat_token_ttl_min, 5..1440). */
    public static function ttlMinutes(): int
    {
        return max(5, min(1440, Settings::getInt('wa_chat_token_ttl_min', 30)));
    }

    /** A fresh random code. Public for the tests; callers use mint(). */
    public static function randomCode(): string
    {
        $max  = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /**
     * Issue a code for a booking and return it in plain text. This is the
     * only moment the plain code exists; show it and forget it.
     */
    public static function mint(int $bookingId, string $source = 'customer', ?int $adminId = null): string
    {
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Invalid booking.');
        }
        $source = $source === 'staff' ? 'staff' : 'customer';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::randomCode();
            try {
                Database::run(
                    'INSERT INTO wa_chat_tokens (booking_id, token_hash, source, admin_id, expires_at)
                     VALUES (:b, :h, :s, :a, DATE_ADD(NOW(), INTERVAL :m MINUTE))',
                    ['b' => $bookingId, 'h' => self::hash($code), 's' => $source,
                     'a' => $adminId, 'm' => self::ttlMinutes()]
                );
                return $code;
            } catch (PDOException $e) {
                // 23000 = the unique hash collided with a live code; draw again.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Could not issue a WhatsApp code, please try again.');
    }

    /** The WhatsApp number codes are sent to, digits only ('' when unset). */
    public static function ticketNumber(): string
    {
        $d = preg_replace('/\D/', '', Settings::getString('wa_ticket_number', '')) ?? '';
        return $d !== '' ? $d : Settings::officeWhatsApp();
    }

    /** The message the passenger sends, e.g. "TICKET K7QM2P". */
    public static function messageFor(string $code): string
    {
        return 'TICKET ' . strtoupper($code);
    }

    /** wa.me link that opens our chat with the code typed. No PII in it. */
    public static function buildLink(string $code, ?string $phoneDigits = null): string
    {
        $phone = preg_replace('/\D/', '', $phoneDigits ?? self::ticketNumber()) ?? '';
        return 'https://wa.me/' . $phone . '?text=' . rawurlencode(self::messageFor($code));
    }

    /** PNG data URI of the QR for a wa.me link (the in-house QR encoder). */
    public static function buildQr(string $waLink, int $scale = 6): string
    {
        require_once INCLUDE_PATH . '/qr.php';
        return QrCode::dataUri($waLink, $scale, 2);
    }

    /**
     * Pull a code out of a WhatsApp message: "TICKET K7QM2P", "ticket k7qm2p",
     * "टिकट K7QM2P". Returns null when the message is not a code request.
     */
    public static function extract(string $text): ?string
    {
        $alpha = self::ALPHABET;
        if (preg_match('/(?:^|\s)(?:TICKET|TIKET|टिकट)\s*[:#-]?\s*([' . $alpha . ']{' . self::LENGTH . '})(?![A-Z0-9])/iu', trim($text), $m)) {
            $code = strtoupper($m[1]);
            return strspn($code, $alpha) === self::LENGTH ? $code : null;
        }
        return null;
    }

    /** True when the message is "TICKET <something>", valid or not. */
    public static function looksLikeRequest(string $text): bool
    {
        return (bool) preg_match('/^\s*(?:TICKET|TIKET|टिकट)\s*[:#-]?\s*[A-Z0-9]{4,8}\s*$/iu', $text);
    }

    /**
     * Spend a code. Returns the booking id, or null for an unknown, used or
     * expired code (never says which). The check and the "used" mark are one
     * UPDATE, so a code can be spent exactly once.
     */
    public static function redeem(string $text): ?int
    {
        $code = self::extract($text);
        if ($code === null) {
            return null;
        }
        $hash = self::hash($code);

        $hit = Database::run(
            'UPDATE wa_chat_tokens SET used_at = NOW()
              WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()',
            ['h' => $hash]
        );
        if ($hit !== 1) {
            return null;
        }
        $id = (int) Database::scalar('SELECT booking_id FROM wa_chat_tokens WHERE token_hash = :h', ['h' => $hash], 0);
        return $id > 0 ? $id : null;
    }

    /** Boolean form of redeem(), as the brief named it. */
    public static function verify(string $text): bool
    {
        return self::redeem($text) !== null;
    }

    /**
     * Housekeeping for cron/expire.php: drop codes that expired unused, and
     * spent codes after a day (kept that long so the office can see a code
     * was used). Returns rows removed.
     */
    public static function expire(): int
    {
        return Database::run(
            'DELETE FROM wa_chat_tokens
              WHERE (used_at IS NULL AND expires_at < NOW())
                 OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))'
        );
    }
}
