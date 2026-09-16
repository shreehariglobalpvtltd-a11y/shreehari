<?php
/**
 * GET /api/my-bookings.php — every booking that belongs to the signed-in
 * customer (4 Sep 2026: one name + mobile account, many tickets).
 *
 * Ownership is the session: bookings whose user_id is this account OR
 * whose contact number is this account's number (counter sales and
 * bookings made before the customer ever signed in). Each row is returned
 * in exactly the shape /api/track.php uses, so the app's bookingFromServer()
 * adapter reads both the same way. Newest first.
 *
 * ?tab=upcoming|completed|cancelled|all  (default all)
 * ?page=N                                (20 per page)
 *
 * Filtering is done in SQL, not in the browser. The old endpoint returned a
 * flat LIMIT 30 with no filters at all, so "show me my past trips" meant
 * shipping every booking and hiding most of them — which also silently
 * truncated anyone with more than 30.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $me = Auth::user();
    if ($me === null) {
        Response::error('Please sign in with your name and mobile number to see your bookings.', 401);
    }

    Security::requireRateLimit('my_bookings', Security::clientIp(), 30, 60);

    $phone = normalisePhone((string) ($me['phone'] ?? ''));

    $tab = strtolower(Security::clean((string) ($_GET['tab'] ?? 'all'), 20));
    if (!in_array($tab, ['all', 'upcoming', 'completed', 'cancelled'], true)) {
        $tab = 'all';
    }

    $perPage = 20;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $params = ['u' => (int) ($me['id'] ?? 0), 'p' => $phone];
    $filter = '';

    /* "Upcoming" is about the JOURNEY, not the booking row: a confirmed
       ticket for tomorrow is upcoming, the same ticket next week is
       completed. Judged on the outbound leg's travel date, which is what
       the passenger means by "my next trip". */
    if ($tab === 'upcoming') {
        $filter = " AND b.status IN ('pending','confirmed')
                    AND EXISTS (SELECT 1 FROM booking_legs bl
                                 WHERE bl.booking_id = b.id
                                   AND bl.leg_type = 'outbound'
                                   AND bl.travel_date >= CURDATE())";
    } elseif ($tab === 'completed') {
        $filter = " AND (b.status = 'completed'
                     OR (b.status = 'confirmed'
                         AND NOT EXISTS (SELECT 1 FROM booking_legs bl
                                          WHERE bl.booking_id = b.id
                                            AND bl.leg_type = 'outbound'
                                            AND bl.travel_date >= CURDATE())))";
    } elseif ($tab === 'cancelled') {
        $filter = " AND b.status IN ('cancelled','rejected','expired')";
    }

    $total = (int) Database::scalar(
        "SELECT COUNT(*) FROM bookings b
          WHERE (b.user_id = :u OR b.contact_phone = :p){$filter}",
        $params,
        0
    );

    /* LIMIT / OFFSET are interpolated, not bound — MySQL will not accept a
       placeholder there under real prepares. Both are (int) locals derived
       from a max()/(int) cast above, never raw input, which is the same
       rule admin/activity-log.php:99-104 follows. */
    $rows = Database::fetchAll(
        "SELECT b.pnr FROM bookings b
          WHERE (b.user_id = :u OR b.contact_phone = :p){$filter}
          ORDER BY b.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    $list = [];
    foreach ($rows as $row) {
        $detail = BookingService::detail((string) $row['pnr']);
        if ($detail !== null) {
            $list[] = shg_customer_payload($detail);
        }
    }

    Response::success([
        'phone'    => $phone,
        'name'     => (string) ($me['name'] ?? ''),
        'tab'      => $tab,
        'page'     => $page,
        'perPage'  => $perPage,
        'total'    => $total,
        'hasMore'  => ($offset + count($list)) < $total,
        'bookings' => $list,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
