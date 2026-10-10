<?php
/**
 * WhatsApp delivery proof and zero-API-cost manual forward helpers.
 * "sent" in message_logs means provider ACCEPTED, never "delivered".
 * Signed ticket URL is sent as prefilled text, not a binary attachment.
 */
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/ticket.php';

final class WaDelivery
{
    /**
     * Called only by authenticated manifest/office pages. A schedule may have
     * several passengers on one booking; fetch per-booking WhatsApp state once.
     * This is read-only and gracefully handles uninstalled receipt migrations.
     */
    public static function decorate(array $rows): array
    {
        if ($rows === []) { return $rows; }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows
        ), static fn(int $id): bool => $id > 0)));
        if ($ids === []) { return $rows; }
        $idSql = implode(',', $ids); // numeric IDs from authenticated DB rows only
        $proofs = [];
        try {
            $hasReceipts = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_delivery_receipts'",
                [], 0
            ) > 0;
            $hasPurpose = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'purpose'",
                [], 0
            ) > 0;
            $purpose = $hasPurpose ? " AND (purpose IS NULL OR purpose = 'ticket')" : '';
            $joins = $hasReceipts
                ? " LEFT JOIN wa_delivery_receipts rec ON rec.message_log_id = m.id"
                : '';
            $fields = $hasReceipts
                ? ", rec.delivery_state, rec.delivered_at, rec.read_at, rec.failed_at"
                : ", NULL AS delivery_state, NULL AS delivered_at, NULL AS read_at, NULL AS failed_at";
            $sql = "SELECT m.booking_id, m.provider, m.status, m.created_at AS wa_attempted_at,
                           m.error" . $fields . "
                      FROM message_logs m" . $joins . "
                      JOIN (
                         SELECT booking_id, MAX(id) AS latest_id FROM message_logs
                          WHERE channel='whatsapp' AND booking_id IN (" . $idSql . ")" . $purpose . "
                          GROUP BY booking_id
                      ) last ON last.latest_id=m.id";
            foreach (Database::fetchAll($sql) as $found) {
                $proofs[(int) $found['booking_id']] = $found;
            }
        } catch (Throwable $e) {
            // Never break the passenger manifest if a migration is pending.
            Logger::warning('WhatsApp delivery proof lookup unavailable', ['error' => $e->getMessage()], 'whatsapp');
        }

        foreach ($rows as &$r) {
            $proof = $proofs[(int) ($r['booking_id'] ?? 0)] ?? null;
            $r['wa_provider'] = $proof !== null ? (string) ($proof['provider'] ?? '') : '';
            $r['wa_attempted_at'] = $proof['wa_attempted_at'] ?? null;
            $r['wa_delivered_at'] = $proof['delivered_at'] ?? null;
            $r['wa_read_at'] = $proof['read_at'] ?? null;
            $r['wa_failed_at'] = $proof['failed_at'] ?? null;
            $r['wa_delivery_status'] = self::status($proof);
        }
        unset($r);
        return $rows;
    }

    public static function status(?array $log): string
    {
        if ($log === null) { return 'NOT SENT / NO LOG'; }
        $receipt = strtolower((string) ($log['delivery_state'] ?? ''));
        if ($receipt === 'read') { return 'READ'; }
        if ($receipt === 'delivered') { return 'DELIVERED'; }
        if ($receipt === 'failed' || ($log['status'] ?? '') === 'failed') { return 'FAILED'; }
        if (($log['status'] ?? '') === 'skipped') { return 'SKIPPED'; }
        if (($log['status'] ?? '') === 'queued') { return 'QUEUED'; }
        if (($log['status'] ?? '') === 'sent') { return 'ACCEPTED (UNVERIFIED)'; }
        return 'UNKNOWN';
    }

    /** Mobile number from an explicit +91/+977 country code; never guess NP vs IN. */
    public static function mobile(string $raw, string $country = ''): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) { $digits = substr($digits, 2); }
        if (preg_match('/^(?:91[6-9][0-9]{9}|9779[0-9]{9})$/D', $digits)) { return $digits; }
        if (preg_match('/^[6-9][0-9]{9}$/D', $digits) &&
            in_array($country, ['91', '977'], true)) { return $country . $digits; }
        return '';
    }

    public static function forwardLink(array $row, string $rawPhone, string $country): string
    {
        if (($row['booking_status'] ?? '') !== 'confirmed') { return ''; }
        $pnr = trim((string) ($row['pnr'] ?? ''));
        $phone = self::mobile($rawPhone, $country);
        if ($pnr === '' || $phone === '') { return ''; }
        $booked = (string) ($row['created_at'] ?? '');
        $msg = "S Hari Global Pvt Ltd — Ticket\n"
            . "PNR: " . $pnr . "\n"
            . ($booked !== '' ? "Booked: " . $booked . "\n" : '')
            . "Ticket PNG (open/download): " . Ticket::imageUrl($pnr) . "\n"
            . "कृपया टिकट खोल्नुहोस्।";
        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    }

    /** Consistent printable short form for all three chalani formats. */
    public static function brief(array $row): string
    {
        $booked = (string) ($row['created_at'] ?? '');
        $when = ($booked !== '' && strtotime($booked) !== false)
            ? date('d M H:i', (int) strtotime($booked)) : '-';
        $s = (string) ($row['wa_delivery_status'] ?? 'NOT SENT / NO LOG');
        $s = match ($s) {
            'DELIVERED' => 'WA Delivered',
            'READ' => 'WA Read',
            'FAILED' => 'WA Failed',
            'ACCEPTED (UNVERIFIED)' => 'WA Accepted?',
            'SKIPPED' => 'WA Skipped',
            default => 'WA Unverified',
        };
        return $when . ' | ' . $s;
    }
}
