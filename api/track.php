<?php
/**
 * GET/POST /api/track.php — retrieve a booking by PNR (+ optional phone).
 * Body: { pnr, phone? }
 *
 * Phone is required to reveal full passenger detail; without it only a
 * status summary is returned, so a guessed PNR leaks nothing sensitive.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $pnr   = Security::clean(Response::field('pnr', ''), 40);
    $phone = normalisePhone(Security::clean(Response::field('phone', ''), 20));

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'Enter a valid booking reference (PNR).']);
    }

    Security::requireRateLimit('track', Security::clientIp(), 40, 60);

    $detail = BookingService::detail($pnr);
    if ($detail === null) {
        Response::notFound('No booking found with that reference.');
    }

    // Full detail only when the phone matches, or the customer is signed in
    // as the booking owner, or a staff member entitled to THIS booking is
    // viewing.
    //
    // Holding a staff session is not enough on its own. A counter agent has
    // one and may still only ever see the bookings they sold themselves, so
    // scope them by bookings.sold_by_admin_id exactly as admin/booking-view.php
    // does. Without this the PNR — which is printed on every ticket, so it is
    // no secret — was the only thing between an agent and full passenger
    // detail (name, phone, seats, crew) for the entire company.
    $scopeId   = Auth::bookingScopeAdminId();
    $staffSees = Auth::isAdmin()
        && ($scopeId === null || (int) ($detail['sold_by_admin_id'] ?? 0) === $scopeId);

    // A walk-in's placeholder number (0000000000) proves nothing: it is the
    // same "number" on every walk-in ticket the desk sells.
    $phoneMatch = $phone !== '' && !isPlaceholderPhone((string) $detail['contact_phone'])
            && $phone === (string) $detail['contact_phone'];

    /* The signed-in customer (8 Oct 2026): the one customer rule
       (Auth::customerMayOpen — OTP-verified session for this number, or a PNR
       booked in this browser session) when it is there; the old account-id
       match until it lands. A name+mobile quick sign-in alone is not proof. */
    $sessionOwner = method_exists('Auth', 'customerMayOpen')
        ? (bool) Auth::customerMayOpen($detail)
        : (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($detail['user_id'] ?? -1));

    /* Phone-miss throttle (8 Oct 2026, D8-M2). PNRs are sequential, so a
       known mobile number could be tried against every PNR to find that
       traveller's trips. Each WRONG phone counts against that phone from this
       IP (8 an hour) and this IP (30 an hour); once either budget is spent, even the
       RIGHT phone gets only the partial answer, so the throttle itself never
       says yes or no. Staff and the signed-in owner are not counted. */
    $missSpent = static function (string $bucket, string $id, int $max, int $window): bool {
        try {
            $r = Database::fetch('SELECT hits, window_start, blocked_until FROM rate_limits WHERE bucket = :b AND identifier = :i LIMIT 1',
                ['b' => $bucket, 'i' => $id]);
        } catch (Throwable $e) {
            return false;
        }
        if ($r === null) {
            return false;
        }
        if (!empty($r['blocked_until']) && strtotime((string) $r['blocked_until']) > time()) {
            return true;
        }
        return strtotime((string) $r['window_start']) >= time() - $window && (int) $r['hits'] >= $max;
    };
    $ownsIt = $staffSees || $sessionOwner;
    if (!$ownsIt && $phone !== '') {
        /* The phone bucket is keyed on phone + IP (review, 8 Oct 2026): keyed
           on the phone alone, anyone who knew a customer's mobile could spend
           its budget from elsewhere and lock the real owner out for an hour.
           The app's own sync of a "for someone else" ticket (the account's
           phone is that booking's alt_phone, or the account booked it) is
           not a miss either - it still gets only the partial answer. */
        $phoneKey = $phone . '|' . Security::clientIp();
        $spent = $missSpent('track_phone_miss', $phoneKey, 8, 3600)
              || $missSpent('track_miss_ip', Security::clientIp(), 30, 3600);
        $ownSide = $phone === normalisePhone((string) ($detail['alt_phone'] ?? ''))
                || (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($detail['user_id'] ?? -1));
        if ($phoneMatch && !$spent) {
            $ownsIt = true;
        } elseif (!$phoneMatch && !$ownSide) {
            Security::rateLimit('track_phone_miss', $phoneKey, 8, 3600);
            Security::rateLimit('track_miss_ip', Security::clientIp(), 30, 3600);
        }
    }

    if (!$ownsIt) {
        Response::success([
            'pnr'        => $detail['pnr'],
            'status'     => $detail['status'],
            // Live pill is safe to expose without ownership — it is derived
            // from public route dep_time + status, not from passenger data.
            'live_status'=> Ticket::liveStatus($detail),
            'partial'    => true,
            'hint'       => 'Enter the mobile number used at booking to see full details.',
        ]);
    }

    // One shared shape for track.php and my-bookings.php (includes/helpers.php).
    $payload = shg_customer_payload($detail);
    // Ticket page only (17 Sep 2026): did the WhatsApp ticket go? See shg_wa_last().
    // 8 Oct 2026: only a send to the booking's own number(s) answers — never a seller copy.
    $payload['wa'] = shg_wa_last((int) ($detail['id'] ?? 0), (string) ($detail['contact_phone'] ?? ''));

    /* Directions to the pickup (8 Oct 2026, D6-11): only from the OFFICIAL
       coordinates (the label's [lat,lng] or the route's pickup row) — null
       when the stop has none; a position is never guessed. Single booking
       only, so the My Bookings list does not pay a query per row. */
    $payload['pickupMap'] = null;
    if (($detail['status'] ?? '') === 'confirmed') {
        try {
            $leg0 = null;
            foreach ($detail['legs'] ?? [] as $lg) {
                if ((string) ($lg['leg_type'] ?? 'outbound') === 'outbound') { $leg0 = $lg; break; }
            }
            $leg0 ??= ($detail['legs'][0] ?? []);
            $rid  = (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => (int) ($leg0['schedule_id'] ?? 0)], 0);
            $geo  = Ticket::pickupGeo(['boarding_stop' => (string) ($leg0['boarding_stop'] ?? ''), 'route_id_for_stop' => $rid]);
            $payload['pickupMap'] = $geo !== null ? Ticket::pickupMapsUrl($geo) : null;
        } catch (Throwable $e) {
            $payload['pickupMap'] = null;
        }
    }
    Response::success($payload);
} catch (Throwable $e) {
    Response::serverError($e);
}
