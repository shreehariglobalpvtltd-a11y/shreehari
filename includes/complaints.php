<?php
/**
 * =====================================================================
 *  Complaints — the Help bot's "Raise complaint" desk (23 Sep 2026,
 *  UI/UX v3 brief §8).
 *
 *  A complaint is a small, self-contained record: who, which booking,
 *  what category, what happened. Filing it does three things, in order:
 *    1. writes the `complaints` row and mints a ticket number the
 *       passenger can quote (SHG-C-yymmdd-XXXX);
 *    2. tells the office on WhatsApp through Notify::whatsapp() - the
 *       configured driver (Meta Cloud API / Gupshup / Twilio) when one is
 *       set, otherwise a wa.me link the passenger is handed to send it
 *       themselves;
 *    3. returns the ticket + link so the bot can confirm on screen.
 *
 *  Behind `complaints_on` (default OFF). While it is off api/complaint.php
 *  answers 503 and the bot files through api/enquiry.php as it always did,
 *  so switching the feature on and deploying it are two separate steps.
 *  Nothing here touches bookings, seats or payments.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/notify.php';

final class Complaints
{
    public const CATEGORIES = ['late', 'staff', 'luggage', 'seat', 'refund', 'border', 'ac', 'other'];
    public const STATUSES   = ['open', 'in_progress', 'resolved'];
    public const LANGS      = ['en', 'hi', 'ne', 'gu'];

    private const LABELS = [
        'late' => 'Late bus', 'staff' => 'Staff behaviour', 'luggage' => 'Luggage', 'seat' => 'Seat issue',
        'refund' => 'Refund delay', 'border' => 'Border issue', 'ac' => 'AC not working', 'other' => 'Other',
    ];

    public static function enabled(): bool
    {
        return Settings::getBool('complaints_on', false);
    }

    /** The office WhatsApp that receives new complaints, digits with country code. */
    public static function number(): string
    {
        $n = preg_replace('/\D+/', '', Settings::getString('complaint_whatsapp', '')) ?? '';
        if ($n === '') {
            $n = preg_replace('/\D+/', '', Settings::getString('admin_whatsapp', Settings::officePhone())) ?? '';
        }
        return $n;
    }

    public static function categoryLabel(string $cat): string
    {
        return self::LABELS[$cat] ?? ucfirst($cat);
    }

    /** A ticket number nobody else holds. */
    public static function ticketNo(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 8; $try++) {
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $no = 'SHG-C-' . date('ymd') . '-' . $suffix;
            if (!Database::exists('SELECT 1 FROM complaints WHERE ticket_no = :t', ['t' => $no])) {
                return $no;
            }
        }
        return 'SHG-C-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    /**
     * Validate + store + notify. Returns
     *   ['id', 'ticketId', 'waSent' => bool, 'waLink' => string|null, 'message' => string]
     * Throws InvalidArgumentException with a field name for a bad input.
     */
    public static function file(array $in, string $ip = '', string $ua = ''): array
    {
        $name  = Security::clean((string) ($in['name'] ?? ''), 120);
        $phone = Security::digits((string) ($in['phone'] ?? ''), 20);
        $pnr   = strtoupper(Security::clean((string) ($in['pnr'] ?? ''), 40));
        $cat   = strtolower(Security::clean((string) ($in['category'] ?? 'other'), 20));
        $msg   = trim(Security::clean((string) ($in['message'] ?? ''), 1000));
        $lang  = strtolower(substr((string) ($in['lang'] ?? 'en'), 0, 2));

        if ($name === '') { $name = 'Passenger'; }
        if ($phone === '' || !Security::isValidPhone($phone)) {
            throw new InvalidArgumentException('phone');
        }
        if ($msg === '') {
            throw new InvalidArgumentException('message');
        }
        if (!in_array($cat, self::CATEGORIES, true)) { $cat = 'other'; }
        if (!in_array($lang, self::LANGS, true)) { $lang = 'en'; }
        if ($pnr !== '' && !Security::isValidPnr($pnr)) { $pnr = ''; }

        $ticket = self::ticketNo();
        $text   = self::officeText($ticket, $name, $phone, $pnr, $cat, $msg);
        $to     = self::number();

        $waSent = false;
        $waLink = null;
        if ($to !== '') {
            try {
                $res = Notify::whatsapp($to, $text, null, 'IN', [], null, ['purpose' => 'complaint']);
                if ($res === true) {
                    $waSent = true;
                } elseif (is_string($res) && $res !== '') {
                    $waLink = $res;
                }
            } catch (Throwable $e) {
                Logger::warning('Complaint WhatsApp failed', ['ticket' => $ticket, 'err' => $e->getMessage()]);
            }
            if (!$waSent && $waLink === null) {
                $waLink = 'https://wa.me/' . $to . '?text=' . rawurlencode($text);
            }
        }

        $id = Database::insert('complaints', [
            'ticket_no'  => $ticket,
            'name'       => $name,
            'phone'      => $phone,
            'pnr'        => $pnr !== '' ? $pnr : null,
            'category'   => $cat,
            'message'    => $msg,
            'lang'       => $lang,
            'status'     => 'open',
            'wa_sent'    => $waSent ? 1 : 0,
            'wa_link'    => $waLink !== null ? substr($waLink, 0, 600) : null,
            'ip'         => $ip !== '' ? $ip : null,
            'user_agent' => $ua !== '' ? substr($ua, 0, 255) : null,
        ]);
        Logger::info('Complaint filed', ['ticket' => $ticket, 'cat' => $cat, 'wa' => $waSent ? 'api' : 'link']);

        return ['id' => $id, 'ticketId' => $ticket, 'waSent' => $waSent, 'waLink' => $waLink, 'message' => $text];
    }

    /** The WhatsApp the office receives - plain, scannable, one screen. */
    public static function officeText(string $ticket, string $name, string $phone, string $pnr, string $cat, string $msg): string
    {
        return "🛎️ *New complaint* {$ticket}\n"
            . "👤 {$name} · +{$phone}\n"
            . ($pnr !== '' ? "🎫 Booking {$pnr}\n" : '')
            . '📌 ' . self::categoryLabel($cat) . "\n"
            . "💬 " . mb_substr($msg, 0, 600) . "\n"
            . '🕒 ' . date('d M Y, H:i');
    }

    /** Office board action. */
    public static function setStatus(int $id, string $status, ?string $note, ?int $adminId): bool
    {
        if (!in_array($status, self::STATUSES, true)) { return false; }
        $row = Database::fetch('SELECT id, status, ticket_no FROM complaints WHERE id = :id', ['id' => $id]);
        if ($row === null) { return false; }
        $data = ['status' => $status, 'handled_by' => $adminId];
        if ($note !== null) { $data['admin_note'] = Security::clean($note, 500); }
        $data['resolved_at'] = $status === 'resolved' ? date('Y-m-d H:i:s') : null;
        Database::update('complaints', $data, 'id = :id', ['id' => $id]);
        if ((string) $row['status'] !== $status) {
            Logger::audit('complaint.status', 'complaint', (string) $row['ticket_no'], ['status' => $row['status']], ['status' => $status], 'complaint status changed');
        }
        return true;
    }
}
