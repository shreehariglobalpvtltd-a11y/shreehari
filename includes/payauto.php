<?php
/**
 * includes/payauto.php — PayAuto: automatic payment confirmation, ONLY from an
 * authenticated payment provider (19 Nov 2026).
 *
 * Owner rule (19 Nov, final): "Auto-confirm only on authenticated provider/bank success
 * matching booking, amount, currency and transaction; screenshot or UTR alone stays pending."
 *
 * Nothing here ever looks at a screenshot, a typed UTR or a WhatsApp message. The only
 * caller is a provider bridge AFTER it verified the provider's signature (today the ICICI
 * PG bridge: payment/icici_booking.php checks the secure hash + merchant id, and the
 * booking id is bound into the provider's transaction number). PayAuto re-checks:
 *
 *   1. pay_provider_autoconfirm_on = 1 and the provider runs in PRODUCTION (never UAT/test money).
 *   2. The booking is still pending (confirmed = no-op, so duplicate callbacks are safe).
 *   3. Currency: the provider charged INR (the booking's online amount is INR).
 *   4. Amount: provider amount == bookings.total_amount to the paisa.
 *   5. Transaction: the provider reference is this booking's payment proof and on NO other booking.
 * Then BookingService::approveReviewed() — the same gate a person uses — confirms it; the
 * idempotent confirm() (row lock) gives one ticket per booking. Anything else: the proof
 * stays in Verify Payments for a person, exactly as before.
 */
declare(strict_types=1);

final class PayAuto
{
    public static function on(): bool
    {
        return Settings::getBool('pay_provider_autoconfirm_on', true);
    }

    /**
     * @param string $provider   'icici' (the only authenticated provider today)
     * @param bool   $production the provider ran in production, not UAT / sandbox
     * @param string $proofRef   the payments.utr_number the bridge stored for this transaction
     * @return array{ok:bool, why:string}
     */
    public static function confirmFromProvider(int $bookingId, string $provider, bool $production, string $txnId,
                                               string $proofRef, float $amount, string $currency): array
    {
        $no = static fn(string $why): array => ['ok' => false, 'why' => $why];
        if (!self::on()) {
            return $no('off');
        }
        if (!$production) {
            return $no('provider not in production');
        }
        if ($provider !== 'icici' || $txnId === '' || $proofRef === '') {
            return $no('unknown provider or transaction');
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($b === null) {
            return $no('no booking');
        }
        if ((string) $b['status'] === 'confirmed') {
            return $no('already confirmed');
        }
        if ((string) $b['status'] !== 'pending') {
            return $no('not pending');
        }
        if (strtoupper($currency) !== 'INR') {
            return $no('currency');
        }
        $total = round((float) ($b['total_amount'] ?? 0), 2);
        if ($total <= 0 || abs(round($amount, 2) - $total) > 0.009) {
            return $no('amount mismatch');
        }
        $mine = Database::fetch('SELECT id FROM payments WHERE booking_id = :b AND utr_number = :u ORDER BY id DESC LIMIT 1',
            ['b' => $bookingId, 'u' => $proofRef]);
        if ($mine === null) {
            return $no('transaction not recorded on this booking');
        }
        $elsewhere = (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE utr_number = :u AND booking_id <> :b',
            ['u' => $proofRef, 'b' => $bookingId], 0);
        if ($elsewhere > 0) {
            return $no('transaction on another booking');
        }

        $admin = self::systemAdmin();
        if ($admin === null) {
            return $no('no system admin');
        }
        if (!class_exists('BookingService')) {
            require_once INCLUDE_PATH . '/booking.php';
        }
        try {
            $r = BookingService::approveReviewed($bookingId, $admin, [
                'via' => 'gateway', 'bankChecked' => true, 'amountSeen' => $amount,
                'note' => 'Auto-confirm: ' . strtoupper($provider) . ' signed success, txn ' . mb_substr($txnId, -12) . ', ' . inr($amount) . ' INR matched',
            ]);
        } catch (Throwable $e) {
            Logger::warning('PayAuto: approve refused', ['pnr' => $b['pnr'] ?? '', 'err' => $e->getMessage()], 'payment');
            return $no('gate: ' . mb_substr($e->getMessage(), 0, 80));
        }
        if (($r['state'] ?? '') !== 'confirmed') {
            return $no('needs a person (' . (string) ($r['state'] ?? '?') . ')');
        }
        Logger::audit('payment.auto_confirmed', 'booking', (string) $b['pnr'], ['status' => 'pending'],
            ['status' => 'confirmed', 'provider' => $provider, 'txn' => mb_substr($txnId, -12), 'amount' => $amount, 'currency' => 'INR'],
            'Confirmed automatically from a signed ' . strtoupper($provider) . ' success');
        try {
            Database::insert('notifications', [
                'audience' => 'admin', 'booking_id' => $bookingId, 'icon' => '🏦',
                'title' => Security::clean('Auto-confirmed (bank) · ' . (string) $b['pnr'], 191),
                'body' => Security::clean(strtoupper($provider) . ' signed success ' . inr($amount) . ' (txn …' . substr($txnId, -6) . '). Ticket sent.', 500),
            ]);
        } catch (Throwable $e) {
            Logger::warning('PayAuto bell failed', ['err' => $e->getMessage()], 'payment');
        }
        return ['ok' => true, 'why' => 'confirmed'];
    }

    /**
     * 25 Nov 2026 (mp1 "pay"): the second authenticated provider path - a BANK CREDIT from the
     * shared ledger (includes/paycredits.php), kept apart from confirmFromProvider() so the ICICI
     * path above stays exactly as it was. The caller (PayCredits::tryAutoConfirm) has already
     * checked the source was authenticated (Gmail: DKIM pass from the bank's domain + allowed
     * sender; bank API: HMAC-signed webhook in live mode), that the reference names exactly one
     * pending booking and that the amount equals what is due. This re-checks what it can see:
     *
     *   1. the source's own switch is on (gmail: bankmail_on + pay_bankmail_autoconfirm_on;
     *      bankapi: bankapi_on + bankapi_mode = live + pay_provider_autoconfirm_on);
     *   2. the booking is still pending and not pay-at-boarding;
     *   3. $dueInr equals total - verified payments to the paisa;
     *   4. the proof is this booking's and on NO other booking.
     * Then the same approveReviewed() a person uses (via 'gateway', bank checked).
     *
     * @param string $source   'gmail' | 'bankapi'
     * @param string $proofRef the payments.utr_number recorded for this credit (GIME-… / UPIAPI-…)
     * @return array{ok:bool, why:string}
     */
    public static function confirmFromBankCredit(int $bookingId, string $source, string $txnId, string $proofRef,
                                                 float $dueInr, string $currency, float $creditAmount): array
    {
        $no = static fn(string $why): array => ['ok' => false, 'why' => $why];
        if ($source === 'gmail') {
            if (!Settings::getBool('bankmail_on', false) || !Settings::getBool('pay_bankmail_autoconfirm_on', false)) {
                return $no('off');
            }
        } elseif ($source === 'bankapi') {
            if (!self::on() || !Settings::getBool('bankapi_on', false) || Settings::getString('bankapi_mode', 'sandbox') !== 'live') {
                return $no('off or sandbox');
            }
        } else {
            return $no('unknown provider');
        }
        if ($txnId === '' || $proofRef === '') {
            return $no('no transaction');
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($b === null) {
            return $no('no booking');
        }
        if ((string) $b['status'] !== 'pending') {
            return $no((string) $b['status'] === 'confirmed' ? 'already confirmed' : 'not pending');
        }
        if ((int) ($b['is_cod'] ?? 0) === 1) {
            return $no('pay at boarding');
        }
        $paid = (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => $bookingId], 0);
        $due  = round(max(0.0, (float) ($b['total_amount'] ?? 0) - $paid), 2);
        if ($due <= 0 || abs(round($dueInr, 2) - $due) > 0.009) {
            return $no('amount mismatch');
        }
        $mine = Database::fetch('SELECT id FROM payments WHERE booking_id = :b AND utr_number = :u ORDER BY id DESC LIMIT 1',
            ['b' => $bookingId, 'u' => $proofRef]);
        if ($mine === null) {
            return $no('transaction not recorded on this booking');
        }
        $elsewhere = (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE (utr_number = :u OR utr_number = :t) AND booking_id <> :b',
            ['u' => $proofRef, 't' => $txnId, 'b' => $bookingId], 0);
        if ($elsewhere > 0) {
            return $no('transaction on another booking');
        }
        $admin = self::systemAdmin();
        if ($admin === null) {
            return $no('no system admin');
        }
        if (!class_exists('BookingService')) {
            require_once INCLUDE_PATH . '/booking.php';
        }
        $label = $source === 'gmail' ? 'GMAIL (Global IME credit alert, DKIM pass)' : 'BANK API (signed webhook)';
        $shown = strtoupper($currency) === 'NPR' ? 'NPR ' . number_format($creditAmount, 2) : inr($creditAmount);
        try {
            $r = BookingService::approveReviewed($bookingId, $admin, [
                'via' => 'gateway', 'bankChecked' => true, 'amountSeen' => $due,
                'note' => mb_substr('Auto-confirm: ' . $label . ', txn ' . mb_substr($txnId, -14) . ', ' . $shown . ' matched', 0, 255),
            ]);
        } catch (Throwable $e) {
            Logger::warning('PayAuto: bank credit approve refused', ['pnr' => $b['pnr'] ?? '', 'err' => $e->getMessage()], 'payment');
            return $no('gate: ' . mb_substr($e->getMessage(), 0, 80));
        }
        if (($r['state'] ?? '') !== 'confirmed') {
            return $no('needs a person (' . (string) ($r['state'] ?? '?') . ')');
        }
        Logger::audit('payment.auto_confirmed', 'booking', (string) $b['pnr'], ['status' => 'pending'],
            ['status' => 'confirmed', 'provider' => $source, 'txn' => mb_substr($txnId, -14), 'amount' => $creditAmount, 'currency' => strtoupper($currency)],
            'Confirmed automatically from an authenticated bank credit (' . $source . ')');
        try {
            Database::insert('notifications', [
                'audience' => 'admin', 'booking_id' => $bookingId, 'icon' => '🏦',
                'title' => Security::clean('Auto-confirmed (' . ($source === 'gmail' ? 'Gmail bank alert' : 'bank API') . ') · ' . (string) $b['pnr'], 191),
                'body' => Security::clean($shown . ' credited (txn …' . substr($txnId, -6) . '). Ticket confirmed and sent.', 500),
            ]);
        } catch (Throwable $e) {
            Logger::warning('PayAuto bell failed', ['err' => $e->getMessage()], 'payment');
        }
        return ['ok' => true, 'why' => 'confirmed'];
    }

    /** @return array{id:int, role:string}|null the account the automatic approval is recorded under */
    public static function systemAdmin(): ?array
    {
        $id = Settings::getInt('pay_auto_admin_id', 0);
        $row = $id > 0
            ? Database::fetch('SELECT id, role FROM admins WHERE id = :i AND is_active = 1', ['i' => $id])
            : Database::fetch("SELECT id, role FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
        return $row === null ? null : ['id' => (int) $row['id'], 'role' => (string) $row['role']];
    }
}
