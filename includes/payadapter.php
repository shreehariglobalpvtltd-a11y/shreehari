<?php
/**
 * =====================================================================
 *  PaymentAdapter — how an Online booking gets paid (15 Oct 2026,
 *  SmartBook v5 §7).
 *
 *  Today: ManualQr — the company's UPI QR / eSewa ID on the QR page
 *  (smartpay.php); the passenger sends the UTR or a screenshot and the
 *  office verifies it in Admin -> Payments (BookingService::approveReviewed).
 *
 *  Later: a gateway (ICICI today in UAT under /payment/, Razorpay /
 *  Cashfree / PhonePe PG or eSewa ePay tomorrow) is switched on in
 *  Settings `payment_gateway` WITHOUT touching the card or the QR page:
 *  the page asks PaymentAdapters::all() what to offer and the adapter
 *  returns either QR data or a redirect URL.
 *
 *  Verification stays with the office (owner rule: a bank success goes to
 *  the verify queue, nothing auto-confirms). A gateway callback lands in
 *  BookingService::submitPaymentProof() exactly like a typed UTR — it is
 *  idempotent per booking and the amount is compared by the risk score.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/qr.php';

interface PaymentAdapter
{
    /** 'manual_qr' | 'icici' | … */
    public function key(): string;

    /** Button text, en / hi / ne. @return array{en: string, hi: string, ne: string} */
    public function label(): array;

    /** Configured and allowed right now. */
    public function available(): bool;

    /**
     * What the QR page shows for this booking.
     *
     * @param array<string, mixed> $booking bookings row
     * @param float $due amount still owed (INR)
     * @return array<string, mixed> kind 'qr' {upi, esewa} | kind 'redirect' {url}
     */
    public function start(array $booking, float $due): array;
}

final class ManualQrAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'manual_qr';
    }

    public function label(): array
    {
        return ['en' => 'UPI / eSewa QR', 'hi' => 'UPI / eSewa QR', 'ne' => 'UPI / eSewa QR'];
    }

    public function available(): bool
    {
        return trim(Settings::getString('upi_id', '')) !== '' || trim(Settings::getString('esewa_id', '')) !== '';
    }

    public function start(array $booking, float $due): array
    {
        $pnr     = (string) $booking['pnr'];
        $upiId   = trim(Settings::getString('upi_id', ''));
        $upiName = trim(Settings::getString('upi_name', ''));
        $link    = $upiId !== '' ? upiLink($upiId, $upiName, $due, $pnr) : '';
        $npr     = round($due * Settings::getFloat('npr_per_inr', 1.6));
        $esewaQr = '';
        foreach (['esewa-qr.jpg', 'esewa-qr.png', 'assets/img/esewa-qr.jpg', 'assets/img/esewa-qr.png'] as $f) {
            if (is_file(ROOT_PATH . '/' . $f)) {
                $esewaQr = '/' . $f;
                break;
            }
        }
        return [
            'kind'  => 'qr',
            'upi'   => $upiId === '' ? null : [
                'id'   => $upiId,
                'name' => $upiName,
                'link' => $link,
                'qr'   => QrCode::dataUri($link, 7, 3),
            ],
            'esewa' => trim(Settings::getString('esewa_id', '')) === '' ? null : [
                'id'   => trim(Settings::getString('esewa_id', '')),
                'name' => trim(Settings::getString('esewa_name', '')),
                'npr'  => $npr,
                'qr'   => $esewaQr,
            ],
        ];
    }
}

/** ICICI PG (UAT, /payment/ on the server): the bank result goes to the verify queue. */
final class IciciAdapter implements PaymentAdapter
{
    public function key(): string
    {
        return 'icici';
    }

    public function label(): array
    {
        return ['en' => 'Card / Net banking', 'hi' => 'कार्ड / नेट बैंकिंग', 'ne' => 'कार्ड / नेट बैंकिङ'];
    }

    public function available(): bool
    {
        return in_array('icici', PaymentAdapters::enabledKeys(), true) && is_file(ROOT_PATH . '/payment/initiate_payment.php');
    }

    public function start(array $booking, float $due): array
    {
        return ['kind' => 'redirect', 'url' => '/payment/initiate_payment.php?pnr=' . rawurlencode((string) $booking['pnr'])];
    }
}

final class SmartPay
{
    /** A UTR or a screenshot is on the booking (the office's verify queue has it). */
    public static function hasProof(int $bookingId): bool
    {
        return Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND utr_number IS NOT NULL AND TRIM(utr_number) <> ''", ['b' => $bookingId])
            || Database::exists('SELECT 1 FROM payment_screenshots WHERE booking_id = :b', ['b' => $bookingId]);
    }

    /**
     * The QR page's poll: the booking's state for the browser that owns it.
     *
     * @return array{status: string, proof: bool, expiresIn: int}|null null = not yours
     */
    public static function status(string $pnr): ?array
    {
        $b = BookingService::findByPnr($pnr);
        if ($b === null || !Auth::customerMayOpen($b)) {
            return null;
        }
        $exp = strtotime((string) ($b['expires_at'] ?? ''));
        return [
            'status'    => (string) $b['status'],
            'proof'     => self::hasProof((int) $b['id']),
            'expiresIn' => $exp !== false ? max(0, $exp - time()) : 0,
        ];
    }
}

final class PaymentAdapters
{
    /** Settings `payment_gateway`: comma list of gateways switched on ('' = QR only). @return list<string> */
    public static function enabledKeys(): array
    {
        $raw = strtolower(Settings::getString('payment_gateway', ''));
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Every adapter available right now, QR first. @return list<PaymentAdapter> */
    public static function all(): array
    {
        $out = [];
        foreach ([new ManualQrAdapter(), new IciciAdapter()] as $a) {
            if ($a->available()) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /** What is still owed on a booking (INR): total minus verified payments. */
    public static function due(array $booking): float
    {
        $paid = (float) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
            ['b' => (int) $booking['id']]
        );
        return max(0.0, round((float) $booking['total_amount'] - $paid, 2));
    }
}
