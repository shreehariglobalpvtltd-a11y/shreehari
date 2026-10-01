<?php
/**
 * =====================================================================
 *  includes/paywebhook.php — signed payment webhooks and the
 *  "possible match" hint (26 Sep 2026, Prompt 2 sections 3–5).
 *
 *  THE RULE: money is never confirmed from a screenshot, a typed UTR, OCR,
 *  a bank SMS or a phone notification. Only a webhook whose HMAC signature
 *  checks out may confirm a booking by itself, and only when the office
 *  has switched that on (payment_webhook_autoconfirm) and named the admin
 *  account the confirmation is recorded against. Everything short of that
 *  becomes payments.match_hint = 'possible_match': a yellow badge for the
 *  office, while the booking stays pending until a person verifies it.
 *
 *  Idempotency: webhooks_received has UNIQUE (provider, event_id). A
 *  retried delivery inserts nothing and is answered "duplicate", so it can
 *  never credit a booking twice. BookingService::confirm() is itself
 *  idempotent under a row lock as a second guard.
 *
 *  Stored per event: hashes of the body and the UTR, the amount and the
 *  provider's status. Never the body, never a card or account number.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/notifier.php';

final class PayWebhook
{
    public static function enabled(): bool
    {
        return Settings::getBool('payment_webhook_on', false);
    }

    /** Env var first (never in the repo or the settings screen), then settings. */
    public static function secret(): string
    {
        $env = getenv('SHG_PAYMENT_WEBHOOK_SECRET');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        return trim(Settings::getString('payment_webhook_secret', ''));
    }

    /**
     * HMAC-SHA256 of the raw body, hex, optionally prefixed "sha256=".
     * Fails closed: no secret configured means nothing verifies.
     */
    public static function verifySignature(string $rawBody, string $header, ?string $secret = null): bool
    {
        $secret = $secret ?? self::secret();
        $sig    = strtolower(trim($header));
        if ($secret === '' || $sig === '') {
            return false;
        }
        if (str_starts_with($sig, 'sha256=')) {
            $sig = substr($sig, 7);
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    public static function utrHash(string $utr): string
    {
        return hash('sha256', strtoupper(trim($utr)));
    }

    /**
     * Normalise a provider payload to the fields we use. Accepts the common
     * spellings so a new gateway rarely needs code: event_id|id,
     * utr|utr_number|bank_ref|rrn, amount, status, reference|pnr|order_id.
     *
     * @return array{event_id: string, utr: string, amount: float, status: string, reference: string}
     */
    public static function normalise(array $p): array
    {
        $pick = static function (array $keys) use ($p): string {
            foreach ($keys as $k) {
                if (isset($p[$k]) && is_scalar($p[$k]) && trim((string) $p[$k]) !== '') {
                    return trim((string) $p[$k]);
                }
            }
            return '';
        };
        $status = strtolower($pick(['status', 'txn_status', 'payment_status']));
        if (in_array($status, ['success', 'successful', 'succeeded', 'paid', 'captured', 'completed', 'credit'], true)) {
            $status = 'success';
        }
        return [
            'event_id'  => mb_substr($pick(['event_id', 'id', 'webhook_id', 'txn_id', 'transaction_id']), 0, 191),
            'utr'       => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $pick(['utr', 'utr_number', 'bank_ref', 'rrn'])) ?? '', 0, 60),
            'amount'    => round((float) $pick(['amount', 'amount_inr', 'amount_npr']), 2),
            'status'    => mb_substr($status, 0, 20),
            'reference' => strtoupper(mb_substr($pick(['reference', 'pnr', 'order_id', 'booking_ref']), 0, 40)),
        ];
    }

    /**
     * Record and act on one verified event. The caller has already checked
     * the signature. Returns the outcome: duplicate | ignored | unmatched |
     * possible_match | confirmed | invalid.
     *
     * @return array{outcome: string, bookingId: ?int}
     */
    public static function process(string $provider, array $payload, string $rawBody): array
    {
        $e = self::normalise($payload);
        if ($e['event_id'] === '') {
            return ['outcome' => 'invalid', 'bookingId' => null];
        }

        // insertIgnore returns the new id, or 0 when (provider, event_id) exists.
        $rowId = Database::insertIgnore('webhooks_received', [
            'provider'     => mb_substr($provider, 0, 50),
            'event_id'     => $e['event_id'],
            'payload_hash' => hash('sha256', $rawBody),
            'utr_hash'     => $e['utr'] !== '' ? self::utrHash($e['utr']) : null,
            'amount'       => $e['amount'],
            'pay_status'   => $e['status'],
        ]);
        if ($rowId < 1) {
            return ['outcome' => 'duplicate', 'bookingId' => null];
        }

        $result = self::decide($provider, $e);

        Database::update('webhooks_received', [
            'booking_id'   => $result['bookingId'],
            'outcome'      => $result['outcome'],
            'processed_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $rowId]);

        return $result;
    }

    /** @return array{outcome: string, bookingId: ?int} */
    private static function decide(string $provider, array $e): array
    {
        if ($e['status'] !== 'success') {
            return ['outcome' => 'ignored', 'bookingId' => null];
        }

        $match = self::findBooking($e['reference'], $e['utr']);
        if ($match === null) {
            Notifier::notifyAdmin('payment_unmatched', null,
                '💳 Payment received with no matching booking', [
                    'Source' => $provider,
                    'Amount' => inr($e['amount']),
                    'UTR'    => $e['utr'] !== '' ? '…' . substr($e['utr'], -4) : '—',
                    'Action' => 'It will be matched when the customer sends this UTR.',
                ], $provider . ':' . $e['event_id']);
            return ['outcome' => 'unmatched', 'bookingId' => null];
        }

        $bid = (int) $match['id'];
        if ((string) $match['status'] !== 'pending') {
            return ['outcome' => 'ignored', 'bookingId' => $bid];
        }

        $total    = (float) $match['total_amount'];
        $full     = $e['amount'] + 0.005 >= $total;
        $adminId  = self::autoConfirmAdminId();
        $isCod    = (int) ($match['is_cod'] ?? 0) === 1;

        if ($full && !$isCod && $adminId !== null) {
            try {
                BookingService::confirm($bid, $adminId,
                    'Auto-confirmed: signed ' . $provider . ' webhook, ' . inr($e['amount']));
                Notifier::notifyAdmin('payment_confirmed_webhook', $bid,
                    '✅ Booking confirmed by gateway webhook', [
                        'PNR' => (string) $match['pnr'], 'Amount' => inr($e['amount']), 'Source' => $provider,
                    ]);
                return ['outcome' => 'confirmed', 'bookingId' => $bid];
            } catch (Throwable $ex) {
                // A seat resold after rejection, a state race: fall back to the hint.
                Logger::warning('webhook auto-confirm refused: ' . $ex->getMessage(), ['pnr' => $match['pnr']]);
            }
        }

        self::markPossibleMatch($bid, $provider . ' webhook: ' . inr($e['amount'])
            . ($full ? ' (full fare)' : ' of ' . inr($total) . ' (short)') . ' at ' . date('j M H:i'));
        return ['outcome' => 'possible_match', 'bookingId' => $bid];
    }

    /**
     * The customer typed a UTR (BookingService::submitPaymentProof). If a
     * signed webhook already reported that UTR and nobody claimed it, the
     * office gets the hint. Never confirms. Returns true when a hint was set.
     */
    public static function onProof(int $bookingId, string $utr): bool
    {
        if (!self::enabled() || trim($utr) === '') {
            return false;
        }
        try {
            $row = Database::fetch(
                "SELECT id, provider, amount FROM webhooks_received
                  WHERE utr_hash = :h AND outcome = 'unmatched' AND pay_status = 'success'
                  ORDER BY id DESC LIMIT 1",
                ['h' => self::utrHash($utr)]
            );
            if ($row === null) {
                return false;
            }
            Database::update('webhooks_received', ['booking_id' => $bookingId, 'outcome' => 'possible_match'],
                'id = :id', ['id' => (int) $row['id']]);
            self::markPossibleMatch($bookingId, $row['provider'] . ' webhook: ' . inr((float) $row['amount'])
                . ' with the same UTR, received earlier');
            return true;
        } catch (Throwable $e) {
            Logger::exception($e);
            return false;
        }
    }

    public static function markPossibleMatch(int $bookingId, string $note): void
    {
        $pid = (int) Database::scalar('SELECT MAX(id) FROM payments WHERE booking_id = :b', ['b' => $bookingId], 0);
        if ($pid > 0) {
            Database::update('payments', [
                'match_hint' => 'possible_match',
                'match_note' => mb_substr($note, 0, 255),
            ], 'id = :id', ['id' => $pid]);
        }
        $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bookingId], '');
        Logger::audit('payment.possible_match', 'booking', $pnr, null, ['match_hint' => 'possible_match'], $note);
        Notifier::notifyAdmin('possible_match_detected', $bookingId,
            '🟡 Possible payment match — please verify', ['PNR' => $pnr, 'Detail' => $note]);
    }

    /**
     * The admin account a webhook confirmation is recorded against, or null
     * when auto-confirm is off or that account is missing / inactive.
     */
    public static function autoConfirmAdminId(): ?int
    {
        if (!Settings::getBool('payment_webhook_autoconfirm', false)) {
            return null;
        }
        $id = (int) Settings::getString('payment_webhook_admin_id', '');
        if ($id <= 0) {
            return null;
        }
        $ok = Database::exists("SELECT 1 FROM admins WHERE id = :id AND is_active = 1 AND role <> 'agent'", ['id' => $id]);
        return $ok ? $id : null;
    }

    /**
     * The booking a payment belongs to: by PNR reference first, else by the
     * UTR on its latest payment row. Two different bookings with the same
     * UTR is ambiguous and matches nothing (the office decides).
     */
    private static function findBooking(string $reference, string $utr): ?array
    {
        if ($reference !== '' && Security::isValidPnr($reference)) {
            $b = Database::fetch('SELECT id, pnr, status, total_amount, is_cod FROM bookings WHERE pnr = :p', ['p' => $reference]);
            if ($b !== null) {
                return $b;
            }
        }
        if ($utr === '') {
            return null;
        }
        $rows = Database::fetchAll(
            "SELECT DISTINCT b.id, b.pnr, b.status, b.total_amount, b.is_cod
               FROM payments p JOIN bookings b ON b.id = p.booking_id
              WHERE p.utr_number IS NOT NULL
                AND UPPER(REPLACE(REPLACE(TRIM(p.utr_number), ' ', ''), '-', '')) = :u
              LIMIT 2",
            ['u' => strtoupper($utr)]
        );
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Housekeeping for cron: events older than 90 days. */
    public static function prune(): int
    {
        return Database::run('DELETE FROM webhooks_received WHERE received_at < NOW() - INTERVAL 90 DAY');
    }
}
