<?php
/**
 * =====================================================================
 *  includes/notifier.php — rate-limited office alerts and window-checked
 *  customer messages (26 Sep 2026, Prompt 2 section 12).
 *
 *  Two rules this class exists to keep:
 *   - The office hears about one thing on one booking once per window
 *     (settings.notify_rate_minutes, default 10): a gateway that retries a
 *     webhook five times must not WhatsApp the manager five times.
 *   - A customer is written to on WhatsApp ONLY while their 24 h window is
 *     open, i.e. they wrote to us in the last 24 h (WaTemplates records
 *     every inbound message). S Hari never opens a conversation.
 *
 *  Delivery itself is Notify's (message_logs, providers, country codes).
 *  Never throws.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/notify.php';

final class Notifier
{
    /** Events the office can be alerted about. */
    public const ADMIN_EVENTS = [
        'possible_match_detected', 'payment_unmatched', 'payment_confirmed_webhook',
        'cancel_requested', 'ai_cloud_fallback',
    ];

    /**
     * Alert the office. Returns true when the alert went out, false when it
     * was suppressed by the rate limit (or failed).
     *
     * @param array<string, string|int|float> $facts label => value lines
     * @param string|null $subject what "the same thing" means when there is no
     *                             booking (e.g. one webhook event); default the booking
     */
    public static function notifyAdmin(string $event, ?int $bookingId, string $headline, array $facts = [], ?string $subject = null): bool
    {
        if (!in_array($event, self::ADMIN_EVENTS, true)) {
            $event = 'other';
        }
        $minutes = max(1, Settings::getInt('notify_rate_minutes', 10));
        try {
            // One per booking (or subject) per event per window; no lockout beyond it.
            $who = $subject !== null && $subject !== '' ? 's' . substr(hash('sha256', $subject), 0, 32) : 'b' . (int) $bookingId;
            if (!Security::rateLimit('notify_' . $event, $who, 1, $minutes * 60)) {
                return false;
            }
            Notify::adminNote($headline, $facts, $bookingId);
            return true;
        } catch (Throwable $e) {
            Logger::exception($e);
            return false;
        }
    }

    /**
     * Is the customer's 24 h WhatsApp window open (did they write to us in
     * the last 24 hours)?
     */
    public static function windowOpen(string $phoneDigits): bool
    {
        $d = preg_replace('/\D/', '', $phoneDigits) ?? '';
        if ($d === '') {
            return false;
        }
        try {
            require_once INCLUDE_PATH . '/watemplates.php';
            // noteInbound() keys the window by the number as WhatsApp sent it
            // (with country code); a 10-digit local number is tried as +91 and
            // +977 because the booking may have been saved without the code.
            foreach (strlen($d) <= 10 ? ['91' . $d, '977' . $d, $d] : [$d] as $k) {
                if (WaTemplates::inSessionWindow($k)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e);
        }
        return false;
    }

    /**
     * WhatsApp the customer of a booking, only inside their open window.
     * Returns 'sent', 'suppressed_window' or 'failed'.
     */
    public static function notifyCustomer(int $bookingId, string $text, ?string $mediaUrl = null): string
    {
        try {
            $b = Database::fetch('SELECT id, contact_phone FROM bookings WHERE id = :id', ['id' => $bookingId]);
            $phone = (string) ($b['contact_phone'] ?? '');
            if ($b === null || !self::windowOpen($phone)) {
                return 'suppressed_window';
            }
            $res = Notify::whatsapp($phone, $text, $mediaUrl, null, [], $bookingId, ['purpose' => 'notice']);
            return $res === true ? 'sent' : 'failed';
        } catch (Throwable $e) {
            Logger::exception($e);
            return 'failed';
        }
    }
}
