<?php
/**
 * admin/_booking_filters.php — filters shared by the bookings register
 * (admin/bookings.php) and its export (admin/export.php), so the file that is
 * downloaded is always exactly the list that is on screen (5 Oct 2026: the
 * export ignored the Counter and Payment-method filters and returned more rows
 * than the page showed).
 *
 *   phone = none | bad | ok      the customer's number on the booking
 *   reach = got | agent | nobody  where the WhatsApp ticket ended up
 *   f_ticket / f_pax / f_mob / f_seat / mine  the register's column filters
 *                                (shg_booking_column_filters, 15 Oct 2026)
 *
 * All add to $where / $params (alias `b` = bookings) and return the cleaned
 * values for the form and the paging / export links.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * @param list<string>          $where
 * @param array<string, mixed>  $params
 * @param array<string, mixed>  $in     usually $_GET
 * @return array{phone: string, reach: string}
 */
function shg_booking_extra_filters(array &$where, array &$params, array $in): array
{
    $phone = Security::clean((string) ($in['phone'] ?? ''), 8);
    $reach = Security::clean((string) ($in['reach'] ?? ''), 8);

    // A usable number = 10-13 digits not starting with 0 (India / Nepal, with or
    // without the country code); 0000000000 / blank is the desk's "no number".
    $none = "(b.contact_phone IS NULL OR b.contact_phone = '' OR b.contact_phone REGEXP '^0+$')";
    $ok   = "b.contact_phone REGEXP '^[1-9][0-9]{9,12}$'";
    if ($phone === 'none') {
        $where[] = $none;
    } elseif ($phone === 'ok') {
        $where[] = $ok;
    } elseif ($phone === 'bad') {
        $where[] = "(NOT $none AND NOT $ok)";
    } else {
        $phone = '';
    }

    $hasQueue = false;
    if (in_array($reach, ['got', 'agent', 'nobody'], true)) {
        try {
            $hasQueue = (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_ticket_queue'",
                [], 0
            ) > 0;
        } catch (Throwable $e) {
            $hasQueue = false;
        }
    }
    if ($hasQueue) {
        $cust   = "EXISTS (SELECT 1 FROM wa_ticket_queue wq WHERE wq.booking_id = b.id AND wq.role IN ('customer','alt') AND wq.status IN ('sent','delivered','read'))";
        $seller = "EXISTS (SELECT 1 FROM wa_ticket_queue wq2 WHERE wq2.booking_id = b.id AND wq2.role = 'seller' AND wq2.status IN ('sent','delivered','read'))";
        if ($reach === 'got') {
            $where[] = $cust;
        } elseif ($reach === 'agent') {
            $where[] = "(NOT $cust AND $seller)";
        } else {
            $where[] = "(NOT $cust AND NOT $seller AND b.status IN ('confirmed','completed'))";
        }
    } else {
        $reach = '';
    }

    return ['phone' => $phone, 'reach' => $reach];
}

/**
 * 15 Oct 2026 (owner: chalani register, Excel-style column filters). The
 * funnel under each register heading, shared with the export so the file is
 * still exactly the list on screen:
 *
 *   f_ticket = ticket number or PNR (part)   f_pax  = a passenger's name (part)
 *   f_mob    = the customer's mobile (digits) f_seat = a seat as PRINTED (A7, B5-6)
 *                                                      or as stored (L11)
 *   mine     = 1 -> only the tickets I sold (b.sold_by_admin_id = me)
 *
 * 'phone' already means the none / bad / ok number check above, so the
 * mobile column filter is f_mob. Each arm is an EXISTS / plain clause on the
 * booking, so a booking is never multiplied by its seats or passengers.
 *
 * @param list<string>          $where
 * @param array<string, mixed>  $params
 * @param array<string, mixed>  $in     usually $_GET
 * @return array{f_ticket: string, f_pax: string, f_mob: string, f_seat: string, mine: string}
 */
function shg_booking_column_filters(array &$where, array &$params, array $in): array
{
    $tk   = Security::clean((string) ($in['f_ticket'] ?? ''), 40);
    $pax  = Security::clean((string) ($in['f_pax'] ?? ''), 60);
    $mob  = (string) preg_replace('/\D/', '', Security::clean((string) ($in['f_mob'] ?? ''), 20));
    $seat = strtoupper((string) preg_replace('/\s+/', '', Security::clean((string) ($in['f_seat'] ?? ''), 16)));
    $mine = (string) ($in['mine'] ?? '') === '1' ? '1' : '';

    if ($tk !== '') {
        // The Ticket cell shows the ticket number and the PNR under it.
        $where[] = '(b.pnr LIKE :fTk1 OR EXISTS (SELECT 1 FROM tickets tf WHERE tf.booking_id = b.id AND tf.ticket_number LIKE :fTk2))';
        $params['fTk1'] = '%' . $tk . '%';
        $params['fTk2'] = '%' . $tk . '%';
    }
    if ($pax !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM booking_passengers bpf WHERE bpf.booking_id = b.id AND bpf.full_name LIKE :fPax)';
        $params['fPax'] = '%' . $pax . '%';
    }
    if ($mob !== '') {
        $where[] = 'b.contact_phone LIKE :fMob';
        $params['fMob'] = '%' . $mob . '%';
    } else {
        $mob = '';
    }
    if ($seat !== '' && preg_match('/^[A-Z0-9+\-]{1,16}$/', $seat) === 1) {
        // A live seat (released seats are not on the ticket any more) whose
        // stored id is what was typed, or whose PRINTED label is.
        $lbl = shg_seat_label_match($seat, 'bsf.seat_no', $params, 'fSl');
        $where[] = 'EXISTS (SELECT 1 FROM booking_seats bsf WHERE bsf.booking_id = b.id AND bsf.released_at IS NULL'
                 . ' AND (bsf.seat_no = :fSeatRaw' . ($lbl !== null ? ' OR ' . $lbl : '') . '))';
        $params['fSeatRaw'] = $seat;
    } else {
        $seat = '';
    }
    if ($mine === '1') {
        $me = (int) (Auth::admin()['id'] ?? 0);
        if ($me > 0) {
            $where[] = 'b.sold_by_admin_id = :mineId';
            $params['mineId'] = $me;
        } else {
            $mine = '';
        }
    }

    /* 15 Oct 2026 (review fix): the dashboard's drill-downs. cancelled_from /
       cancelled_to = the CANCEL date window ("Cancelled today"); cancel_req=1 =
       tickets with an open WhatsApp cancel request (counted on the dashboard,
       settled by hand). */
    $cf = (string) ($in['cancelled_from'] ?? '');
    $ct = (string) ($in['cancelled_to'] ?? '');
    if (Security::isValidDate($cf)) {
        $where[] = 'b.cancelled_at >= :cxFrom';
        $params['cxFrom'] = $cf . ' 00:00:00';
    }
    if (Security::isValidDate($ct)) {
        $where[] = 'b.cancelled_at <= :cxTo';
        $params['cxTo'] = $ct . ' 23:59:59';
    }
    if ((string) ($in['cancel_req'] ?? '') === '1') {
        $where[] = "EXISTS (SELECT 1 FROM ticket_edit_audit tq WHERE tq.booking_id = b.id AND tq.field_changed = 'cancel_request' AND tq.outcome = 'requested')";
    }

    return ['f_ticket' => $tk, 'f_pax' => $pax, 'f_mob' => $mob, 'f_seat' => $seat, 'mine' => $mine];
}

/**
 * Does $col (a stored seat id on booking `b`) name the seat PRINTED as $typed?
 * (15 Oct 2026.) The ticket, the register and the manifest print grid labels
 * (L11 -> "B5", private L6 -> "B5-6"); storage keeps L/U ids, and the same
 * id means a different berth in the other booking mode — so the match is
 * per mode. Returns null when $typed is not a label we print (the caller's
 * raw-id match then stands alone). Placeholders are :{$pfx}s0.. / :{$pfx}p0..
 *
 * @param array<string, mixed> $params
 */
function shg_seat_label_match(string $typed, string $col, array &$params, string $pfx): ?string
{
    $arms = [];
    foreach (['s' => 'sharing', 'p' => 'private'] as $k => $mode) {
        $ids = Seats::storageIdsForLabel($typed, 'sleeper', $mode);
        if ($ids === []) {
            continue;
        }
        $ph = [];
        foreach (array_values($ids) as $i => $id) {
            $ph[] = ':' . $pfx . $k . $i;
            $params[$pfx . $k . $i] = $id;
        }
        $arms[] = ($mode === 'private' ? "b.booking_mode = 'private'" : "COALESCE(b.booking_mode, 'sharing') <> 'private'")
                . ' AND ' . $col . ' IN (' . implode(', ', $ph) . ')';
    }
    return $arms === [] ? null : '((' . implode(') OR (', $arms) . '))';
}

/**
 * The global search's seat arm, label-aware (15 Oct 2026): an EXISTS over the
 * booking's live seats for a typed PRINTED label, or null when $q is not one.
 * ORed beside the raw `seat_no LIKE` arm, which keeps finding L11 / U7.
 *
 * @param array<string, mixed> $params
 */
function shg_seat_label_exists(string $q, array &$params, string $pfx = 'qSl'): ?string
{
    $q = trim($q);
    if ($q === '' || strlen($q) > 16) {
        return null;
    }
    $m = shg_seat_label_match($q, 'bsq.seat_no', $params, $pfx);
    return $m === null ? null
        : 'EXISTS (SELECT 1 FROM booking_seats bsq WHERE bsq.booking_id = b.id AND bsq.released_at IS NULL AND ' . $m . ')';
}

/**
 * 29 Oct 2026 (management upgrade 2.1 / 3.1): desk, pickup / drop, cabin,
 * amount range and a hand-picked list — shared by the register and its
 * export so the downloaded file is still exactly the list on screen.
 *
 *   counter  = a desk code (bookings cut at, or sold by staff of, that desk);
 *              ignored when desk isolation already pins a counter clerk
 *   board    = part of the outbound pickup stop    drop = part of the drop stop
 *   mode     = sharing | private                    amin / amax = ticket total ₹
 *   ids      = "12,15,19" — the rows ticked in the register (Export selected)
 *
 * Desk isolation (Auth::deskScopeCode) is applied here too, before the
 * user's own choices, so editing the query string can never widen it.
 *
 * @param list<string>          $where
 * @param array<string, mixed>  $params
 * @param array<string, mixed>  $in     usually $_GET
 * @return array{counter: string, board: string, drop: string, mode: string, amin: string, amax: string, ids: string}
 */
function shg_booking_trip_filters(array &$where, array &$params, array $in): array
{
    $counter = mb_strtoupper(Security::clean((string) ($in['counter'] ?? ''), 16));
    $board   = Security::clean((string) ($in['board'] ?? ''), 60);
    $drop    = Security::clean((string) ($in['drop'] ?? ''), 60);
    $mode    = (string) ($in['mode'] ?? '');
    $amin    = trim((string) ($in['amin'] ?? ''));
    $amax    = trim((string) ($in['amax'] ?? ''));
    $idsRaw  = (string) ($in['ids'] ?? '');

    $desk = Auth::deskScopeCode();
    if ($desk !== null && CounterDesk::stampColumn()) {
        $where[] = CounterDesk::scopeClause('b', 'deskScopeT');
        $params['deskScopeT'] = $desk;
        $counter = '';
    }
    if ($counter !== '' && CounterDesk::stampColumn()) {
        $where[] = CounterDesk::scopeClause('b', 'ctrFltT');
        $params['ctrFltT'] = $counter;
    } else {
        $counter = '';
    }
    if ($board !== '') {
        $where[] = "EXISTS (SELECT 1 FROM booking_legs blfb WHERE blfb.booking_id = b.id AND blfb.leg_type = 'outbound' AND blfb.boarding_stop LIKE :fBoard)";
        $params['fBoard'] = '%' . $board . '%';
    }
    if ($drop !== '') {
        $where[] = "EXISTS (SELECT 1 FROM booking_legs blfd WHERE blfd.booking_id = b.id AND blfd.leg_type = 'outbound' AND blfd.drop_stop LIKE :fDrop)";
        $params['fDrop'] = '%' . $drop . '%';
    }
    if (in_array($mode, ['sharing', 'private'], true)) {
        $where[] = "COALESCE(b.booking_mode, 'sharing') = :fMode";
        $params['fMode'] = $mode;
    } else {
        $mode = '';
    }
    if ($amin !== '' && is_numeric($amin) && (float) $amin >= 0) {
        $where[] = 'b.total_amount >= :fAmin';
        $params['fAmin'] = round((float) $amin, 2);
    } else {
        $amin = '';
    }
    if ($amax !== '' && is_numeric($amax) && (float) $amax >= 0) {
        $where[] = 'b.total_amount <= :fAmax';
        $params['fAmax'] = round((float) $amax, 2);
    } else {
        $amax = '';
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), static fn(int $v): bool => $v > 0)));
    $ids = array_slice($ids, 0, 500);
    if ($ids !== []) {
        // Integers only (cast above), so they are safe to inline.
        $where[] = 'b.id IN (' . implode(',', $ids) . ')';
    }

    return ['counter' => $counter, 'board' => $board, 'drop' => $drop, 'mode' => $mode,
            'amin' => $amin, 'amax' => $amax, 'ids' => implode(',', $ids)];
}
