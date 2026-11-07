<?php
/**
 * admin/manifest.php — the complete passenger list for one departure.
 *
 * Master prompt §16. The agent view (agent-passengers.php) is deliberately
 * scoped to `sold_by_admin_id`, so it shows only what one agent sold and
 * online customer bookings — which carry no seller at all — never appear in
 * it. The office needs the opposite: every passenger on the bus, whoever
 * sold the seat. That list did not exist anywhere.
 *
 * Built straight from the database (§16: "generated from database records"),
 * never from a cached or client-held copy.
 *
 * Modes:
 *   (default)      on-screen, with a Print button that hides the admin chrome
 *   ?format=csv    the same rows as a spreadsheet download
 *
 * Permission: bookings.view — the same grant the bookings list needs, since
 * this shows the same personal data. An agent reaching this page is scoped
 * back to their own sales rather than being shown the whole coach.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // TripStatus (5 Sep 2026: explicit, not via another include)
$admin = admin_boot('bookings.view');
$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)

$date = Security::clean($_GET['date'] ?? '', 10);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = todayISO();
}
$routeId = (int) ($_GET['route'] ?? 0);
$format  = in_array($_GET['format'] ?? '', ['csv', 'chalani', 'chalanipdf', 'chalanipng', 'pdf'], true) ? (string) $_GET['format'] : 'html';
/* 29 Oct 2026 (management upgrade 3.2): passengers in the order the coach
   picks them up — the route's own boarding-stop order (route_stops.sort_order),
   seat order inside one stop. ?order=seat keeps the old seat-only list. */
/* 5 Nov 2026 (master upgrade §3): 'group' is the default — pickup order, but
   one booking's passengers stay together under a "Dileep — 6 passengers —
   Surat" line. 'stop' (pure pickup order) and 'seat' stay as they were. */
$orderReq = (string) ($_GET['order'] ?? 'group');
$order    = in_array($orderReq, ['group', 'stop', 'seat'], true) ? $orderReq : 'group';

/* An agent must not be handed the whole coach through a URL the sidebar
   does not show them. Same scope rule the bookings list and CSV export use. */
$scopeId = Auth::bookingScopeAdminId();

/* 6 Nov 2026 (owner: "ticket transfer / delete garna — admin le click garera
   sajilai"): a one-click CANCEL from the chalani for the office (bookings.cancel).
   Same engine as Booking view → Cancel (BookingService::cancel, staff-initiated,
   refund left to the owner by hand). Transfer opens the reschedule page. */
$mfFlash = null;
$mfCanCancel = Auth::can('bookings.cancel') && $scopeId === null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['mf_action'] ?? '') === 'cancel') {
    if (!Security::verifyCsrf()) {
        $mfFlash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mfCanCancel) {
        $mfFlash = ['bad', 'Only the office may cancel from the chalani.'];
    } else {
        $cPnr = Security::clean((string) ($_POST['pnr'] ?? ''), 40);
        try {
            require_once INCLUDE_PATH . '/ticketedit.php';
            $reason = Security::clean((string) ($_POST['reason'] ?? ''), 200);
            $res = BookingService::cancel($cPnr, $reason !== '' ? $reason : 'Cancelled from chalani by staff', false);
            TicketEdit::closeOpenCancels((int) ($res['booking']['id'] ?? 0), (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? 'staff')) . ' #' . (int) $admin['id']);
            Logger::audit('manifest.cancel', 'booking', $cPnr, null, null, 'Cancelled from chalani');
            /* 6 Nov 2026 (owner: "cancel gare fine ni lagaun milos, admin chahe"): optional fine */
            $fineMsg = '';
            $fineIn  = (float) preg_replace('/[^0-9.]/', '', (string) ($_POST['fine'] ?? ''));
            if ($fineIn > 0 && OpsHub::fineOn()) {
                $fineMsg = OpsHub::applyCancelFine((int) ($res['booking']['id'] ?? 0), $fineIn, $reason, $admin);
            }
            $mfFlash = ['ok', '🗑 ' . $cPnr . ' cancelled — seats are free again. Refund (if any) is settled by hand.' . ($fineMsg !== '' ? ' ' . $fineMsg : '')];
        } catch (Throwable $ex) {
            $mfFlash = ['bad', $cPnr . ': ' . $ex->getMessage()];
        }
    }
}

/* 6 Nov 2026 (owner: "chalani ma admin le payment option add garn, kasko payment
   vayo only admin le tick laune, due ra paid color"): the office's own tick per
   booking (OpsHub::markPaid / markDue) and its payment options list. */
$mfCanTick = OpsHub::canTick();
$mfAct = (string) ($_POST['mf_action'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($mfAct, ['paid', 'due', 'addmode', 'delmode'], true)) {
    if (!Security::verifyCsrf()) {
        $mfFlash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mfCanTick) {
        $mfFlash = ['bad', 'Payment tick is for the office (admin) only.'];
    } else {
        try {
            if ($mfAct === 'paid') {
                $amtIn = trim((string) ($_POST['amount'] ?? ''));
                $r = OpsHub::markPaid((int) ($_POST['booking_id'] ?? 0), (string) ($_POST['mode'] ?? 'Cash'),
                    $amtIn !== '' ? (float) preg_replace('/[^0-9.]/', '', $amtIn) : null, (string) ($_POST['note'] ?? ''), $admin);
                $mfFlash = [$r['ok'] ? 'ok' : 'bad', $r['msg']];
            } elseif ($mfAct === 'due') {
                $r = OpsHub::markDue((int) ($_POST['booking_id'] ?? 0), $admin);
                $mfFlash = ['ok', $r['msg']];
            } elseif ($mfAct === 'addmode') {
                OpsHub::addPayMode((string) ($_POST['mode'] ?? ''), (int) $admin['id']);
                $mfFlash = ['ok', '➕ Payment option added.'];
            } else {
                OpsHub::removePayMode((string) ($_POST['mode'] ?? ''), (int) $admin['id']);
                $mfFlash = ['ok', '➖ Payment option removed.'];
            }
        } catch (Throwable $ex) {
            $mfFlash = ['bad', $ex->getMessage()];
        }
    }
}

/* ---- which departures run on this date ------------------------------- */
/* 17 Sep 2026: the same facts ChallanPng::schedule() reads — the slot (daily
   bus = 1, Bus-Calendar extra buses = 2+), the per-departure coach override
   and the route's bus as a fallback — so the chalani never disagrees with
   the challan picture about the coach, the plate or the number. */
$departures = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.travel_date, s.status, s.seats_booked, s.total_seats, s.slot,
            r.id AS route_id, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            COALESCE(s.coach_type_override, r.coach_type) AS coach_type, r.coach_type AS route_coach_type,
            bu.bus_number, bu.bus_name,
            d.full_name AS driver_name, d.phone AS driver_phone
       FROM schedules s
       JOIN routes r      ON r.id = s.route_id
       LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date = :d AND r.is_active = 1
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC, s.slot ASC, r.id ASC",
    ['d' => $date]
);

if ($routeId <= 0 && $departures !== []) {
    $routeId = (int) $departures[0]['route_id'];
}

/* ?sid= picks one departure by its schedule id (an extra bus on the same
   date — Bus Calendar, 5 Sep 2026); otherwise the first departure of the
   chosen route, as before. */
$sidReq = (int) ($_GET['sid'] ?? 0);
$trip = null;
foreach ($departures as $dep) {
    if ($sidReq > 0 ? (int) $dep['schedule_id'] === $sidReq : (int) $dep['route_id'] === $routeId) { $trip = $dep; break; }
}
if ($trip !== null) { $routeId = (int) $trip['route_id']; }

/* Overnight buses that left on an EARLIER date but are still inside their 24h
   counter-grace (an agent may still cut a late ticket) — surfaced so the
   chalani for a bus that departed Surat yesterday afternoon is one click away,
   instead of the "No departure scheduled → add trip (past date refused)"
   dead-end. Excludes the date being viewed and any cancelled trip. 24 = the
   TripStatus::COUNTER_LATE_HOURS window, inlined so this read needs no extra
   require. */
$stillRunning = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.travel_date, r.id AS route_id, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
      WHERE r.is_active = 1
        AND s.travel_date <> :d
        AND s.status <> 'cancelled'
        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) <= NOW()
        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) + INTERVAL 24 HOUR >= NOW()
      ORDER BY s.travel_date DESC, dep_time ASC
      LIMIT 10",
    ['d' => $date]
);

/* ---- the manifest ----------------------------------------------------
   Every seat sold on this departure, from any source. Cancelled and
   rejected bookings are excluded: those seats are back in the pool and
   the people are not travelling. Pending ones ARE listed, flagged, so the
   desk can see who still owes money before the coach leaves. */
$rows = [];
if ($trip !== null) {
    $where  = ['bl.schedule_id = :sid', "b.status IN ('confirmed','pending','completed')"];
    $params = ['sid' => (int) $trip['schedule_id']];

    if ($scopeId !== null) {
        $where[] = 'b.sold_by_admin_id = :scope';
        $params['scope'] = $scopeId;
    }

    $rows = Database::fetchAll(
        "SELECT bp.seat_no, bp.full_name, bp.age, bp.gender, bp.boarded_at,
                bp.id_type, bp.id_number, bp.special_need,
                b.booking_mode,
                b.id AS booking_id, b.pnr, b.contact_phone, b.status AS booking_status,
                b.source, b.is_cod, b.total_amount, b.base_total, b.sold_by_admin_id, b.created_at,
                bl.boarding_stop, bl.drop_stop,
                p.status AS pay_status, p.method AS pay_method,
                t.ticket_number, t.scanned_at, t.is_void,
                ad.full_name AS agent_name, ad.username AS agent_user, ad.role AS agent_role
           FROM booking_passengers bp
           JOIN bookings b      ON b.id = bp.booking_id
           JOIN booking_legs bl ON bl.id = bp.leg_id
           LEFT JOIN payments p ON p.id = (
                 SELECT p2.id FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1)
           LEFT JOIN tickets t  ON t.booking_id = b.id
           LEFT JOIN admins ad  ON ad.id = b.sold_by_admin_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY " . ($order !== 'seat'
              ? "COALESCE((SELECT MIN(rs.sort_order) FROM route_stops rs
                            WHERE rs.route_id = :rid AND rs.stop_type = 'boarding' AND rs.stop_name <> ''
                              AND bl.boarding_stop LIKE CONCAT(rs.stop_name, '%')), 9999), "
                . ($order === 'group' ? 'bl.boarding_stop, b.id, ' : '')
              : '') . "LENGTH(bp.seat_no), bp.seat_no",
        $params + ($order !== 'seat' ? ['rid' => (int) $trip['route_id']] : [])
    );
}

/* Per-person fare divisor. total_amount is stored ONCE per booking and
   covers every seat on it — including a round-trip's return leg, which this
   single-departure manifest does not list. So divide by the booking's WHOLE
   passenger-seat count (all legs), not just the rows on this departure, or a
   round-trip fare would read double. */
/* 6 Nov 2026: paid · with agent · due — one rule (OpsHub::payState) for this
   screen, Bus Control and the public link. */
$mfTicks = OpsHub::collections(array_map(static fn(array $r): int => (int) ($r['booking_id'] ?? 0), $rows));
$mfState = [];
foreach ($rows as $r) {
    $bid = (int) ($r['booking_id'] ?? 0);
    if (!isset($mfState[$bid])) {
        $mfState[$bid] = OpsHub::payState($r, $mfTicks[$bid] ?? null);
    }
}

$paxTotalPerBooking = [];
$bids = array_values(array_unique(array_map(
    static fn(array $r): int => (int) ($r['booking_id'] ?? 0),
    $rows
)));
$bids = array_values(array_filter($bids, static fn(int $i): bool => $i > 0));
if ($bids !== []) {
    $in = implode(',', $bids);   // ints from the DB — safe to inline
    foreach (Database::fetchAll(
        "SELECT booking_id, COUNT(*) c FROM booking_passengers WHERE booking_id IN ($in) GROUP BY booking_id"
    ) as $pr) {
        $paxTotalPerBooking[(int) $pr['booking_id']] = (int) $pr['c'];
    }
}

/* ---- shared field formatting (screen and CSV must not drift) --------- */
/* The seller, decided in ONE place — Ticket::issuedBy(), the same call the
   ticket PNG, the ticket PDF and the chalani make. This used to answer AGENT
   for every staff sale, so an office booking sat in the agent column of the
   register with no code behind it while the passenger's own ticket said
   OFFICE (audit, 11 Sep 2026). */
$sourceLabel = static function (array $r): string {
    if (!empty($r['sold_by_admin_id'])) {
        $by = Ticket::issuedBy($r);
        return $by['kind'] === 'agent' ? 'AGENT' : $by['code'];
    }
    return strtoupper((string) ($r['source'] ?? 'web'));
};
/* A stop label as a HUMAN should read it. The stored form carries machine
   parts — "Surat · Departure @ 13:00 [21.1702,72.8311]" — and the manifest
   screen and the CSV were printing it verbatim, coordinates and all, while
   the ticket and the chalani printed the clean name. One rule now, used by
   all three. */
$stopShort = static function (string $label): string {
    $s = (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label);   // drop [lat,lng]
    $s = (string) preg_replace('/\s*@.*$/u', '', $s);                 // drop "@ 13:00"
    $s = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $s);          // drop "(...)"
    $s = trim((string) preg_replace('/\s{2,}/u', ' ', $s), " \t·");
    return $s !== '' ? $s : trim($label);
};
/* What one passenger paid — the booking total split across every seat on the
   whole booking (both legs of a round trip). */
$farePer = static function (array $r) use ($paxTotalPerBooking): float {
    $n = max(1, (int) ($paxTotalPerBooking[(int) ($r['booking_id'] ?? 0)] ?? 1));
    return round((float) $r['total_amount'] / $n);
};
/* Compact "who sold this seat" for the printed waybill: the seller's SHG
   code, or the channel when there is no numbered agent behind it.
   Ticket::issuedBy() is the one place that decides — the same call the
   ticket PNG and PDF print their AGENT CODE from, so the chalani row and
   the passenger's ticket can never name different sellers (10 Sep 2026).
   It used to say AGENT for every staff sale, which put an office booking
   in the agent column with no code to back it. The row carries agent_role
   so a numberless agent still reads AGENT and office staff read OFFICE. */
$bookedByShort = static function (array $r): string {
    if (!empty($r['sold_by_admin_id'])) {
        return Ticket::issuedBy($r)['code'];
    }
    $src = strtolower((string) ($r['source'] ?? 'web'));
    return $src === 'admin' ? 'OFFICE' : ($src === 'counter' ? 'COUNTER' : 'ONLINE');
};
/* This trip's departure moment, and per-row detection of a ticket cut AFTER
   the coach left (owner ask: a post-departure booking must be visible on the
   chalani). */
$depTs = ($trip !== null && ($trip['dep_time'] ?? '') !== '')
    ? strtotime((string) $date . ' ' . substr((string) $trip['dep_time'], 0, 8))
    : null;
$isLate = static function (array $r) use ($depTs): bool {
    if ($depTs === null || empty($r['created_at'])) { return false; }
    $ts = strtotime((string) $r['created_at']);
    return $ts !== false && $ts > $depTs;
};
/* Compact booking time — time only when booked on the travel day, otherwise
   "j M · H:i" so an earlier-day booking is unambiguous. */
$bookedTime = static function (array $r) use ($date): string {
    if (empty($r['created_at'])) { return ''; }
    $ts = strtotime((string) $r['created_at']);
    if ($ts === false) { return ''; }
    return date('Y-m-d', $ts) === $date ? date('H:i', $ts) : date('j M · H:i', $ts);
};
/* The AGENT column carries agents only. A company desk selling at the window
   is not an agent, and printing its name here made an office sale look like a
   commissionable one. */
$agentLabel = static function (array $r): string {
    if (empty($r['sold_by_admin_id'])) return '';
    if (Ticket::issuedBy($r)['kind'] !== 'agent') return '';
    $code = AgentWallet::agentCodeLabel((int) $r['sold_by_admin_id']);
    $name = (string) ($r['agent_name'] ?: $r['agent_user']);
    return trim(($code !== '' ? $code . ' ' : '') . $name);
};
$payLabel = static function (array $r): string {
    $s = (string) ($r['pay_status'] ?? '');
    if ($s === 'verified')    return 'PAID';
    if ($s === 'cod_pending') return 'COD — collect';
    if ($s === 'refunded')    return 'REFUNDED';
    if ($s === 'rejected')    return 'REJECTED';
    return 'PENDING';
};
$ticketLabel = static function (array $r): string {
    if (!empty($r['is_void']))     return 'VOID';
    if (empty($r['ticket_number'])) return 'not issued';
    return !empty($r['scanned_at']) ? 'BOARDED' : (string) $r['ticket_number'];
};

/* =====================================================================
 *  6 Nov 2026 (owner: "chalani ajhai simple — location wise thau ko number
 *  ra name, gadi name ra passenger kati chhan chhutyaera lekhdinu; single
 *  private sleeper women lai chhutyaera highlight; seat chart bichma khali
 *  gap rakhera"). ONE place builds, from the same $rows:
 *    $placeList   pickup places in the route's own order, numbered 1..n,
 *                 with head-count and seats (and $dropList for get-off);
 *    $seatInfo    physical bed => who is on it (name, place no., woman?);
 *    $isWomanSingle  a woman on a 1-berth (single) sleeper — highlighted on
 *                 the screen, the printed chalani and the seat chart.
 *  The screen and the print sheet both read these, so they cannot disagree.
 * ===================================================================== */
require_once INCLUDE_PATH . '/boarding.php';
$coachT   = (string) ($trip['coach_type'] ?? 'sleeper');
$layout   = $trip !== null ? Seats::layoutFor($coachT, 'sharing') : ['decks' => []];
$singleBeds = [];
if ($coachT === 'sleeper') {
    foreach ($layout['decks'] as $dk) {
        foreach ($dk['rows'] as $rw) {
            foreach ($rw['right'] as $bed) { $singleBeds[$bed] = true; }
        }
    }
}
$physOf = static function (array $r) use ($coachT): array {
    try {
        return Seats::physicalSeats((string) $r['seat_no'], (string) (($r['booking_mode'] ?? '') ?: 'sharing'), $coachT);
    } catch (Throwable $ex) {
        return [strtoupper((string) $r['seat_no'])];
    }
};
$isWoman = static fn(array $r): bool => in_array(strtolower(trim((string) ($r['gender'] ?? ''))), ['female', 'f', 'woman', 'mahila'], true);
$isWomanSingle = static function (array $r) use ($isWoman, $physOf, $singleBeds): bool {
    if (!$isWoman($r) || $singleBeds === []) { return false; }
    foreach ($physOf($r) as $bed) { if (isset($singleBeds[$bed])) { return true; } }
    return false;
};
$seatLbl = static fn(array $r): string => Seats::displayLabel((string) $r['seat_no'], $coachT, (string) (($r['booking_mode'] ?? '') ?: 'sharing'));

/* place number = the route's own pickup order (Admin → Stops), so "1" is
   always the first pickup and the crew reads the same numbers every trip */
$routeStops = [];
if ($trip !== null) {
    try { $routeStops = Boarding::allStopsFor((int) $trip['route_id'], 'boarding'); } catch (Throwable $ex) { $routeStops = []; }
}
$placeNoOf = static function (string $stop) use ($routeStops): int {
    $s = mb_strtolower(trim($stop));
    foreach (array_values($routeStops) as $i => $st) {
        $n = mb_strtolower(trim((string) ($st['name'] ?? '')));
        if ($n !== '' && ($s === $n || str_starts_with($s, $n) || str_starts_with($n, $s))) { return $i + 1; }
    }
    return 0;
};
$placeList = [];   // key => [no, name, n, women, seats[]]
$dropList  = [];
foreach ($rows as $r) {
    $pk = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—';
    $no = $placeNoOf($pk);
    if (!isset($placeList[$pk])) { $placeList[$pk] = ['no' => $no, 'name' => $pk, 'n' => 0, 'women' => 0, 'seats' => []]; }
    $placeList[$pk]['n']++;
    if ($isWoman($r)) { $placeList[$pk]['women']++; }
    $placeList[$pk]['seats'][] = $seatLbl($r);
    $dk = $stopShort((string) (($r['drop_stop'] ?? '') ?: ($trip['to_city'] ?? '—')));
    if (!isset($dropList[$dk])) { $dropList[$dk] = ['name' => $dk, 'n' => 0]; }
    $dropList[$dk]['n']++;
}
// unknown places (0) go after the numbered ones, keeping first-seen order
$nextNo = count($routeStops);
foreach ($placeList as $k => $p) {
    if ($p['no'] === 0) { $placeList[$k]['no'] = ++$nextNo; }
}
uasort($placeList, static fn(array $a, array $b): int => $a['no'] <=> $b['no']);
$womenSingleCount = count(array_filter($rows, $isWomanSingle));

$seatInfo = [];   // physical bed => info
foreach ($rows as $r) {
    $pk = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—';
    foreach ($physOf($r) as $bed) {
        $seatInfo[$bed] = [
            'name'  => (string) $r['full_name'],
            'place' => (int) ($placeList[$pk]['no'] ?? 0),
            'stop'  => $pk,
            'woman' => $isWoman($r),
            'ws'    => $isWomanSingle($r),
            'pnr'   => (string) $r['pnr'],
            'paid'  => ($r['pay_status'] ?? '') === 'verified',
            'state' => $mfState[(int) ($r['booking_id'] ?? 0)] ?? 'due',
        ];
    }
}

/* The seat chart — lower | upper floor, "DOUBLE · 2 BERTH | AISLE gap |
   SINGLE · 1 BERTH", the same picture the customer books on. Self-styled
   (scx-*) so the screen and the print sheet can both drop it in. */
$seatChartHtml = static function () use ($layout, $seatInfo, $coachT): string {
    if (($layout['decks'] ?? []) === []) { return ''; }
    $e  = static fn($v): string => Security::e((string) $v);
    $sglFirst = ($layout['singleSide'] ?? '') === 'left';
    $sleeper = $coachT === 'sleeper';
    $cell = static function (string $bed) use ($seatInfo, $coachT, $e): string {
        $lbl = Seats::displayLabel($bed, $coachT, 'sharing');
        $i   = $seatInfo[$bed] ?? null;
        if ($i === null) {
            return '<div class="scx-s scx-free"><b>' . $e($lbl) . '</b></div>';
        }
        $cls = 'scx-s scx-busy' . ($i['woman'] ? ' scx-w' : '') . ($i['ws'] ? ' scx-ws' : '') . ' scx-m-' . ($i['state'] ?? 'due');
        $first = mb_substr(trim(explode(' ', trim($i['name']))[0] ?? ''), 0, 9);
        return '<div class="' . $cls . '" title="' . $e($i['name'] . ' · ' . $i['stop'] . ' · ' . $i['pnr']) . '">'
             . '<b>' . $e($lbl) . '</b><span>' . $e($first) . '</span>'
             . '<i>' . ($i['ws'] ? '♀ ' : '') . ($i['place'] > 0 ? '📍' . (int) $i['place'] : '') . '</i></div>';
    };
    $h = '<style>
.scx{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:14px;margin:10px 0}
.scx-deck{border:1px solid #DCE3EE;border-radius:14px;padding:10px 10px 12px;background:#fff;break-inside:avoid}
.scx-deck h4{margin:0 0 8px;font-size:15px;color:#12264E}
.scx-hd,.scx-row{display:grid;grid-template-columns:' . ($sglFirst ? 'minmax(0,1fr) 34px minmax(0,2fr)' : 'minmax(0,2fr) 34px minmax(0,1fr)') . ';gap:0;align-items:stretch}
.scx-hd span{background:#E4E8EF;border-radius:8px;font-size:10.5px;font-weight:800;text-align:center;padding:5px 2px;color:#16233C;letter-spacing:.3px}
.scx-row{margin-top:7px}
.scx-g{display:grid;gap:4px;border:1.5px solid #B9C3D3;border-radius:10px;padding:4px;background:#F7F9FC}
.scx-g.double{grid-template-columns:repeat(4,minmax(0,1fr))}
.scx-g.single,.scx-g.seat{grid-template-columns:repeat(2,minmax(0,1fr))}
.scx-a{display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#8A95A8;writing-mode:vertical-rl;letter-spacing:2px}
.scx-s{border:1.5px solid #9FD3AE;background:#EAF7EE;border-radius:8px;min-height:44px;padding:3px 2px;text-align:center;line-height:1.15;overflow:hidden}
.scx-s b{display:block;font-size:12px;color:#14532D}
.scx-s span{display:block;font-size:10px;font-weight:700;color:#16233C;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.scx-s i{display:block;font-style:normal;font-size:9.5px;font-weight:800;color:#0B3D91}
.scx-busy{background:#DCE8FB;border-color:#6E95D8}.scx-busy b{color:#0B3D91}
.scx-w{border-color:#E06AA8;background:#FDE7F2}.scx-w b{color:#9D1C5E}
.scx-ws{border:2.5px solid #C2185B;background:#F8BBD9;box-shadow:0 0 0 2px #FCE4EC}.scx-ws b,.scx-ws i{color:#880E4F}
.scx-leg{display:flex;flex-wrap:wrap;gap:10px;font-size:11.5px;font-weight:700;color:#3B4A66;margin:4px 0 0}
.scx-m-paid{box-shadow:inset 0 -4px 0 #16A34A}.scx-m-agent{box-shadow:inset 0 -4px 0 #F59E0B}.scx-m-due{box-shadow:inset 0 -4px 0 #DC2626}
.scx-ex{display:flex;flex-wrap:wrap;gap:4px}.scx-ex .scx-s{min-width:52px}
.scx-leg em{display:inline-block;width:14px;height:12px;border-radius:3px;vertical-align:-2px;margin-right:4px;font-style:normal}
.scx *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
</style><div class="scx">';
    foreach ($layout['decks'] as $dk) {
        $h .= '<div class="scx-deck"><h4>' . $e($dk['label']) . '</h4>';
        if ($sleeper) {
            $hs = ['single' => 'SINGLE SLEEPER · 1 BERTH', 'double' => 'DOUBLE SLEEPER · 2 BERTHS'];
            [$k1, $k2] = $sglFirst ? ['single', 'double'] : ['double', 'single'];
            $h .= '<div class="scx-hd"><span>' . $hs[$k1] . '</span><span style="background:none"></span><span>' . $hs[$k2] . '</span></div>';
        }
        foreach ($dk['rows'] as $ri => $rw) {
            [$g1, $g2] = Seats::rowGroups($rw, $layout);   // 6 Nov 2026: single column drawn first
            $h .= '<div class="scx-row"><div class="scx-g ' . $g1[1] . '">';
            foreach ($g1[0] as $bed) { $h .= $cell($bed); }
            $h .= '</div><div class="scx-a">' . ($ri === intdiv(count($dk['rows']), 2) ? 'AISLE' : '') . '</div><div class="scx-g ' . $g2[1] . '">';
            foreach ($g2[0] as $bed) { $h .= $cell($bed); }
            $h .= '</div></div>';
        }
        $h .= '</div>';
    }
    /* 6 Nov 2026 (owner: "extra seat ... chalani dekhi har ek thau ma entry hos"): the
       extra seats sold on this bus, as their own block, beside the berths. */
    $exBeds = array_values(array_filter(array_keys($seatInfo), static fn($b): bool => Seats::isExtraSeat((string) $b)));
    if ($exBeds !== []) {
        usort($exBeds, static fn($a, $b): int => (int) substr((string) $a, 2) <=> (int) substr((string) $b, 2));
        $h .= '<div class="scx-deck"><h4>Extra seats · थप सिट (' . count($exBeds) . ')</h4><div class="scx-ex">';
        foreach ($exBeds as $bed) { $h .= $cell((string) $bed); }
        $h .= '</div></div>';
    }
    $h .= '</div><div class="scx-leg"><span><em style="background:#EAF7EE;border:1px solid #9FD3AE"></em>खाली · Free</span>'
        . '<span><em style="background:#DCE8FB;border:1px solid #6E95D8"></em>बुक · Booked</span>'
        . '<span><em style="background:#FDE7F2;border:1px solid #E06AA8"></em>महिला · Woman</span>'
        . '<span><em style="background:#F8BBD9;border:2px solid #C2185B"></em>♀ महिला सिंगल स्लिपर · Woman on single sleeper</span>'
        . '<span>📍N = चढ्ने ठाउँ नं. · pickup place no.</span>'
        . '<span><em style="background:#fff;box-shadow:inset 0 -4px 0 #16A34A;border:1px solid #ccc"></em>भुक्तानी · Paid</span>'
        . '<span><em style="background:#fff;box-shadow:inset 0 -4px 0 #F59E0B;border:1px solid #ccc"></em>एजेन्टसँग · With agent</span>'
        . '<span><em style="background:#fff;box-shadow:inset 0 -4px 0 #DC2626;border:1px solid #ccc"></em>बाँकी · Due</span></div>';
    return $h;
};

/* =====================================================================
 *  CSV — streamed before any admin chrome is printed.
 * ===================================================================== */
if ($format === 'csv') {
    $name = 'manifest-' . $date . '-' . ($trip['route_code'] ?? 'none') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");     // BOM so Excel reads the Devanagari names

    csv_put($out, ['Manifest', ($trip['from_city'] ?? '') . ' -> ' . ($trip['to_city'] ?? '')]);
    csv_put($out, ['Travel date', $date]);
    csv_put($out, ['Departure', substr((string) ($trip['dep_time'] ?? ''), 0, 5)]);
    csv_put($out, ['Bus number', (string) ($trip['bus_number'] ?? 'not assigned')]);
    csv_put($out, ['Passengers', (string) count($rows)]);
    csv_put($out, []);
    csv_put($out, ['Seat', 'Passenger', 'Age', 'Gender', 'Phone', 'Boarding point',
                   'Destination', 'Fare', 'Booking ID', 'Payment', 'Source', 'Agent', 'Ticket', 'Booked']);

    foreach ($rows as $r) {
        csv_put($out, [
            Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing')),
            $r['full_name'], $r['age'], $r['gender'], $r['contact_phone'],
            $stopShort((string) $r['boarding_stop']), $stopShort((string) $r['drop_stop']), $farePer($r), $r['pnr'],
            $payLabel($r), $sourceLabel($r), $agentLabel($r), $ticketLabel($r),
            $bookedTime($r) . ($isLate($r) ? ' (after departure)' : ''),
        ]);
    }
    fclose($out);

    Logger::audit('manifest.export', 'schedule', (string) ($trip['schedule_id'] ?? 0),
        null, null, count($rows) . ' passengers, ' . $date);
    exit;
}

/* =====================================================================
 *  PDF — downloadable manifest, uses the ReportPdf class.
 *  Master prompt §13.
 * ===================================================================== */
if ($format === 'pdf') {
    if ($trip === null) {
        exit('No departure on this date.');
    }
    /* 17 Sep 2026: ONE PDF, not two. The English ReportPdf manifest
       duplicated the chalani PDF (and printed the route time, not a retimed
       departure). Old bookmarks land on the chalani PDF instead. */
    Logger::audit('manifest.pdf_redirect', 'schedule', (string) $trip['schedule_id'], null, null, 'format=pdf -> chalanipdf');
    Response::redirect('/admin/manifest.php?' . http_build_query(array_filter([
        'date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null, 'format' => 'chalanipdf',
    ])));
}

/* =====================================================================
 *  BUS CHALANI — the official waybill, exactly as the office's printed
 *  form reads (owner's PDF, 27 Aug 2026). One page per departure: the
 *  crew fields on top, the passenger table, the cash line and the three
 *  signatures. Data fills what the system knows; the rest stays as
 *  dotted blanks for the pen, same as the paper form.
 * ===================================================================== */
if ($format === 'chalani' || $format === 'chalanipdf' || $format === 'chalanipng') {
    if ($trip === null) {
        exit('No departure on this date.');
    }
    /* 17 Sep 2026: an agent login sees only its own sales, so a chalani
       drawn from its rows would be an official sheet missing half the bus.
       31 Oct 2026 (owner: "agent, staff counter mode bata ... chalani herna
       milos"): the agent gets an AGENT COPY instead — the same sheet, only
       the passengers it sold, its number ending -A<code> and the page saying
       so, never mistaken for the office's whole-bus chalani. Setting
       agent_chalani_on (default on) brings back the old refusal. */
    $agentCopy = $scopeId !== null;
    if ($agentCopy && !Settings::getBool('agent_chalani_on', true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('The chalani lists every seat on the bus. An agent login sees only its own sales — use My Passengers.');
    }

    // Same number the challan picture prints: an extra bus carries its slot.
    $chalaniNo = 'CH-' . str_replace('-', '', $date) . '-' . strtoupper((string) $trip['route_code'])
               . ((int) ($trip['slot'] ?? 1) > 1 ? '-' . (int) $trip['slot'] : '');
    if ($agentCopy) {
        $chalaniNo .= '-A' . (AgentWallet::agentCodeFor((int) $scopeId) ?? (int) $scopeId);
    }
    $collected = 0.0;
    $methods   = [];
    $seen      = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['pnr']])) continue;
        $seen[$r['pnr']] = true;
        if (($r['pay_status'] ?? '') === 'verified') {
            $collected += (float) $r['total_amount'];
            $m = strtoupper((string) ($r['pay_method'] ?? ''));
            if ($m !== '') { $methods[$m === 'COD' ? 'CASH' : $m] = true; }
        }
    }
    $blankRows = max(16, count($rows) + 2);

    // Company logo — embedded as a data URI so it survives the print dialog
    // (an external <img src> is frequently dropped when a browser prints to
    // PDF). An admin-set company_logo path wins; otherwise the site logo.
    /* 9 Oct 2026: the transparent EMBLEM first (no baked name, no white tile —
       the company name is printed beside it); the old full logo only when the
       emblem file is missing. The 96px-tall copy keeps the data URI small. */
    $logoData = '';
    foreach ([Settings::getString('company_logo_mark', ''), 'assets/img/logo-mark-96h-tm.png', 'assets/img/logo-mark-tm.png',
              Settings::getString('company_logo', ''), 'assets/img/logo-tm.png'] as $cand) {
        if ($cand === '' || preg_match('#^https?://#i', $cand) === 1) { continue; }
        $lp = dirname(__DIR__) . '/' . ltrim($cand, '/');
        if (is_file($lp) && filesize($lp) > 0 && filesize($lp) < 600 * 1024) {
            $ext  = strtolower(pathinfo($lp, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg'
                  : ($ext === 'webp' ? 'image/webp' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png'));
            $logoData = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($lp));
            break;
        }
    }

    $e = static fn($v) => Security::e((string) $v);

    /* Inline SVG flags for the print band (9 Oct 2026) — the 🇮🇳 / 🇳🇵 emoji
       printed as the letters "IN" / "NP" on Windows. Explicit fills, so they
       print in colour and need no stylesheet. */
    $flagSvg = static function (string $cc, int $h = 14): string {
        if (class_exists('Brand') && method_exists('Brand', 'flagSvg')) {
            try { $s = (string) Brand::flagSvg($cc, $h); if ($s !== '') { return $s; } } catch (Throwable $ex) {}
        }
        if ($cc === 'IN') {
            return '<svg class="flg" width="' . (int) round($h * 1.5) . '" height="' . $h . '" viewBox="0 0 30 20" aria-label="India"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#FF9933"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.6" fill="none" stroke="#000080" stroke-width=".7"/><circle cx="15" cy="10" r=".6" fill="#000080"/></svg>';
        }
        return '<svg class="flg" width="' . (int) round($h * 0.78) . '" height="' . $h . '" viewBox="0 0 26 32" aria-label="Nepal"><path d="M2 2 23 15H9.6L23 30H2Z" fill="#DC143C" stroke="#003893" stroke-width="2.2"/><circle cx="8" cy="11" r="2.4" fill="#fff"/><circle cx="8" cy="23" r="3" fill="#fff"/></svg>';
    };

    /* =================================================================
       Passenger sheet layout (owner's reference design, 6 Sep 2026):
       landscape, Nepali labels, eleven columns, at least 25 ruled rows,
       payment summary + authorisation boxes. Rows the system knows are
       filled first; the rest stay as clean empty boxes for the pen — so
       the same sheet works before, during and after ticket entry.

       Labels are Nepali; proper nouns, codes (CIN, route code, ticket
       and phone numbers, SHG codes), stop names as stored and passenger
       names as typed stay as they are — the same rule the ticket follows.
       ================================================================= */
    require_once INCLUDE_PATH . '/boarding.php';

    $neDigits = static fn(string $s): string => strtr($s, ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
    $neMonths = ['जनवरी','फेब्रुअरी','मार्च','अप्रिल','मे','जुन','जुलाई','अगस्ट','सेप्टेम्बर','अक्टोबर','नोभेम्बर','डिसेम्बर'];
    $neDays   = ['आइतबार','सोमबार','मङ्गलबार','बुधबार','बिहीबार','शुक्रबार','शनिबार'];
    $neDate   = static function (string $iso) use ($neDigits, $neMonths, $neDays): string {
        $ts = strtotime($iso);
        if ($ts === false) { return $iso; }
        return $neDigits((string) (int) date('j', $ts)) . ' ' . $neMonths[(int) date('n', $ts) - 1] . ' ' . $neDigits(date('Y', $ts)) . ' (' . $neDays[(int) date('w', $ts)] . ')';
    };
    $neTime  = static fn(?string $t): string => ($t === null || $t === '') ? '' : $neDigits(substr((string) $t, 0, 5));
    $coachNe = static function (string $c): string {
        $c = strtolower(trim($c));
        return $c === 'sleeper' ? 'स्लिपर कोच' : ($c === 'seater' ? 'सिटर कोच' : ($c === 'semi' ? 'सेमी-स्लिपर' : ucfirst($c)));
    };
    /* $stopShort is defined once, in the shared formatting block above — the
       chalani, the screen and the CSV all read the same rule. */

    /* Money per ROW, one rule shared by this page and the PDF:
       ticket = this passenger's share of the booking; it lands in the
       नगद column when the verified payment was cash / COD, in अनलाइन for
       UPI / eSewa / bank / wallet, and in neither while still unpaid. */
    $money = static function (array $r) use ($farePer): array {
        $fare   = (float) $farePer($r);
        $paid   = (($r['pay_status'] ?? '') === 'verified');
        $method = strtolower((string) ($r['pay_method'] ?? ''));
        $cash   = $paid && in_array($method, ['cash', 'cod'], true) ? $fare : 0.0;
        $online = $paid && in_array($method, ['upi', 'esewa', 'bank', 'wallet'], true) ? $fare : 0.0;
        return ['ticket' => $fare, 'cash' => $cash, 'online' => $online];
    };
    $tTicket = 0.0; $tCash = 0.0; $tOnline = 0.0; $tDisc = 0.0; $seenB = [];
    foreach ($rows as $r) {
        $m = $money($r);
        $tTicket += $m['ticket']; $tCash += $m['cash']; $tOnline += $m['online'];
        $bid = (int) ($r['booking_id'] ?? 0);
        if ($bid > 0 && !isset($seenB[$bid])) {
            $seenB[$bid] = true;
            // base_total is the undiscounted price of the whole booking;
            // whatever was knocked off it (group / tier / coupon / counter)
            // is the concession.
            //
            // NOT $base (10 Sep 2026): that is the page-level URL prefix set
            // at the top of this file, and this loop was overwriting it with
            // a fare — which is why the chalani sheet's PDF link came out as
            // "2000/admin/manifest.php?..." and 404'd.
            $baseAmt = (float) ($r['base_total'] ?? 0);
            if ($baseAmt > 0) { $tDisc += max(0.0, $baseAmt - (float) ($r['total_amount'] ?? 0)); }
        }
    }
    $totals = [
        'passengers' => count($rows), 'ticket' => $tTicket, 'cash' => $tCash,
        'online' => $tOnline, 'discount' => $tDisc, 'net' => $tCash + $tOnline,
        'chalaniNo' => $chalaniNo,
    ];

    /* Boarding points line, from the route's own stop rows. Built twice: the
       Nepali-digit form the print sheet and the PDF use, and a Roman-digit
       twin for the PNG pages — GD cannot draw Devanagari, and romanising the
       Nepali one left "Surat (:)" where the time should be. */
    $stops = [];
    try { $stops = Boarding::stopsFor((int) $trip['route_id']); } catch (Throwable $ex) { $stops = []; }
    $stopsLine = '';
    $stopsLineRoman = '';
    if ($stops !== []) {
        $parts = [];
        $partsRoman = [];
        foreach ($stops as $st) {
            $nm = trim((string) $st['name']);
            $tm = $st['time'] !== null ? substr((string) $st['time'], 0, 5) : '';
            $parts[]      = $nm . ($tm !== '' ? ' (' . $neTime((string) $st['time']) . ')' : '');
            $partsRoman[] = $nm . ($tm !== '' ? ' (' . $tm . ')' : '');
        }
        $stopsLine      = implode(' · ', $parts);
        $stopsLineRoman = implode(' · ', $partsRoman);
    }

    /* The rows, the money rule and the boarding line, packed once. The PDF
       and the PNG pages both read THIS array, so a fare can never print one
       way on paper and another in the picture. */
    $chalaniFmt = ['money' => $money, 'stopShort' => $stopShort, 'isLate' => $isLate,
                   'bookedBy' => $bookedByShort, 'stopsLine' => $stopsLine,
                   'stopsLineRoman' => $stopsLineRoman,
                   /* 6 Nov 2026: the place list, women on a single sleeper and the
                      seat chart — the PDF and the PNG pages draw the same picture
                      as the print sheet (ChalaniPdf / ChalaniPng read these). */
                   'placeList' => array_values($placeList), 'placeNo' => static fn(string $stop): int => (int) ($placeList[$stop]['no'] ?? 0),
                   'womanSingle' => $isWomanSingle, 'womenSingleCount' => $womenSingleCount,
                   'seatInfo' => $seatInfo, 'layout' => $layout,
                   'busName' => trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? ''))];

    /* Where the three chalani surfaces live. Defined HERE, before any of
       the branches: the PNG picker links back to the PDF, so leaving these
       below the branches left $pdfHref undefined inside the picker — which
       under the strict error handler is a fatal, mid-page. */
    $chalaniLink = static function (array $extra) use ($base, $date, $routeId, $sidReq): string {
        return $base . '/admin/manifest.php?' . http_build_query(array_filter(array_merge(
            ['date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null],
            $extra
        )));
    };
    $pdfHref      = $chalaniLink(['format' => 'chalanipdf']);
    $pngHrefSheet = $chalaniLink(['format' => 'chalanipng']);
    $pngHref      = static fn(int $p, bool $dl): string =>
        $chalaniLink(['format' => 'chalanipng', 'page' => $p, 'dl' => $dl ? 1 : null]);

    /* Downloadable PDF of THIS same data — one code path for the rows and
       the money rule, so the print view and the PDF can never disagree. */
    if ($format === 'chalanipdf') {
        require_once INCLUDE_PATH . '/chalanipdf.php';
        Logger::audit('manifest.chalanipdf', 'schedule', (string) $trip['schedule_id'], null, null, count($rows) . ' passengers, ' . $date);
        ChalaniPdf::send($trip, $rows, $date, $totals, $chalaniFmt);
    }

    /* =================================================================
     *  CHALANI AS PICTURE PAGES (owner ask, 10 Sep 2026)
     *
     *  ?format=chalanipng            -> the page picker (every page, each
     *                                   with its own download button)
     *  &page=N                       -> that page inline
     *  &page=N&dl=1                  -> that page as a download
     *
     *  A scoped agent login is refused the same way admin/challan.php
     *  refuses it: the chalani lists every berth on the bus, and an agent
     *  session is scoped precisely so it reads only its own sales.
     * ================================================================= */
    if ($format === 'chalanipng') {
        require_once INCLUDE_PATH . '/chalanipng.php';   // an agent: its AGENT COPY (see above)

        $sheetPages = ChalaniPng::pageCount($rows);
        $hasChart   = ($layout['decks'] ?? []) !== [];      // 6 Nov 2026: + the seat-chart page
        $pageCount  = $sheetPages + ($hasChart ? 1 : 0);
        $pngOne = static fn(int $p): array => ($hasChart && $p === $pageCount)
            ? ChalaniPng::renderChart($trip, $rows, $date, $totals, $chalaniFmt, $p, $pageCount)
            : ChalaniPng::render($trip, $rows, $date, $totals, $chalaniFmt, $p);
        $wanted    = (int) ($_GET['page'] ?? 0);

        if ($wanted >= 1) {
            if ($wanted > $pageCount) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                exit('This chalani has ' . $pageCount . ' page' . ($pageCount === 1 ? '' : 's') . '.');
            }
            try {
                $res = $pngOne($wanted);
            } catch (Throwable $ex) {
                Logger::error('Chalani PNG failed: ' . $ex->getMessage(), ['sid' => $trip['schedule_id'] ?? 0, 'page' => $wanted]);
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                exit('The chalani page could not be drawn: ' . $ex->getMessage());
            }
            Logger::audit('manifest.chalanipng', 'schedule', (string) $trip['schedule_id'], null,
                ['file' => $res['file'], 'page' => $wanted, 'pages' => $pageCount, 'fresh' => $res['fresh']],
                (isset($_GET['dl']) ? 'downloaded' : 'viewed') . ' page ' . $wanted . ' of ' . $pageCount . ', ' . $date);
            if (isset($_GET['dl'])) {
                Response::download($res['path'], $res['file'], 'image/png');
            }
            Response::inline($res['path'], 'image/png');
        }

        /* No page asked for → the picker. Every page is rendered up front so
           the thumbnails are the real thing, not a promise. */
        $pngPages = [];
        $pngError = '';
        for ($p = 1; $p <= $pageCount; $p++) {
            try {
                $pngPages[] = $pngOne($p);
            } catch (Throwable $ex) {
                $pngError = $ex->getMessage();
                Logger::error('Chalani PNG failed: ' . $ex->getMessage(), ['sid' => $trip['schedule_id'] ?? 0, 'page' => $p]);
                break;
            }
        }
        Logger::audit('manifest.chalanipng', 'schedule', (string) $trip['schedule_id'], null,
            ['pages' => $pageCount], 'page list opened, ' . count($rows) . ' passengers, ' . $date);

        require __DIR__ . '/_chalani-png-view.php';
        exit;
    }

    $minRows  = 25;
    $sheetRows = max($minRows, count($rows));
    /* The letterhead, from ONE place (10 Sep 2026): the address, the
       operator's name, the office email, the CIN and the counter numbers
       used to be typed into this page, into ChalaniPdf and into the PNG
       pages separately. Settings::company() is now the only copy. */
    $co       = Settings::company();
    $cin      = $co['cin'];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html><html lang="ne"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>बस चलानी · <?= $e($date) ?> · <?= $e($trip['route_code']) ?></title>
<style>
  @page { size: A4 landscape; margin: 7mm; }
  * { box-sizing:border-box; }
  body { font-family:'Noto Sans Devanagari','Mangal',Arial,sans-serif; color:#16233C; margin:0; padding:0; font-size:11.5px; background:#F6F8FC; }
  .sheet { width:1123px; max-width:100%; margin:0 auto; background:#fff; padding:14px 18px 12px; }
  .no-print { display:flex; gap:8px; justify-content:flex-end; margin-bottom:8px; }
  .no-print a, .no-print button { padding:8px 18px; font-size:13px; cursor:pointer; border-radius:10px; border:1px solid #C9D6EA; background:#fff; color:#12264E; text-decoration:none; font-family:inherit; }
  .no-print .pri { background:#12264E; color:#fff; border-color:#12264E; }

  /* header, as the reference: logo + company block left, sheet block right */
  .hd { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2.5px solid #12264E; padding-bottom:7px; }
  .hd .co { display:flex; gap:12px; align-items:flex-start; }
  .hd .co img { height:54px; width:auto; }
  .hd h1 { margin:0 0 2px; font-size:19px; color:#12264E; letter-spacing:.2px; }
  .hd .co p { margin:1px 0; font-size:10.3px; color:#3B4A66; }
  .hd .co p b { color:#12264E; }
  .hd .sh { text-align:right; min-width:270px; }
  .hd .sh h2 { margin:0; font-size:17px; color:#12264E; letter-spacing:.6px; }
  .hd .sh .rt { font-size:10.5px; color:#3B4A66; margin:1px 0 6px; }
  .hd .sh .kv { font-size:10.5px; margin:3px 0; }
  .hd .sh .kv span { display:inline-block; min-width:150px; border-bottom:1px solid #16233C; text-align:left; padding:0 4px; font-weight:600; }

  /* four framed fields */
  .boxes { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin:9px 0 6px; }
  .boxes div { border:1.5px solid #12264E; border-radius:6px; padding:5px 8px 7px; min-height:40px; }
  .boxes small { display:block; font-size:9px; letter-spacing:.5px; color:#3B4A66; margin-bottom:3px; }
  .boxes b { font-size:12.5px; font-weight:600; display:block; border-bottom:1px solid #8090A8; min-height:16px; }

  .stops { font-size:9.6px; color:#3B4A66; margin:0 0 5px; line-height:1.4; }
  .stops b { color:#12264E; }
  .band { display:flex; justify-content:space-between; font-size:10.5px; color:#12264E; font-weight:600; margin:4px 0 5px; }
  .band .flg { vertical-align:-2px; margin:0 2px; border-radius:1px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }

  table.ps { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.ps th, table.ps td { border:1px solid #16233C; padding:2px 5px; font-size:10.5px; height:19px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
  table.ps th { background:#EEF2F8; font-weight:600; text-align:left; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.ps th.c, table.ps td.c { text-align:center; } table.ps th.r, table.ps td.r { text-align:right; }
  table.ps td.n { color:#5C6B85; }
  table.ps td small { color:#5C6B85; font-size:8.5px; }
  .late { color:#8a1111; font-weight:700; }
  /* 6 Nov 2026: place strip, place numbers, women on a single sleeper */
  .pl { border:1.5px solid #12264E; border-radius:6px; padding:5px 9px; margin:0 0 6px; }
  .pl-bus { font-size:12.5px; color:#12264E; margin-bottom:3px; }
  .pl-ws { background:#F8BBD9; color:#880E4F; font-weight:800; padding:0 7px; border-radius:9px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .pl-list { display:flex; flex-wrap:wrap; gap:4px 14px; font-size:11px; }
  .pl-list i, i.pno { font-style:normal; display:inline-block; min-width:16px; height:16px; line-height:16px; text-align:center; border-radius:50%; background:#12264E; color:#fff; font-size:9.5px; font-weight:800; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.ps tr.ws td { background:#FCE4EC; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .wsb { color:#C2185B; }
  .chartpg { break-before:page; page-break-before:always; padding-top:6px; }
  .chartpg h2 { margin:0 0 4px; font-size:16px; color:#12264E; }

  .bottom { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:10px; margin-top:9px; }
  .box { border:1.5px solid #12264E; border-radius:6px; padding:7px 10px 8px; }
  .box h3 { margin:0 0 6px; font-size:10.5px; letter-spacing:.6px; color:#12264E; }
  .sum { display:grid; grid-template-columns:minmax(0,1fr) 120px; gap:3px 10px; font-size:10.5px; align-items:baseline; }
  .sum span { color:#3B4A66; }
  .sum b { border-bottom:1px solid #16233C; text-align:right; font-weight:600; padding:0 4px; min-height:14px; }
  .sum .net span, .sum .net b { font-weight:700; color:#12264E; }
  .auth { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:26px 24px; font-size:10px; text-align:center; margin-top:22px; }
  .auth div { border-top:1px solid #16233C; padding-top:4px; color:#3B4A66; }

  .ft { border-top:1.5px solid #12264E; margin-top:9px; padding-top:5px; font-size:9.4px; color:#3B4A66; display:flex; justify-content:space-between; gap:14px; }
  .ft b { color:#12264E; }
  @media print { .no-print { display:none; } body { background:#fff; } .sheet { width:auto; padding:0; } }
</style></head><body><div class="sheet">
<div class="no-print">
  <a class="pri" href="<?= $e($pdfHref) ?>">📄 PDF डाउनलोड</a>
  <a href="<?= $e($pngHrefSheet) ?>">🖼️ PNG पेज (छुट्टाछुट्टै)</a>
  <button type="button" onclick="window.print()">🖨️ छाप्नुहोस्</button>
</div>

<div class="hd">
  <div class="co">
    <?php if ($logoData !== ''): ?><img src="<?= $logoData ?>" alt=""><?php endif; ?>
    <div>
      <h1><?= $e($co['legalNe']) ?></h1>
      <p><?= $e($co['addressNe']) ?></p>
      <p><b>फोन :</b> <?= $e($co['phone']) ?> &nbsp;|&nbsp; <b>सञ्चालक :</b> <?= $e($co['operatorNe']) ?></p>
      <p><b>इमेल :</b> <?= $e($co['email']) ?> &nbsp;|&nbsp; <b>CIN :</b> <?= $e($cin) ?></p>
      <p><b>वेब :</b> <?= $e($co['web']) ?></p>
    </div>
  </div>
  <div class="sh">
    <h2>बस चलानी · यात्रु विवरण</h2>
    <?php if ($agentCopy): ?><div class="kv" style="color:#b45309;font-weight:800">एजेन्ट प्रति · AGENT COPY — <?= $e((string) ($admin['full_name'] ?? '')) ?> का यात्रु मात्र</div><?php endif; ?>
    <div class="rt">गुजरात ⇄ रुपैडिहा · <?= $e($coachNe((string) $trip['coach_type'])) ?></div>
    <div class="kv">चलानी नं. : <span><?= $e($chalaniNo) ?></span></div>
    <div class="kv">मिति : <span><?= $e($neDate($date)) ?></span></div>
    <div class="kv">बस नं. : <span><?= $e($trip['bus_number'] ?? '') ?>&nbsp;</span></div>
  </div>
</div>

<div class="boxes">
  <div><small>चढ्ने ठाउँ (FROM)</small><b><?= $e($trip['from_city']) ?></b></div>
  <div><small>गन्तव्य (TO)</small><b><?= $e($trip['to_city']) ?></b></div>
  <div><small>प्रस्थान समय</small><b><?= $e($neTime($trip['dep_time'] ?? '')) ?>&nbsp;</b></div>
  <div><small>चालक / कन्डक्टर</small><b><?= $e(trim((string) ($trip['driver_name'] ?? '') . (($trip['driver_phone'] ?? '') !== '' ? ' · ' . $trip['driver_phone'] : ''))) ?>&nbsp;</b></div>
</div>

<?php if ($stopsLine !== ''): ?>
<p class="stops"><b>चढ्ने ठाउँहरू :</b> <?= $e($stopsLine) ?> &nbsp;·&nbsp; <b>सीमा :</b> रुपैडिहा · जमुनाहा &nbsp;·&nbsp; <b>अगाडि :</b> नेपालगंज, बाँके</p>
<?php endif; ?>

<?php /* 6 Nov 2026: place-wise head-count, bus name and the women-on-single count, in one strip. */ ?>
<div class="pl">
  <div class="pl-bus">🚌 <b><?= $e(trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? ''))) ?: 'बस' ?></b>
    · जम्मा यात्रु <b><?= $e($neDigits((string) count($rows))) ?></b>
    <?php if ($womenSingleCount > 0): ?> · <span class="pl-ws">♀ महिला सिंगल <?= $e($neDigits((string) $womenSingleCount)) ?></span><?php endif; ?></div>
  <div class="pl-list">
    <?php foreach ($placeList as $pl): ?>
      <span><i><?= $e($neDigits((string) $pl['no'])) ?></i> <?= $e($pl['name']) ?> — <b><?= $e($neDigits((string) $pl['n'])) ?></b></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="band"><span><?= $flagSvg('IN') ?> गुजरात ⇄ रुपैडिहा <?= $flagSvg('NP', 15) ?> · यात्रु यात्रा अभिलेख</span><span>सिट · नाम · भुक्तानी विवरण</span></div>

<table class="ps">
  <colgroup>
    <col style="width:26px"><col style="width:44px"><col style="width:190px"><col style="width:92px">
    <col style="width:112px"><col style="width:112px"><col style="width:64px"><col style="width:64px">
    <col style="width:70px"><col style="width:52px"><col>
  </colgroup>
  <thead><tr>
    <th class="c">#</th><th class="c">सिट</th><th>यात्रुको नाम</th><th>मोबाइल नं.</th>
    <th>चढ्ने</th><th>ओर्लिने</th><th class="r">टिकट ₹</th><th class="r">नगद ₹</th><th class="r">अनलाइन ₹</th>
    <th class="c">प्रा.लि.</th><th>कैफियत</th>
  </tr></thead>
  <tbody>
  <?php for ($i = 0; $i < $sheetRows; $i++): $r = $rows[$i] ?? null; $m = $r ? $money($r) : null;
        $ws = $r !== null && $isWomanSingle($r); ?>
    <tr<?= $ws ? ' class="ws"' : '' ?>>
      <td class="c n"><?= $e($neDigits((string) ($i + 1))) ?></td>
      <td class="c"><b><?= $r ? $e(Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing'))) : '' ?></b></td>
      <td><?php if ($r): ?><?= $ws ? '<b class="wsb">♀</b> ' : '' ?><?= $e($r['full_name']) ?> <small><?= $e($r['ticket_number'] ?: $r['pnr']) ?><?php if ($isLate($r)): ?> · <span class="late">बस चलेपछि</span><?php endif; ?></small><?php endif; ?></td>
      <td><?= $r ? $e($r['contact_phone'] === '0000000000' ? '' : $r['contact_phone']) : '' ?></td>
      <td><?php if ($r): $pk = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—'; ?><i class="pno"><?= $e($neDigits((string) ($placeList[$pk]['no'] ?? ''))) ?></i> <?= $e($pk) ?><?php endif; ?></td>
      <td><?= $r ? $e($stopShort((string) $r['drop_stop'])) : '' ?></td>
      <td class="r"><?= $m ? $e(number_format($m['ticket'])) : '' ?></td>
      <td class="r"><?= ($m && $m['cash'] > 0) ? $e(number_format($m['cash'])) : '' ?></td>
      <td class="r"><?= ($m && $m['online'] > 0) ? $e(number_format($m['online'])) : '' ?></td>
      <td class="c"></td>
      <td><?= $r ? $e($bookedByShort($r) === 'ONLINE' ? 'अनलाइन' : ($bookedByShort($r) === 'OFFICE' ? 'कार्यालय' : ($bookedByShort($r) === 'COUNTER' ? 'काउन्टर' : $bookedByShort($r)))) : '' ?></td>
    </tr>
  <?php endfor; ?>
  </tbody>
</table>

<div class="bottom">
  <div class="box">
    <h3>भुक्तानी सारांश</h3>
    <div class="sum">
      <span>जम्मा यात्रु</span><b><?= $e($neDigits((string) $totals['passengers'])) ?></b>
      <span>जम्मा टिकट रकम</span><b>₹ <?= $e(number_format($totals['ticket'])) ?></b>
      <span>नगद संकलन</span><b>₹ <?= $e(number_format($totals['cash'])) ?></b>
      <span>अनलाइन / UPI प्राप्ति</span><b>₹ <?= $e(number_format($totals['online'])) ?></b>
      <span>छुट / सहुलियत</span><b>₹ <?= $e(number_format($totals['discount'])) ?></b>
      <span class="net">खुद जम्मा संकलन</span><b class="net">₹ <?= $e(number_format($totals['net'])) ?></b>
    </div>
  </div>
  <div class="box">
    <h3>प्रमाणीकरण · दस्तखत</h3>
    <div class="auth">
      <div>कन्डक्टरको सही</div><div>चालकको सही</div>
      <div>एजेन्ट / कार्यालय सही</div><div>प्रमाणित गर्ने (म्यानेजर)</div>
    </div>
  </div>
</div>

<div class="ft">
  <span><b>बुकिङ काउन्टर :</b> <?= $e($co['counters']) ?> &nbsp;·&nbsp; <b>२४×७ सहायता / WhatsApp :</b> +<?= $e($co['whatsapp']) ?></span>
</div>
<div class="ft" style="border-top:0;margin-top:3px;padding-top:0">
  <span>यो एस हरि ग्लोबल प्राइभेट लिमिटेडको आधिकारिक यात्रा कागजात हो। कम्पनी अभिलेखका लागि सुरक्षित राख्नुहोस्। अधिकृत सही / छापसँग मात्र मान्य। रुट : गुजरात ⇄ रुपैडिहा (भारत–नेपाल)। CIN <?= $e($cin) ?> · कर्पोरेट मामिला मन्त्रालय, भारत सरकार दर्ता</span>
  <span><b>shreehariglobal.in</b> · shreehariglobalpvtltd@gmail.com</span>
</div>
<?php /* 6 Nov 2026: page 2 — the seat chart with names, the aisle gap in the middle. */ ?>
<div class="chartpg">
  <h2>सिट चार्ट · Seat chart — <?= $e($chalaniNo) ?> · <?= $e(trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? ''))) ?> · यात्रु <?= $e($neDigits((string) count($rows))) ?></h2>
  <?= $seatChartHtml() ?>
</div>
</div></body></html>
    <?php
    Logger::audit('manifest.chalani', 'schedule', (string) $trip['schedule_id'],
        null, null, count($rows) . ' passengers, ' . $date);
    exit;
}

/* =====================================================================
 *  SCREEN
 * ===================================================================== */
admin_header('Chalani · Passenger manifest', 'manifest');

$paxCount = count($rows);
$boarded  = count(array_filter($rows, static fn($r) => !empty($r['boarded_at'])));
$unpaid   = count(array_filter($rows, static fn($r) => ($r['pay_status'] ?? '') !== 'verified'));
/* 6 Nov 2026: money on this bus by state — each passenger's share of their booking */
$mfMoney = ['paid' => 0.0, 'agent' => 0.0, 'due' => 0.0];
$mfCount = ['paid' => 0, 'agent' => 0, 'due' => 0];
foreach ($rows as $r) {
    $st = $mfState[(int) ($r['booking_id'] ?? 0)] ?? 'due';
    $mfMoney[$st] += $farePer($r);
    $mfCount[$st]++;
}
$revenue  = 0.0;
$seenPnr  = [];
foreach ($rows as $r) {
    if (isset($seenPnr[$r['pnr']])) continue;
    $seenPnr[$r['pnr']] = true;
    $revenue += (float) $r['total_amount'];
}
?>
<style>
  .mf-tools { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
  .mf-head  { border:1px solid var(--line,#dcdcdc); border-radius:10px; padding:12px 14px; margin-bottom:14px; }
  .mf-head h2 { margin:0 0 4px; font-size:19px; }
  .mf-stats { display:flex; gap:18px; flex-wrap:wrap; margin-top:8px; font-size:14px; }
  .mf-stats b { display:block; font-size:18px; }
  table.mf { width:100%; border-collapse:collapse; font-size:14px; }
  table.mf th, table.mf td { border-bottom:1px solid var(--line,#e4e4e4); padding:7px 8px; text-align:left; vertical-align:top; }
  table.mf th { background:var(--soft,#f4f6f8); font-weight:600; white-space:nowrap; }
  table.mf td.seat { font-weight:700; white-space:nowrap; }
  .mf-tag { display:inline-block; padding:1px 7px; border-radius:20px; font-size:12px; white-space:nowrap; }
  .mf-paid    { background:#d9f5e3; color:#0a6b33; }
  .mf-unpaid  { background:#fff4d1; color:#8a6d00; }
  .mf-empty   { padding:24px; text-align:center; opacity:.75; }
  tr.mf-stop td { background:#0b3d91; color:#fff; font-weight:800; font-size:15px; padding:8px 10px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  tr.mf-grp td  { background:#fff7e6; color:#3A2A00; border-top:2px solid #f0b44c; font-size:14px; padding:7px 10px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  tr.mf-grp a   { font-family:monospace; color:#0B3D91; }
  .mf-scroll  { overflow-x:auto; }
  /* 6 Nov 2026: simple place-wise chalani + women single */
  .mf-places { display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:12px; margin:0 0 12px; }
  @media (max-width:800px) { .mf-places { grid-template-columns:1fr; } }
  .mf-pl-card { border:1px solid var(--line,#dcdcdc); border-radius:12px; padding:10px 12px; background:var(--card,#fff); }
  .mf-pl-bus { display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; font-size:16px; margin-bottom:6px; }
  table.mf-pl { width:100%; border-collapse:collapse; font-size:14.5px; }
  table.mf-pl th, table.mf-pl td { padding:6px 6px; border-bottom:1px solid var(--line,#e4e4e4); text-align:left; }
  table.mf-pl .r { text-align:right; }
  table.mf-pl tfoot td { border-top:2px solid var(--line,#ccc); border-bottom:0; font-size:15px; }
  .mf-seats { font-size:12.5px; color:var(--mut,#555); }
  i.mf-no { font-style:normal; display:inline-block; min-width:26px; height:26px; line-height:26px; border-radius:50%; background:#0b3d91; color:#fff; text-align:center; font-weight:800; }
  .mf-wtag { background:#F8BBD9; color:#880E4F; border-radius:9px; padding:1px 6px; font-weight:800; }
  details.mf-chart { margin:0 0 14px; } details.mf-chart summary { cursor:pointer; font-weight:800; font-size:15px; padding:6px 0; }
  tr.mf-ws td { background:#FCE4EC !important; }
  /* 7 Nov 2026: professional skin (owner: "cartoon jasto bhayo") */
  .mf-tools { gap:8px; }
  .mf-tools .btn { display:inline-flex; align-items:center; gap:6px; min-height:36px; padding:0 12px; border-radius:8px; font-weight:600; font-size:13px; background:var(--card); color:var(--ink); border:1px solid var(--line); }
  .mf-tools .btn:hover { background:var(--hover); filter:none; }
  .mf-tools input, .mf-tools select { min-height:36px; border-radius:8px; border:1px solid var(--line); background:var(--card); color:var(--ink); padding:0 8px; }
  .mf-head { background:var(--card); border-radius:12px; padding:14px 16px; }
  .mf-head h2 { font-size:18px; font-weight:650; letter-spacing:-.01em; }
  .mf-stats { gap:22px; color:var(--mut); font-size:12.5px; }
  .mf-stats b { font-size:18px; font-weight:650; color:var(--ink); font-variant-numeric:tabular-nums; }
  .mf-money { display:flex; flex-wrap:wrap; gap:6px; margin:12px 0 0; }
  .mf-money .pc-badge { font-size:12.5px; padding:4px 10px; }
  .mf-link.no-print { border:1px solid var(--line); background:var(--card); color:var(--ink); border-radius:12px; }
  .mf-link.no-print input { border-color:var(--line); background:var(--bg); color:var(--ink); }
  .mf-link .muted { color:var(--mut); font-size:12.5px; }
  table.mf { font-size:13.5px; }
  table.mf th { background:var(--head); font-size:11px; font-weight:650; letter-spacing:.05em; text-transform:uppercase; color:var(--mut); }
  tr.mf-stop td { background:var(--head); color:var(--ink); font-size:13px; font-weight:650; border-left:3px solid var(--navy); padding:8px 10px; }
  .mf-no2 { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; border-radius:6px; background:var(--navy); color:#fff; font-size:12px; margin-right:6px; }
  tr.mf-grp td { background:var(--card); color:var(--ink); border-top:1px solid var(--line); font-size:13.5px; }
  tr.mf-grp a { font-family:inherit; }
  tr.mf-grp .mf-tag { margin-right:6px; }
  .mf-tag { border-radius:6px; font-weight:650; font-size:11.5px; padding:2px 8px; }
  .mf-tag.pay-paid { background:#ECFDF3; color:#15803D; border:1px solid #BBF0CF; }
  .mf-tag.pay-agent { background:#FFF7E8; color:#B45309; border:1px solid #F5D9A8; }
  .mf-tag.pay-due { background:#FEF2F2; color:#B91C1C; border:1px solid #F6C9C9; }
  .mf-acts .btn, .mf-pay .btn { display:inline-flex; align-items:center; gap:5px; border-radius:7px; font-weight:600; background:var(--card); color:var(--ink); border:1px solid var(--line); }
  .mf-acts .btn.ok, .mf-pay .btn.ok { background:#15803D; color:#fff; border-color:#15803D; }
  .mf-acts .del { background:var(--card) !important; color:#B91C1C !important; border-color:#F0C4C4 !important; }
  .mf-acts .a-ic, .mf-pay .a-ic, .mf-tools .a-ic { width:15px; height:15px; }
  .wa-mini .a-ic { width:15px; height:15px; }
  .mf-pl-card { border-radius:12px; }
  .mf-pl-bus { display:flex; align-items:center; gap:6px; }
  .scx-deck { border-radius:10px; }
  .scx-deck h4 { font-size:11px; font-weight:650; letter-spacing:.05em; text-transform:uppercase; color:var(--mut); }
  .scx-s { border-radius:6px; border-width:1px; }
  .scx .scx-free { background:#fff; border-color:#D9E0EA; }
  .scx .scx-free b { color:#64748B; }
  .scx .scx-busy { background:#EEF4FF; border-color:#B9CDF2; }
  .scx .scx-hd span { background:var(--head); color:var(--mut); font-weight:650; }
  :root[data-theme="dark"] .mf-tag.pay-paid { background:rgba(22,163,74,.15); border-color:rgba(22,163,74,.35); color:#6EE7A0; }
  :root[data-theme="dark"] .mf-tag.pay-agent { background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.35); color:#FBC56B; }
  :root[data-theme="dark"] .mf-tag.pay-due { background:rgba(220,38,38,.14); border-color:rgba(220,38,38,.35); color:#FCA5A5; }
  /* 6 Nov 2026: paid green · with agent amber · due red */
  tr.mf-st-paid td  { background:rgba(22,163,74,.13); } tr.mf-st-paid td.seat  { box-shadow:inset 5px 0 0 #16A34A; }
  tr.mf-st-agent td { background:rgba(245,158,11,.16); } tr.mf-st-agent td.seat { box-shadow:inset 5px 0 0 #F59E0B; }
  tr.mf-st-due td   { background:rgba(220,38,38,.13); } tr.mf-st-due td.seat   { box-shadow:inset 5px 0 0 #DC2626; }
  tr.mf-grp.mf-st-paid td  { border-left:6px solid #16A34A; }
  tr.mf-grp.mf-st-agent td { border-left:6px solid #F59E0B; }
  tr.mf-grp.mf-st-due td   { border-left:6px solid #DC2626; }
  .pay-paid  { background:#16A34A; color:#fff; } .pay-agent { background:#F59E0B; color:#1F1300; } .pay-due { background:#DC2626; color:#fff; }
  .mf-pay { display:inline-flex; gap:4px; align-items:center; flex-wrap:wrap; margin:0; }
  .mf-pay select, .mf-pay input { min-height:30px; border-radius:8px; border:1px solid var(--line,#ccc); padding:0 6px; font-size:12.5px; max-width:110px; }
  .mf-pay .btn { padding:3px 9px; min-height:30px; font-size:12.5px; }
  .mf-money { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 0; }
  .mf-money span { border-radius:10px; padding:6px 12px; font-weight:800; font-size:14px; }
  .mf-link { display:flex; gap:6px; flex-wrap:wrap; align-items:center; border:1px dashed #F0B44C; border-radius:12px; padding:8px 10px; margin:0 0 12px; background:#FFFBEB; color:#3A2A00; }
  .mf-link input { flex:1 1 260px; min-height:38px; border-radius:8px; border:1px solid #E5C77A; padding:0 8px; font-size:12.5px; }
  .mf-acts { display:inline-flex; gap:6px; flex-wrap:wrap; margin-left:6px; vertical-align:middle; }
  .mf-acts form { display:inline; margin:0; }
  .mf-acts .btn { padding:4px 10px; min-height:30px; font-size:12.5px; }
  .mf-acts .del { background:#B42318; color:#fff; border-color:#B42318; }
  .wa-mini { display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#25D366;vertical-align:middle;margin-left:4px;transition:background .15s }
  .wa-mini:hover { background:#1da851 }
  .wa-mini svg { display:block }

  @media print {
    /* §16 "PRINT MANIFEST": the crew needs the list, not the console. */
    header.tb, nav.side, .mf-tools, .no-print { display:none !important; }
    main.wrap { margin:0 !important; padding:0 !important; max-width:none !important; }
    table.mf  { font-size:11.5px; }
    table.mf th { background:#eee !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    a[href]:after { content:''; }
  }
</style>

<form class="mf-tools no-print" method="get">
  <label>Travel date<br>
    <input type="date" name="date" value="<?= Security::e($date) ?>" onchange="this.form.submit()">
  </label>

  <?php if (count($departures) > 1): ?>
  <label>Departure<br>
    <select name="sid" onchange="this.form.submit()">
      <?php foreach ($departures as $d): ?>
        <option value="<?= (int) $d['schedule_id'] ?>" <?= $trip !== null && (int) $d['schedule_id'] === (int) $trip['schedule_id'] ? 'selected' : '' ?>>
          <?= Security::e(substr((string) $d['dep_time'], 0, 5) . ' · ' . $d['from_city'] . ' → ' . $d['to_city']
              . ((int) ($d['slot'] ?? 1) > 1 ? ' · Bus ' . (int) $d['slot'] . ' (extra)' : '')
              . ($d['bus_number'] ? ' · ' . $d['bus_number'] : '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

  <label>Order<br>
    <select name="order" onchange="this.form.submit()">
      <option value="group" <?= $order === 'group' ? 'selected' : '' ?>>Group by booking (pickup order)</option>
      <option value="stop" <?= $order === 'stop' ? 'selected' : '' ?>>Pickup order (route)</option>
      <option value="seat" <?= $order === 'seat' ? 'selected' : '' ?>>Seat number</option>
    </select>
  </label>

  <button class="btn" type="submit">Show</button>

  <?php /* Add a late/missed ticket straight onto this departure — the seat map
           applies the 24h counter grace, so it works even after the bus left. */ ?>
  <?php if ($trip !== null): ?>
    <a class="btn" href="<?= $base ?>/admin/seatmap.php?<?= Security::e(http_build_query(['route' => $routeId, 'date' => $date])) ?>"><?= pc_icon('plus') ?> Add ticket</a>
  <?php endif; ?>

  <?php if ($trip !== null && $paxCount > 0): ?>
    <button class="btn ghost" type="button" onclick="window.print()"><?= pc_icon('printer') ?> Print</button>
    <?php /* 17 Sep 2026: every departure document (challan picture, Nepali
             chalani PDF / PNG, WhatsApp) lives on ONE hub page now. */ ?>
    <?php if ($scopeId === null): ?>
    <a class="btn" href="<?= $base ?>/admin/chalan.php?<?= Security::e(http_build_query(['sid' => (int) $trip['schedule_id']])) ?>"><svg class="a-ic"><use href="#a-doc"/></svg> Bus Chalan (PDF / PNG / WhatsApp)</a>
    <?php endif; ?>
    <a class="btn ghost" href="<?= $base ?>/admin/manifest.php?<?= Security::e(http_build_query(array_filter(['date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null, 'order' => $order !== 'group' ? $order : null, 'format' => 'csv']))) ?>"><?= pc_icon('download') ?> CSV</a>
  <?php endif; ?>
</form>

<?php if ($trip === null): ?>
  <p class="mf-empty">No departure is scheduled for <?= Security::e($date) ?>.</p>
  <?php if ($stillRunning !== []): ?>
    <div class="mf-empty no-print" style="background:#fff8e6;border:1px solid #f0d68a;color:#7a5300;padding:12px 16px;border-radius:10px;text-align:left">
      <strong>Still on the road (within 24h of departure)</strong> — open its chalani or add a late ticket:
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
        <?php foreach ($stillRunning as $sr): ?>
          <a class="btn ghost" href="?<?= Security::e(http_build_query(['date' => $sr['travel_date'], 'route' => (int) $sr['route_id']])) ?>">
            <?= Security::e(date('d M', strtotime((string) $sr['travel_date'])) . ' · ' . substr((string) $sr['dep_time'], 0, 5) . ' · ' . $sr['from_city'] . '→' . $sr['to_city']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>

  <div class="mf-head">
    <h2><?= Security::e($trip['from_city'] . ' → ' . $trip['to_city']) ?>
        · <?= Security::e(substr((string) $trip['dep_time'], 0, 5)) ?></h2>
    <div>
      <?= Security::e(date('D, d M Y', strtotime($date))) ?>
      · Bus <strong><?= Security::e($trip['bus_number'] ?: 'not assigned') ?></strong>
      <?= $trip['bus_name'] ? '· ' . Security::e((string) $trip['bus_name']) : '' ?>
      <?php if (!empty($trip['driver_name'])): ?>
        · Driver <?= Security::e((string) $trip['driver_name']) ?>
        <?= $trip['driver_phone'] ? '(' . Security::e((string) $trip['driver_phone']) . ')' : '' ?>
      <?php endif; ?>
    </div>
    <div class="mf-stats">
      <span><b><?= $paxCount ?></b> passengers</span>
      <span><b><?= (int) $trip['total_seats'] - $paxCount ?></b> seats free</span>
      <span><b><?= $boarded ?></b> boarded</span>
      <span><b><?= $unpaid ?></b> unpaid</span>
      <span><b><?= Security::e(inr($revenue)) ?></b> booked value</span>
      <?php if ($womenSingleCount > 0): ?><span style="color:#880E4F"><b>♀ <?= (int) $womenSingleCount ?></b> women on single sleeper</span><?php endif; ?>
    </div>
    <div class="mf-money">
      <?= pc_badge('Paid ' . $mfCount['paid'] . ' · ' . inr($mfMoney['paid']), 'ok') ?>
      <?= pc_badge('With agents ' . $mfCount['agent'] . ' · ' . inr($mfMoney['agent']), 'warn') ?>
      <?= pc_badge('Due ' . $mfCount['due'] . ' · ' . inr($mfMoney['due']), 'bad') ?>
    </div>
  </div>

  <?php if (OpsHub::publicOn()): $mfPub = OpsHub::publicUrl($date); ?>
  <div class="mf-link no-print"><?= pc_icon('link') ?> <b>Live chalani link</b> <span class="muted">login बिना · agent लाई share</span>
    <input readonly value="<?= Security::e($mfPub) ?>" onclick="this.select()">
    <a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Live chalani ' . date('d M', strtotime($date)) . ': ' . $mfPub) ?>">WhatsApp</a>
    <a class="btn ghost" target="_blank" href="<?= Security::e($mfPub) ?>">Open</a>
  </div>
  <?php endif; ?>

  <?php if ($mfCanTick): ?>
  <details class="no-print" style="margin:0 0 12px"><summary style="cursor:pointer;font-weight:650">Payment options (<?= Security::e(implode(' · ', OpsHub::payModes())) ?>)</summary>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
      <?php foreach (OpsHub::payModes() as $pm): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('<?= Security::e($pm) ?> हटाउने?')"><?= Security::csrfField() ?><input type="hidden" name="mf_action" value="delmode"><input type="hidden" name="mode" value="<?= Security::e($pm) ?>"><button class="btn ghost" type="submit"><?= Security::e($pm) ?> ✖</button></form>
      <?php endforeach; ?>
      <form method="post" style="margin:0;display:flex;gap:4px"><?= Security::csrfField() ?><input type="hidden" name="mf_action" value="addmode"><input name="mode" maxlength="30" placeholder="नयाँ option · e.g. PhonePe" style="min-height:38px;border-radius:8px;border:1px solid var(--line,#ccc);padding:0 8px"><button class="btn" type="submit">➕ Add</button></form>
    </div>
  </details>
  <?php endif; ?>

  <?php if ($mfFlash !== null): ?><div class="flash <?= $mfFlash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= Security::e($mfFlash[1]) ?></div><?php endif; ?>

  <?php if ($paxCount > 0): ?>
  <?php /* 6 Nov 2026: the simple chalani — place no. + name + head-count, bus + total, then the seat chart. */ ?>
  <div class="mf-places">
    <div class="mf-pl-card">
      <div class="mf-pl-bus"><?= pc_icon('bus') ?> <b><?= Security::e(trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? '')) ?: 'Bus not assigned') ?></b>
        <span><b><?= $paxCount ?></b> यात्रु · passengers</span></div>
      <table class="mf-pl">
        <thead><tr><th>नं.</th><th>चढ्ने ठाउँ · Pickup place</th><th class="r">यात्रु</th><th>सिट · Seats</th></tr></thead>
        <tbody>
        <?php foreach ($placeList as $pl): ?>
          <tr><td><i class="mf-no"><?= (int) $pl['no'] ?></i></td><td><b><?= Security::e($pl['name']) ?></b><?= $pl['women'] > 0 ? ' <small class="mf-wtag">♀ ' . (int) $pl['women'] . '</small>' : '' ?></td>
              <td class="r"><b><?= (int) $pl['n'] ?></b></td><td class="mf-seats"><?= Security::e(implode(', ', $pl['seats'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td></td><td><b>जम्मा · Total</b></td><td class="r"><b><?= $paxCount ?></b></td><td></td></tr></tfoot>
      </table>
    </div>
    <div class="mf-pl-card">
      <div class="mf-pl-bus"><?= pc_icon('pin') ?> <b>ओर्लिने ठाउँ · Drop places</b></div>
      <table class="mf-pl">
        <tbody>
        <?php foreach ($dropList as $dl): ?>
          <tr><td><b><?= Security::e($dl['name']) ?></b></td><td class="r"><b><?= (int) $dl['n'] ?></b></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <details class="mf-chart" open>
    <summary>Seat chart · सिट चार्ट (with names)</summary>
    <?= $seatChartHtml() ?>
  </details>
  <?php endif; ?>

  <?php if ($paxCount === 0): ?>
    <p class="mf-empty">No passengers are booked on this departure yet.</p>
  <?php else: ?>
    <div class="mf-scroll">
    <table class="mf">
      <thead>
        <tr>
          <th>Seat</th><th>Passenger</th><th>Phone</th><th>Boarding</th>
          <th>Destination</th><th>Fare</th><th>Booking ID</th><th>Payment</th>
          <th>Source</th><th>Agent</th><th>Ticket</th>
        </tr>
      </thead>
      <tbody>
      <?php
        /* 5 Nov 2026 (master upgrade §3): the booking groups on THIS departure —
           lead passenger, head-count, seats, pickup → drop, phone. Built from the
           same $rows, so cancellations / seat moves show on the next load. */
        $grp = [];
        if ($order === 'group') {
            foreach ($rows as $gr) {
                $gid = (int) $gr['booking_id'];
                if (!isset($grp[$gid])) $grp[$gid] = ['lead' => (string) $gr['full_name'], 'n' => 0, 'seats' => [], 'row' => $gr];
                $grp[$gid]['n']++;
                $grp[$gid]['seats'][] = Seats::displayLabel((string) $gr['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($gr['booking_mode'] ?? 'sharing'));
            }
        }
        $lastGid = null; $lastStop = null;
      ?>
      <?php foreach ($rows as $r):
        $paid = ($r['pay_status'] ?? '') === 'verified'; ?>
        <?php if ($order === 'group'):
          $stopNow = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—';
          if ($stopNow !== $lastStop): $lastStop = $stopNow;
            $stopPax = count(array_filter($rows, static fn($x) => ($x['boarding_stop'] ? $stopShort((string) $x['boarding_stop']) : '—') === $stopNow)); ?>
        <tr class="mf-stop"><td colspan="11"><span class="mf-no2"><?= (int) ($placeList[$stopNow]['no'] ?? 0) ?></span> <?= Security::e($stopNow) ?> · <?= $stopPax ?> passenger<?= $stopPax === 1 ? '' : 's' ?></td></tr>
          <?php endif;
          $gid = (int) $r['booking_id'];
          if ($gid !== $lastGid): $lastGid = $gid; $g = $grp[$gid]; ?>
        <?php $gSt = $mfState[$gid] ?? 'due'; [$gLbl, $gCls] = OpsHub::payLabel($gSt); $gTick = $mfTicks[$gid] ?? null; ?>
        <tr class="mf-grp mf-st-<?= Security::e($gSt) ?>"><td colspan="11">
          <span class="mf-tag <?= Security::e($gCls) ?>"><?= Security::e($gLbl) ?></span>
          <b><?= Security::e($g['lead']) ?></b> — <?= (int) $g['n'] ?> passenger<?= $g['n'] === 1 ? '' : 's' ?>
          — <?= Security::e($stopNow) ?> → <?= Security::e($stopShort((string) ($r['drop_stop'] ?: $trip['to_city']))) ?>
          — <span class="mono"><?= Security::e((string) $r['contact_phone']) ?></span>
          — seats <b><?= Security::e(implode(', ', $g['seats'])) ?></b>
          · <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a>
          <span class="mf-acts no-print">
            <a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode((string) $r['pnr']) ?>" title="अर्को मिति / अर्को बसमा सार्नुहोस्"><?= pc_icon('swap') ?> Transfer</a>
            <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>#seats" title="सिट बदल्नुहोस्"><?= pc_icon('seat') ?> Seat</a>
            <?php if ($mfCanTick): ?>
              <?php if ($gSt !== 'paid'): ?>
              <form method="post" class="mf-pay" title="भुक्तानी आयो — tick"><?= Security::csrfField() ?><input type="hidden" name="mf_action" value="paid"><input type="hidden" name="booking_id" value="<?= $gid ?>">
                <select name="mode" aria-label="Payment option"><?php foreach (OpsHub::payModes() as $pm): ?><option><?= Security::e($pm) ?></option><?php endforeach; ?></select>
                <input name="amount" inputmode="numeric" placeholder="₹ <?= (int) round((float) $r['total_amount']) ?>" aria-label="Amount">
                <button class="btn ok" type="submit"><?= pc_icon('check') ?> Paid</button></form>
              <?php else: ?>
              <small><?= $gTick !== null ? Security::e(trim((string) $gTick['mode'] . ' · ' . inr((float) $gTick['amount']) . ' · ' . ((string) ($gTick['by_name'] ?? '') ?: (string) ($gTick['by_user'] ?? '')))) : 'auto' ?></small>
              <form method="post" class="mf-pay" onsubmit="return confirm('<?= Security::e((string) $r['pnr']) ?> फेरि बाँकी (due) बनाउने?')"><?= Security::csrfField() ?><input type="hidden" name="mf_action" value="due"><input type="hidden" name="booking_id" value="<?= $gid ?>"><button class="btn ghost" type="submit">Mark due</button></form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($mfCanCancel): ?>
            <form method="post" onsubmit="return confirm('<?= Security::e((string) $r['pnr']) ?> — <?= Security::e(addslashes($g['lead'])) ?> (<?= (int) $g['n'] ?>) cancel गर्ने? सिट खाली हुन्छ। Refund हातैले।')">
              <?= Security::csrfField() ?><input type="hidden" name="mf_action" value="cancel"><input type="hidden" name="pnr" value="<?= Security::e((string) $r['pnr']) ?>">
              <?php if (OpsHub::fineOn()): ?><input name="fine" inputmode="numeric" placeholder="Fine ₹" value="<?= OpsHub::fineDefault() > 0 ? (int) OpsHub::fineDefault() : '' ?>" style="width:74px;min-height:30px;border-radius:8px;border:1px solid #ccc;padding:0 6px" title="जरिवाना (खाली = छैन)"><?php endif; ?>
              <button class="btn del" type="submit"><?= pc_icon('trash') ?> Delete</button>
            </form>
            <?php else: ?>
            <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>" title="Cancel request"><?= pc_icon('trash') ?> Cancel…</a>
            <?php endif; ?>
          </span>
        </td></tr>
          <?php endif; ?>
        <?php endif; ?>
        <?php $rowWs = $isWomanSingle($r); $rSt = $mfState[(int) $r['booking_id']] ?? 'due'; ?>
        <tr class="mf-st-<?= Security::e($rSt) ?><?= $rowWs ? ' mf-ws' : '' ?>">
          <td class="seat"><?= Security::e(Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing'))) ?></td>
          <td>
            <?= $rowWs ? '<b style="color:#C2185B" title="Woman on a single sleeper">♀ </b>' : '' ?><?= Security::e((string) $r['full_name']) ?>
            <?php if ($r['age'] || $r['gender']): ?>
              <br><small style="opacity:.7"><?= Security::e(trim(($r['age'] ? $r['age'] . 'y ' : '') . (string) $r['gender'])) ?></small>
            <?php endif; ?>
            <?php if (!empty($r['special_need'])): ?>
              <br><small style="color:#0f5c36;font-weight:700" title="Priority boarding">Priority · <?= Security::e(ucfirst((string) $r['special_need'])) ?></small>
            <?php endif; ?>
          </td>
          <td class="mono">
            <?= Security::e((string) $r['contact_phone']) ?>
            <?php
              $waNum = preg_replace('/[\s\-\(\)]/', '', (string) $r['contact_phone']);
              if ($waNum !== '' && $waNum[0] !== '+') $waNum = '+' . $waNum;
              if ($waNum !== ''):
            ?>
              <a href="https://wa.me/<?= Security::e(ltrim($waNum, '+')) ?>" target="_blank" rel="noopener"
                 class="wa-mini" title="WhatsApp">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="#25D366"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.28-.1-.48-.15-.68.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.14-.14.3-.35.45-.53.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.68-1.63-.93-2.23-.24-.58-.49-.5-.68-.51h-.58c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.22 5.1 4.51.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.58-.08 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.28-.2-.58-.35zm-5.44 7.44h-.02a9.87 9.87 0 01-5.03-1.38l-.36-.22-3.74.98 1-3.65-.24-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.65 0 5.14 1.03 7 2.9a9.83 9.83 0 012.9 7c0 5.45-4.44 9.89-9.9 9.89zm8.41-18.3A11.82 11.82 0 0012.04 0C5.46 0 .1 5.35.1 11.93c0 2.1.55 4.16 1.6 5.95L0 24l6.3-1.65a11.9 11.9 0 005.73 1.47h.01c6.58 0 11.94-5.35 11.94-11.93a11.86 11.86 0 00-3.54-8.47z"/></svg>
              </a>
              <?php /* 8 Oct 2026 (master prompt 2.0): one tap to call from the crew phone. */ ?>
              <a href="tel:<?= Security::e($waNum) ?>" class="wa-mini mf-call" title="Call passenger" aria-label="Call <?= Security::e((string) $r['full_name']) ?>"><?= pc_icon('phone') ?></a>
            <?php endif; ?>
          </td>
          <td><?= Security::e($r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—') ?>
            <?php
              /* Open the OFFICIAL pickup in Maps (8 Oct 2026): its own coordinates
                 when the stop carries them, else a place search by its name. */
              $bpRaw = (string) ($r['boarding_stop'] ?? '');
              if ($bpRaw !== ''):
                $bpMap = preg_match('/\[\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*\]/', $bpRaw, $gm) === 1
                  ? 'https://www.google.com/maps/dir/?api=1&destination=' . $gm[1] . ',' . $gm[2]
                  : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($stopShort($bpRaw));
            ?>
              <a href="<?= Security::e($bpMap) ?>" target="_blank" rel="noopener" class="wa-mini mf-map" title="Open pickup in Maps"><?= pc_icon('pin') ?></a>
            <?php endif; ?>
          </td>
          <td><?= Security::e($stopShort((string) ($r['drop_stop'] ?: $trip['to_city']))) ?></td>
          <td class="mono"><?= Security::e(inr($farePer($r))) ?></td>
          <td class="mono">
            <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a>
            <?php /* 17 Sep 2026: the ticket to this passenger on WhatsApp (previewed, logged) —
                     only a confirmed booking has a ticket the composer will send. */ ?>
            <?php if ((string) ($r['pnr'] ?? '') !== '' && (string) ($r['booking_status'] ?? '') === 'confirmed'): ?><?= admin_wa_button('booking_ticket', ['pnr' => (string) $r['pnr']], '💬', ['class' => 'btn ghost sm', 'title' => 'Send the ticket to this passenger on WhatsApp — opens a preview first']) ?><?php endif; ?>
          </td>
          <td><?php [$rLbl, $rCls] = OpsHub::payLabel($rSt); ?><span class="mf-tag <?= Security::e($rCls) ?>"><?= Security::e($rLbl) ?></span>
            <br><small class="muted"><?= Security::e($payLabel($r)) ?><?= !empty($r['pay_method']) ? ' · ' . Security::e(strtoupper((string) $r['pay_method'])) : '' ?></small></td>
          <td><?= Security::e($sourceLabel($r)) ?>
            <?php if ($bookedTime($r) !== ''): ?>
              <br><small<?= $isLate($r) ? ' style="color:#8a1111;font-weight:700"' : ' class="muted"' ?>><?= $isLate($r) ? '⚠ after dep · ' : 'booked ' ?><?= Security::e($bookedTime($r)) ?></small>
            <?php endif; ?>
          </td>
          <td><?= Security::e($agentLabel($r) ?: '—') ?></td>
          <td class="mono"><?= Security::e($ticketLabel($r)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php
admin_footer();
