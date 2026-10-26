<?php
/**
 * =====================================================================
 *  ICICI PG <-> booking bridge (6 Oct 2026)
 *
 *  Joins the ICICI files to the real booking system WITHOUT a schema
 *  change and WITHOUT touching includes/booking.php:
 *
 *   - The amount always comes from bookings.total_amount, never from
 *     the request, so nobody can pay Rs 1 for a Rs 2000 seat.
 *   - merchantTxnNo carries the booking id (SHG<id>T<YmdHis><rnd>), so
 *     the bank's return/webhook finds the booking with no session (the
 *     site cookie is SameSite=Lax and is NOT sent on the bank's
 *     cross-site POST back) and no new table.
 *   - A bank-signed success becomes payment proof through the normal
 *     BookingService::submitPaymentProof() path: the booking stops
 *     expiring, lands in the admin verify queue and the admin is
 *     alerted. A person still presses Confirm — nothing is auto-
 *     confirmed (owner settles money by hand), and in UAT the row is
 *     marked TEST so test money is never mistaken for real money.
 *   - Money that arrives for a booking that can no longer take it
 *     (cancelled / expired / amount mismatch) raises an admin alert so
 *     the owner can refund by hand.
 * =====================================================================
 */

declare(strict_types=1);

// Library file — never a page of its own.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/payevents.php';
require_once __DIR__ . '/icici_config.php';

/** Bank codes that mean the money was taken. */
const ICICI_SUCCESS_CODES = ['0', '00', '000', '0000', 'P1000'];
/** Bank codes that mean "still in progress". */
const ICICI_PENDING_CODES = ['P0030', 'U1000', 'R1001'];

function icici_is_uat(): bool
{
    return ICICI_ENVIRONMENT !== 'production';
}

/** SHG<bookingId>T<YmdHis><3 digits> — at most ~30 chars, alphanumeric. */
function icici_txn_for_booking(int $bookingId): string
{
    return 'SHG' . $bookingId . 'T' . date('YmdHis') . random_int(100, 999);
}

function icici_booking_id_from_txn(string $txn): ?int
{
    return preg_match('/^SHG(\d{1,12})T\d{17}$/', $txn, $m) ? (int) $m[1] : null;
}

/** Booking + its latest payment row, by PNR or id. */
function icici_load_booking(?string $pnr = null, ?int $id = null): ?array
{
    $b = $pnr !== null
        ? Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr])
        : Database::fetch('SELECT * FROM bookings WHERE id = :i LIMIT 1', ['i' => (int) $id]);
    if ($b === null) {
        return null;
    }
    $b['_payment'] = Database::fetch(
        'SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
        ['b' => $b['id']]
    );
    return $b;
}

/** Amount the bank must charge, as ICICI's 2-decimal string. */
function icici_amount_for(array $booking): string
{
    return number_format((float) ($booking['total_amount'] ?? 0), 2, '.', '');
}

/** Why this booking cannot be paid online right now ('' = it can). */
function icici_cannot_pay_reason(array $booking): string
{
    if ((string) $booking['status'] !== 'pending') {
        return (string) $booking['status'] === 'confirmed'
            ? 'यो टिकटको भुक्तानी पहिले नै पुष्टि भइसक्यो। / This booking is already confirmed.'
            : 'यो बुकिङ अब सक्रिय छैन। / This booking is no longer active.';
    }
    if ((int) ($booking['is_cod'] ?? 0) === 1) {
        return 'यो बुकिङ नगद (COD) हो। / This is a cash-on-delivery booking.';
    }
    $p = $booking['_payment'] ?? null;
    if ($p === null) {
        return 'No payment record for this booking.';
    }
    if (in_array((string) $p['status'], ['verified', 'settled'], true)) {
        return 'यो टिकटको भुक्तानी पहिले नै पुष्टि भइसक्यो। / Already paid.';
    }
    if (str_starts_with((string) ($p['utr_number'] ?? ''), 'ICICI-')) {
        return 'यो बुकिङको अनलाइन भुक्तानी प्राप्त भइसक्यो, जाँच हुँदैछ। / Online payment already received — awaiting verification.';
    }
    if ((float) ($booking['total_amount'] ?? 0) < 1) {
        return 'Amount not set.';
    }
    return '';
}

function icici_admin_alert(?int $bookingId, string $title, string $body): void
{
    try {
        Database::insert('notifications', [
            'audience'   => 'admin',
            'booking_id' => $bookingId,
            'icon'       => '🏦',
            'title'      => mb_substr($title, 0, 191),
            'body'       => mb_substr($body, 0, 500),
        ]);
    } catch (Throwable $e) {
        icici_log('ADMIN_ALERT_FAILED', ['title' => $title, 'e' => $e->getMessage()]);
    }
}

/**
 * Apply one bank result (return page or webhook). Idempotent: the return
 * POST and the webhook both arrive for the same payment.
 *
 * @return array{state:string, booking:?array, message:string}
 *   state: success | pending | failed | orphan (signed, booking unknown) | invalid (bad hash/merchant)
 */
function icici_apply_result(array $data, string $source): array
{
    if (!icici_verify_secure_hash($data)) {
        icici_log($source . '_HASH_MISMATCH', $data);
        return ['state' => 'invalid', 'booking' => null, 'message' => 'Security check failed.'];
    }

    $code   = (string) ($data['responseCode'] ?? $data['adviceResponseCode'] ?? '');
    $txnNo  = (string) ($data['merchantTxnNo'] ?? '');
    $txnId  = (string) ($data['txnID'] ?? '');
    $amount = (string) ($data['amount'] ?? '');
    $mid    = (string) ($data['merchantId'] ?? '');

    if ($mid !== '' && $mid !== ICICI_MERCHANT_ID) {
        icici_log($source . '_WRONG_MERCHANT', $data);
        return ['state' => 'invalid', 'booking' => null, 'message' => 'Wrong merchant.'];
    }

    $bookingId = icici_booking_id_from_txn($txnNo);
    $booking   = $bookingId !== null ? icici_load_booking(null, $bookingId) : null;
    if ($booking === null) {
        icici_log($source . '_UNKNOWN_BOOKING', $data);
        if (in_array($code, ICICI_SUCCESS_CODES, true)) {
            icici_admin_alert(null, 'ICICI: money for an unknown booking',
                "txn $txnNo / bank id $txnId / Rs $amount — check and refund by hand.");
        }
        // Signed but not ours to match: 'orphan' so the webhook still answers 200 (no retry storm).
        return ['state' => 'orphan', 'booking' => null, 'message' => 'Booking not found.'];
    }
    $pnr = (string) $booking['pnr'];

    if (in_array($code, ICICI_PENDING_CODES, true)) {
        icici_log($source . '_PENDING', ['pnr' => $pnr, 'txn' => $txnNo, 'code' => $code]);
        return ['state' => 'pending', 'booking' => $booking, 'message' => (string) ($data['respDescription'] ?? '')];
    }
    if (!in_array($code, ICICI_SUCCESS_CODES, true)) {
        icici_log($source . '_FAILED', ['pnr' => $pnr, 'txn' => $txnNo, 'code' => $code, 'desc' => $data['respDescription'] ?? '']);
        PayEvents::log((int) $booking['id'], $pnr, 'icici_fail');
        return ['state' => 'failed', 'booking' => $booking, 'message' => (string) ($data['respDescription'] ?? '')];
    }

    // ---- success from here: the bank says money was taken ----
    $utr = 'ICICI-' . ($txnId !== '' ? $txnId : $txnNo);
    $p   = $booking['_payment'] ?? null;

    // Already recorded (return + webhook both land here) — nothing to do.
    if ($p !== null && (string) ($p['utr_number'] ?? '') === $utr) {
        return ['state' => 'success', 'booking' => $booking, 'message' => 'already recorded'];
    }

    $expected = icici_amount_for($booking);
    if ($amount !== '' && abs((float) $amount - (float) $expected) > 0.009) {
        icici_log($source . '_AMOUNT_MISMATCH', ['pnr' => $pnr, 'paid' => $amount, 'expected' => $expected, 'txn' => $txnNo]);
        icici_admin_alert((int) $booking['id'], 'ICICI amount mismatch · ' . $pnr,
            "Paid Rs $amount but booking is Rs $expected (bank id $txnId). Check by hand.");
        return ['state' => 'failed', 'booking' => $booking, 'message' => 'Amount mismatch — our office will contact you.'];
    }

    $tag = icici_is_uat() ? 'ICICI UAT TEST (not real money)' : 'ICICI PG';
    try {
        BookingService::submitPaymentProof($pnr, [
            'utr'       => $utr,
            'payerName' => $tag,
            'method'    => 'bank',
        ]);
    } catch (Throwable $e) {
        // Money taken but the booking cannot hold it (cancelled/expired/...).
        icici_log($source . '_PROOF_REJECTED', ['pnr' => $pnr, 'txn' => $txnNo, 'e' => $e->getMessage()]);
        icici_admin_alert((int) $booking['id'], 'ICICI paid but booking closed · ' . $pnr,
            "Rs $amount paid (bank id $txnId) — " . $e->getMessage() . ' Refund or rebook by hand.');
        return ['state' => 'failed', 'booking' => $booking, 'message' => $e->getMessage()];
    }

    // Mark the row so staff can see it came signed from the bank.
    Database::query(
        'UPDATE payments SET match_hint = :h, match_note = :n, gateway_payload = :g
          WHERE booking_id = :b AND utr_number = :u',
        [
            'h' => icici_is_uat() ? 'icici_uat_test' : 'icici_paid',
            'n' => mb_substr($tag . ' · bank txn ' . $txnId . ' · Rs ' . $amount . ' · via ' . strtolower($source), 0, 255),
            'g' => json_encode(['source' => $source, 'env' => ICICI_ENVIRONMENT, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'b' => $booking['id'],
            'u' => $utr,
        ]
    );
    PayEvents::log((int) $booking['id'], $pnr, 'icici_paid');
    icici_log($source . '_RECORDED', ['pnr' => $pnr, 'txn' => $txnNo, 'bankTxn' => $txnId, 'amount' => $amount, 'env' => ICICI_ENVIRONMENT]);

    return ['state' => 'success', 'booking' => icici_load_booking(null, (int) $booking['id']), 'message' => ''];
}

/** Small self-contained result page (the old redirect targets never existed). */
function icici_render_page(string $title, string $body, string $tone = 'ok'): void
{
    $color = ['ok' => '#0a7c2f', 'wait' => '#b26a00', 'bad' => '#b3261e'][$tone] ?? '#0a7c2f';
    $home  = 'https://www.shreehariglobal.in/';
    $wa    = '';
    try {
        $bizWa = Settings::officeWhatsApp();
        if ($bizWa !== '') {
            $wa = 'https://wa.me/' . rawurlencode($bizWa);
        }
    } catch (Throwable $e) {
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="ne"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex">'
       . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f7f8fb;color:#111;margin:0;padding:16px}'
       . '.card{max-width:440px;margin:32px auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 24px rgba(0,0,0,.06)}'
       . 'h1{font-size:21px;margin:0 0 12px;color:' . $color . '}p{line-height:1.55;margin:8px 0}code{background:#eef1f5;padding:2px 6px;border-radius:4px}'
       . '.btn{display:block;text-align:center;background:#0a7c2f;color:#fff;padding:14px;border-radius:12px;text-decoration:none;font-weight:600;margin:14px 0 0}'
       . '.wa{background:#128C7E}.test{background:#fff4e5;border:1px solid #f0c27a;border-radius:8px;padding:8px 10px;font-size:14px}</style></head><body><div class="card">'
       . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $body
       . ($wa !== '' ? '<a class="btn wa" href="' . htmlspecialchars($wa, ENT_QUOTES, 'UTF-8') . '">WhatsApp मा सम्पर्क</a>' : '')
       . '<a class="btn" href="' . $home . '">गृहपृष्ठ · Home</a>'
       . '</div></body></html>';
}

function icici_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
