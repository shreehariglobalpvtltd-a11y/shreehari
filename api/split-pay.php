<?php
/**
 * =====================================================================
 *  /api/split-pay.php — group booking split-pay (13 Sep 2026)
 *
 *  POST action=plan   { pnr, phone? }          → the shares for one booking
 *                                                 (created on first call, one per seat)
 *  POST action=claim  { pnr, s, k, name, utr } → a friend says "I paid this share"
 *  POST action=status { pnr, s, k }            → one share's public state
 *
 *  WHAT THIS IS — AND IS NOT
 *  A pending online booking for 2+ passengers can be paid by each friend
 *  through their own UPI link (pay-share.php). The shares are a checklist
 *  for the passengers and a hint list for the office: every claim records
 *  the payer's name and the UTR they typed. The MONEY record stays exactly
 *  what it was — the payments row the desk verifies — so this code can
 *  never confirm, discount or release a seat. It only informs.
 *
 *  ACCESS
 *  plan   — the booking owner: the signed-in customer whose mobile is on
 *           the booking, or the mobile printed on the ticket (the same
 *           bar api/track.php sets to reveal booking detail).
 *  claim  — anyone holding the share link, which carries an HMAC of
 *           pnr|share so a link cannot be forged or pointed at another
 *           ticket. Amount is server-side and fixed.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

/** HMAC that ties one share link to one booking + share number. */
function split_key(string $pnr, int $s): string
{
    return substr(Security::sign('split|' . strtoupper($pnr) . '|' . $s), 0, 20);
}

/** Load the booking + refuse the cases split-pay does not apply to. */
function split_booking(string $pnr): array
{
    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'A valid booking reference is required.']);
    }
    $b = BookingService::findByPnr($pnr);
    if ($b === null) {
        Response::notFound('No booking found with that reference.');
    }
    return $b;
}

/**
 * The share rows for a booking, created on first sight: one per seat, the
 * fare split evenly to the rupee, the last share carrying the rounding.
 *
 * @return array<int, array<string, mixed>>
 */
function split_shares(array $b, bool $create): array
{
    $bid  = (int) $b['id'];
    $rows = Database::fetchAll('SELECT * FROM payment_shares WHERE booking_id = :b ORDER BY share_no', ['b' => $bid]);
    if ($rows !== [] || !$create) {
        return $rows;
    }
    $n = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b', ['b' => $bid], 0);
    $n = max(1, min(20, $n));
    $total = (float) ($b['total_amount'] ?? 0);
    if ($n < 2 || $total <= 0) {
        return [];
    }
    $base = floor($total / $n);
    $acc  = 0.0;
    for ($i = 1; $i <= $n; $i++) {
        $amt = $i === $n ? round($total - $acc, 2) : $base;
        $acc += $amt;
        Database::insertIgnore('payment_shares', ['booking_id' => $bid, 'share_no' => $i, 'amount' => $amt, 'status' => 'open']);
    }
    return Database::fetchAll('SELECT * FROM payment_shares WHERE booking_id = :b ORDER BY share_no', ['b' => $bid]);
}

/** Public-safe view of one share row. */
function split_view(array $row, string $pnr): array
{
    $s = (int) $row['share_no'];
    return [
        'n'         => $s,
        'amount'    => (float) $row['amount'],
        'status'    => (string) $row['status'],
        'payerName' => $row['payer_name'] !== null ? (string) $row['payer_name'] : '',
        'utr'       => $row['utr'] !== null ? (string) $row['utr'] : '',
        'claimedAt' => $row['claimed_at'],
        'link'      => appUrl('pay-share.php?pnr=' . rawurlencode($pnr) . '&s=' . $s . '&k=' . split_key($pnr, $s)),
    ];
}

try {
    Security::requirePost();
    Security::requireCsrf();
    Security::requireRateLimit('split_pay', Security::clientIp(), 40, 300);

    if (!Settings::getBool('split_pay_enabled', true)) {
        Response::error('Split-pay is switched off.', 503);
    }

    $action = Security::clean((string) Response::field('action', 'plan'), 20);
    $pnr    = strtoupper(Security::clean((string) Response::field('pnr', ''), 40));
    $b      = split_booking($pnr);

    if ($action === 'plan') {
        $user   = Auth::user();
        $mine   = $user !== null && normalisePhone((string) ($user['phone'] ?? '')) !== ''
               && normalisePhone((string) ($user['phone'] ?? '')) === normalisePhone((string) ($b['contact_phone'] ?? ''));
        $given  = normalisePhone(Security::clean((string) Response::field('phone', ''), 20));
        $byNum  = $given !== '' && $given === normalisePhone((string) ($b['contact_phone'] ?? ''));
        if (!$mine && !$byNum && !Auth::can('bookings.view')) {
            Response::error('Only the passenger who booked can split this fare.', 403);
        }
        if ((string) $b['status'] !== 'pending' || (int) ($b['is_cod'] ?? 0) === 1) {
            Response::error('Split-pay is only for an online booking that is still waiting for payment.', 400);
        }
        $rows = split_shares($b, true);
        if ($rows === []) {
            Response::error('Split-pay needs a booking of two or more passengers.', 400);
        }
        Response::success([
            'pnr'     => $pnr,
            'total'   => (float) $b['total_amount'],
            'n'       => count($rows),
            'perHead' => (float) $rows[0]['amount'],
            'shares'  => array_map(static fn(array $r): array => split_view($r, $pnr), $rows),
        ]);
    }

    // claim / status carry the signed share key
    $s = (int) Response::field('s', 0);
    $k = Security::clean((string) Response::field('k', ''), 40);
    if ($s < 1 || $s > 20 || $k === '' || !hash_equals(split_key($pnr, $s), $k)) {
        Response::error('This payment link is not valid.', 403);
    }
    $row = Database::fetch('SELECT * FROM payment_shares WHERE booking_id = :b AND share_no = :s', ['b' => (int) $b['id'], 's' => $s]);
    if ($row === null) {
        Response::notFound('This share does not exist.');
    }

    if ($action === 'status') {
        Response::success(['pnr' => $pnr, 'bookingStatus' => (string) $b['status'], 'share' => split_view($row, $pnr)]);
    }

    if ($action === 'claim') {
        if ((string) $b['status'] !== 'pending') {
            Response::error('This booking is no longer waiting for payment.', 400);
        }
        $name = Security::clean((string) Response::field('name', ''), 120);
        $utr  = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) Response::field('utr', '')) ?? '');
        $ph   = normalisePhone(Security::clean((string) Response::field('phone', ''), 20));
        if ($name === '' || mb_strlen($name) < 2) {
            Response::invalid(['name' => 'Please enter your name.']);
        }
        if (strlen($utr) < 6 || strlen($utr) > 40) {
            Response::invalid(['utr' => 'Enter the UPI transaction number (UTR) from your payment app.']);
        }
        Database::update('payment_shares', [
            'status'      => 'claimed',
            'payer_name'  => $name,
            'payer_phone' => $ph !== '' ? $ph : null,
            'utr'         => $utr,
            'claimed_at'  => date('Y-m-d H:i:s'),
        ], 'id = :i', ['i' => (int) $row['id']]);
        Logger::audit('payment.share_claimed', 'booking', $pnr, ['share' => $s, 'status' => (string) $row['status']],
            ['share' => $s, 'status' => 'claimed', 'utr' => $utr, 'name' => $name], 'Split-pay share ' . $s . ' claimed on ' . $pnr);
        $fresh = Database::fetch('SELECT * FROM payment_shares WHERE id = :i', ['i' => (int) $row['id']]) ?? $row;
        Response::success(['pnr' => $pnr, 'share' => split_view($fresh, $pnr)], 'Thank you — the office will verify your payment shortly.');
    }

    Response::invalid(['action' => 'Unknown action.']);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
