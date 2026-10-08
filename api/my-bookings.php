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
 * 8 Oct 2026 (owner: name + phone is never proof): the whole list is only
 * for a session that proved the number with the WhatsApp code
 * (CustomerProfile::verifiedUserId). A plain name + mobile session sees
 * just the tickets THIS browser session booked (Auth::ownPnrs) and gets
 * needsOtp:true, so the app can offer "Verify on WhatsApp to see all".
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
        Response::error('Please sign in with the code we send on WhatsApp to see your bookings. / बुकिङ हेर्न WhatsApp मा आएको कोडले साइन इन गर्नुहोस्।', 401);
    }

    Security::requireRateLimit('my_bookings', Security::clientIp(), 30, 60);

    require_once INCLUDE_PATH . '/customerprofile.php';
    $verified = CustomerProfile::verifiedUserId() === (int) ($me['id'] ?? 0);

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

    /* Who owns what. Verified: the account's bookings (user_id) and every
       booking on its number. Not verified: only what this browser session
       booked — each PNR bound as its own placeholder (never interpolated). */
    $owner = '(b.user_id = :u OR b.contact_phone = :p)';
    if (!$verified) {
        $own = Auth::ownPnrs();
        if ($own === []) {
            Response::success([
                'phone'    => $phone,
                'name'     => (string) ($me['name'] ?? ''),
                'tab'      => $tab,
                'page'     => $page,
                'perPage'  => $perPage,
                'total'    => 0,
                'hasMore'  => false,
                'bookings' => [],
                'verified' => false,
                'needsOtp' => true,
            ]);
        }
        $params = [];
        $marks  = [];
        foreach (array_values($own) as $i => $p) {
            $marks[]         = ':own' . $i;
            $params['own' . $i] = $p;
        }
        $owner = 'b.pnr IN (' . implode(', ', $marks) . ')';
    }

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
          WHERE {$owner}{$filter}",
        $params,
        0
    );

    /* LIMIT / OFFSET are interpolated, not bound — MySQL will not accept a
       placeholder there under real prepares. Both are (int) locals derived
       from a max()/(int) cast above, never raw input, which is the same
       rule admin/activity-log.php:99-104 follows. */
    $rows = Database::fetchAll(
        "SELECT b.pnr FROM bookings b
          WHERE {$owner}{$filter}
          ORDER BY b.created_at DESC
          LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    $list = [];
    foreach ($rows as $row) {
        $detail = BookingService::detail((string) $row['pnr']);
        // The walk-in placeholder 0000000000 is never anybody's own booking.
        if ($detail !== null && !isPlaceholderPhone((string) ($detail['contact_phone'] ?? ''))) {
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
        'verified' => $verified,
        // true = only this browser's own tickets are listed; the WhatsApp
        // code unlocks every booking on the number
        'needsOtp' => !$verified,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
