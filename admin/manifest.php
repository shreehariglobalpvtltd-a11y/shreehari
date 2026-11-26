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
$format  = in_array($_GET['format'] ?? '', ['csv', 'chalani', 'chalanipdf', 'chalanipng', 'pdf', 'payqr', 'json', 'chalanishare', 'chalani80'], true) ? (string) $_GET['format'] : 'html';   // 25 Nov 2026 (release ch): + json (live poll), chalanishare (WhatsApp)   // 26 Nov 2026 (fx1): + chalani80 (80 mm thermal list)
require_once INCLUDE_PATH . '/chalanipng.php';   // 16 Nov 2026: ChalaniPng::payMode - the pay-at-boarding rule every chalani surface shares
require_once INCLUDE_PATH . '/paxid.php';        // 25 Nov 2026 (op5): the passenger ID tag for the border crew
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

/* 16 Nov 2026 (owner: "ani redirect payment lina sab suvidha hunu paryo, free wala"):
   the crew's 💳 QR - the passenger's own pay page (pay.php: UPI, UPI QR, eSewa, a
   UTR claim; all free) as a picture to show at the bus door. READ-ONLY: it never
   marks anything paid - an online payment lands 'pending' and only the office
   approves it. Only a pay-at-boarding booking still unpaid gets one, and an agent
   login only for its own sales. */
if ($format === 'payqr') {
    $qrPng = ChalaniPng::payQrPng((string) ($_GET['pnr'] ?? ''), $scopeId, null, null, true);   // the ticket key rides along (staff screen)
    if ($qrPng === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('बसमा तिर्न बाँकी छैन · Nothing is due on boarding for this booking.');
    }
    header('Content-Type: image/png');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen($qrPng));
    echo $qrPng;
    exit;
}

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
/* 19 Nov 2026 (AI-3): the rows AND the money rule now live in ONE place -
   ChalaniPng::departureMoney() - so this page, the PDF, the PNG pages, the
   office WhatsApp report and the driver's list share every rupee. The same
   names below are unpacked from it; nothing on the page changed (proved by
   tests/u10-chalani-money-helper-test.php against the old inline code).
   NP-2: ?desk=<code> (validated against CounterDesk::all()) keeps only the
   sales of that desk; an NPR desk prints its money in NPR (see the chalani). */
$deskF = ChalaniPng::deskCode((string) ($_GET['desk'] ?? ''));
$dm    = ChalaniPng::departureMoney($trip !== null ? (int) $trip['schedule_id'] : 0, $scopeId, $order, $deskF);
$rows  = $dm['rows'];

/* Per-person fare divisor. total_amount is stored ONCE per booking and
   covers every seat on it — including a round-trip's return leg, which this
   single-departure manifest does not list. So divide by the booking's WHOLE
   passenger-seat count (all legs), not just the rows on this departure, or a
   round-trip fare would read double. */
/* 6 Nov 2026: paid · with agent · due — one rule (OpsHub::payState) for this
   screen, Bus Control and the public link. */
$mfTicks = $dm['ticks'];
$mfState = $dm['state'];
$paxTotalPerBooking = $dm['paxTotal'];
$bids = $dm['bids'];

/* 16 Nov 2026 (owner: "chalani ko lagi bisesh payment bhayo ki nai thapaune
   line rakheu"): the desk EXTRAS of each booking on this bus - extra luggage
   pieces and lap infants (no berth) - with whether that money came in.
   total_amount carries them, so the per-seat fare below takes them OFF first
   (they are not a seat's fare) and they print once, on the booking's first row
   here, in their own "विशेष भुक्तानी" lines. A round trip's extras are split
   across its legs the same way its fare is, so the two chalanis add up. */
$legsPerBooking = $dm['legs'];
$extrasOf   = $dm['extrasOf'];
$xBook      = $dm['xBook'];    // booking id => the extras block line (a ₹0 free infant line reads 'free', never "✗ बाँकी")
$xFirst     = $dm['xFirst'];   // booking id => pax_id of its first row on this chalani
$xInfants   = $dm['xInfants'];
/** The extras money that rides on THIS row: the booking's leg share, on its first row only. */
$extraOnRow = $dm['extraOnRow'];
/** "भयो" / "बाँकी" for an extras line — the same paid · with agent · due rule as the row colour. */
$xStateNe = static fn(string $st): string => match ($st) { 'paid' => '✓ भयो', 'agent' => '✓ भयो (एजेन्टसँग)', 'free' => '— निःशुल्क', default => '✗ बाँकी' };
$xCls     = static fn(string $st): string => $st === 'due' ? 'xdue' : ($st === 'free' ? '' : 'xok');

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
$farePer = $dm['farePer'];   // 16 Nov 2026: the desk extras (luggage / infant) are not a seat's fare
/* Money per ROW, one rule shared by the screen, the print sheet, the PDF and
   the PNG pages: ticket = this passenger's share of the booking; it lands in
   the नगद column when the verified payment was cash / COD, in अनलाइन for
   UPI / eSewa / bank / wallet, and in neither while still unpaid.
   16 Nov 2026: + 'extra' — the booking's desk extras (luggage / infant), on
   its first row only. It is collected WITH the fare, so a paid row's नगद /
   अनलाइन carries fare + extra; टिकट stays the seat's fare alone.
   (Moved up from the chalani branch, 16 Nov 2026, so the screen's नगद /
   अनलाइन badges read the very same rule.)
   Review 16 Nov 2026: cash or online is ChalaniPng::paidWay() - a settled pay-at-
   boarding row always says 'cod', so the office tick's mode (UPI / eSewa / Bank)
   puts it in अनलाइन, as the passenger's ticket says; it used to land in नगद. */
/* review: the extras sit in नगद / अनलाइन only where their own line says भयो -
   an office "Mark due" keeps the payment row verified, and the same rupees
   must not read as collected AND still to collect on one sheet.
   19 Nov 2026: the closure itself is ChalaniPng::departureMoney()'s. */
$money = $dm['money'];

/* 16 Nov 2026 (owner: "pay at boarding vane option ticket chalani ma add gardeu -
   cash ho ki online ho"): HOW each booking pays - ChalaniPng::payMode(), read from
   the row colour ($mfState = OpsHub::payState) and the verified payment the नगद /
   अनलाइन columns read; never a second paid rule. A pay-at-boarding booking still
   unpaid owes its fare share + desk extras on THIS bus: "बसमा ₹X", printed once
   (on its first row, like the extras line). 'boarding' never has a verified
   payment, so its rupees are never also in नगद / अनलाइन. */
$pmBook  = $dm['pmBook'];    // booking id => pay mode
$pmDue   = $dm['pmDue'];     // booking id => ₹ the crew collects on boarding, this bus
$pmPax   = $dm['pmPax'];     // booking id => passengers on this bus
$pmLead  = $dm['pmLead'];    // booking id => its first passenger here (the 💳 QR card names them)
$pmClaim = $dm['pmClaim'];   // booking id => the passenger already sent a UTR (CodPayClaim) - still due, the office checks it
$boardDue  = $dm['boardDue'];
$boardDueN = $dm['boardDueN'];
/** ['mode', 'amt', 'first', 'ne', 'claim'] for one row - the PDF and the PNG pages read this too. */
$payMark = static function (array $r) use ($pmBook, $pmDue, $xFirst, $pmClaim): array {
    $bid   = (int) ($r['booking_id'] ?? 0);
    $mode  = $pmBook[$bid] ?? 'due';
    $first = ($xFirst[$bid] ?? -1) === (int) ($r['pax_id'] ?? 0);
    $amt   = $mode === 'boarding' ? round((float) ($pmDue[$bid] ?? 0), 2) : 0.0;
    $ne    = ChalaniPng::payModeLabel($mode, 'ne') . ($mode === 'boarding' && $first && $amt > 0 ? ' ₹' . number_format($amt) : '');
    return ['mode' => $mode, 'amt' => $amt, 'first' => $first, 'ne' => $ne, 'claim' => (bool) ($pmClaim[$bid] ?? false)];
};
/* The staff words (Nepali · English): "बसमा ₹2,200 · On boarding", "नगद ✓ · Cash" ... */
$pmStaff = static fn(array $pmk, bool $withAmt = true): string => $pmk['mode'] === 'boarding'
    ? 'बसमा' . ($withAmt && (float) $pmk['amt'] > 0 ? ' ' . inr((float) $pmk['amt']) : '') . ' · On boarding'
    : ChalaniPng::payModeLabel((string) $pmk['mode'], 'staff');
/* The 💳 QR picture for a booking still due on boarding (format=payqr above). */
$payQrSrc = static fn(string $pnr): string => $base . '/admin/manifest.php?' . http_build_query(['format' => 'payqr', 'pnr' => $pnr]);

/* 25 Nov 2026 (release ch, owner plan section 6): ?format=json - what the chalani
   screen polls every 30 s (only while its tab is visible) so an online payment
   flips the row to PAID and the strip moves without a reload. The SAME gate as
   this page (admin_boot('bookings.view') above, an agent scoped to its own sales,
   the ?desk= filter) and the same rows ($dm). Read-only. Switch chalani_live_poll_on. */
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if ($trip === null || !ChalaniStrip::pollOn()) {
        echo json_encode(['ok' => false, 'off' => !ChalaniStrip::pollOn()]);
        exit;
    }
    echo json_encode(ChalaniStrip::livePayload($dm, $pmStaff), JSON_UNESCAPED_UNICODE);
    exit;
}
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
    // 16 Nov 2026: a lap infant shows on the berth of its booking's first passenger
    $bInf = ($xFirst[(int) ($r['booking_id'] ?? 0)] ?? -1) === (int) ($r['pax_id'] ?? 0)
        ? (int) ($xBook[(int) $r['booking_id']]['inf'] ?? 0) : 0;
    foreach ($physOf($r) as $bi => $bed) {
        $seatInfo[$bed] = [
            'inf'   => $bi === 0 ? $bInf : 0,
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
             . '<i>' . ($i['ws'] ? '♀ ' : '') . ($i['place'] > 0 ? '📍' . (int) $i['place'] : '') . ((int) ($i['inf'] ?? 0) > 0 ? ' 👶' . (int) $i['inf'] : '') . '</i></div>';
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
        . '<span>👶N = काखमा शिशु · lap infant</span>'
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
    if ($xInfants > 0) { csv_put($out, ['Infants (lap, no berth)', (string) $xInfants]); }
    // 16 Nov 2026: pay-at-boarding money still to collect on this bus (never also in cash / online)
    csv_put($out, ['To collect on boarding', $boardDueN . ' passengers', (string) round($boardDue, 2)]);
    csv_put($out, []);
    csv_put($out, ['Seat', 'Passenger', 'Age', 'Gender', 'Phone', 'Boarding point',
                   'Destination', 'Fare', 'Booking ID', 'Payment', 'Pay mode', 'Source', 'Agent', 'Ticket', 'Booked',
                   'Extra luggage', 'Infants', 'Extra Rs', 'Extra paid']);

    /* 26 Nov 2026 (fx1): a passenger types their own name on the website, and a cell
       that starts with = + - @ is run as a FORMULA when the office opens this file in
       Excel. Free-text cells that start that way get a leading apostrophe (shown as
       text, harmless - ChalaniStrip::csvText). Numbers, the phone and the fixed words
       stay exactly as they were. */
    $csvText = static fn($v): string => ChalaniStrip::csvText($v);
    foreach ($rows as $r) {
        $xRow = $extraOnRow($r);
        $xB   = $xBook[(int) ($r['booking_id'] ?? 0)] ?? null;
        $xHere = $xB !== null && ($xFirst[(int) $r['booking_id']] ?? -1) === (int) ($r['pax_id'] ?? 0);
        csv_put($out, [
            Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing')),
            $csvText($r['full_name']), $r['age'], $csvText($r['gender']), $r['contact_phone'],
            $csvText($stopShort((string) $r['boarding_stop'])), $csvText($stopShort((string) $r['drop_stop'])), $farePer($r), $r['pnr'],
            $payLabel($r), ChalaniPng::payModeLabel($payMark($r)['mode'], 'csv') . ($payMark($r)['claim'] ? ' (' . ChalaniPng::payClaimLabel('csv') . ')' : ''), $csvText($sourceLabel($r)), $csvText($agentLabel($r)), $ticketLabel($r),
            $bookedTime($r) . ($isLate($r) ? ' (after departure)' : ''),
            $xHere ? (string) $xB['lug'] : '', $xHere ? (string) $xB['inf'] : '',
            $xHere ? (string) $xRow : '', $xHere ? match ($xB['state']) { 'due' => 'DUE', 'agent' => 'PAID (with agent)', 'free' => 'FREE', default => 'PAID' } : '',
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
if ($format === 'chalani' || $format === 'chalanipdf' || $format === 'chalanipng' || $format === 'chalanishare' || $format === 'chalani80') {
    if ($trip === null) {
        exit('No departure on this date.');
    }
    /* 26 Nov 2026 (fx1): the 80 mm thermal list is its own switch (chalani_thermal_on,
       default on). Off = no button anywhere and this format is not found. */
    if ($format === 'chalani80' && !ChalaniStrip::thermalOn()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('८० मिमि चलानी बन्द छ · The 80 mm chalani is switched off.');
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
    /* 25 Nov 2026 (release ch): the whole-bus chalani as PNG / PDF / print / WhatsApp
       share needs chalani.export (superadmin, counter, manager, operations; anyone
       else by a per-person grant). An agent's AGENT COPY stays under agent_chalani_on
       above, but an agent never shares. Switch chalani_export_perm_on (off = bookings.view). */
    if ((!$agentCopy && !ChalaniStrip::mayExport()) || ($agentCopy && $format === 'chalanishare')) {
        Logger::audit('manifest.chalani_refused', 'schedule', (string) $trip['schedule_id'], null, null, 'no chalani.export: ' . $format);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('चलानी PNG / PDF / सेयर अनुमति छैन · You do not have the chalani.export permission — ask the office.');
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

    /* 26 Nov 2026 (fx1): the flags and the corridor words follow the DIRECTION of this
       departure (India first going to Nepal, Nepal first on the return run) - the
       sheet said "गुजरात ⇄ रुपैडिहा" with the India flag first on every bus. And the
       date in BS beside AD (switch chalani_bs_date_on; AD alone outside the BS table). */
    $toNepal    = ChalaniPng::toNepal($trip);
    $corridorNe = $toNepal ? 'गुजरात → रुपैडिहा' : 'रुपैडिहा → गुजरात';
    $landsNe    = $toNepal ? 'भारत–नेपाल' : 'नेपाल–भारत';
    $bsNe       = ChalaniPng::bsDate($date, 'ne');

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

    /* $money — the per-row money rule — is defined once in the shared block
       above (16 Nov 2026), so the screen badges read it too. */
    /* 19 Nov 2026 (AI-3): the whole-bus totals are ChalaniPng::departureMoney()'s
       (ticket, cash / online by paidWay, the base_total concession less the desk
       extras, extras paid / due, pay at boarding still to collect). NP-2: a desk
       copy carries the desk in its number, as an agent copy carries -A<code>. */
    if ($deskF !== '') {
        $chalaniNo .= '-' . $deskF;
    }
    $tt = $dm['totals'];
    $totals = [
        'passengers' => $tt['passengers'], 'ticket' => $tt['ticket'], 'cash' => $tt['cash'],
        'online' => $tt['online'], 'discount' => $tt['discount'], 'net' => $tt['net'],
        'chalaniNo' => $chalaniNo,
        // 16 Nov 2026: special (desk extras) payments — whole, came in, still to collect
        'extras' => $tt['extras'], 'extrasPaid' => $tt['extrasPaid'], 'extrasDue' => $tt['extrasDue'], 'infants' => $tt['infants'],
        // 16 Nov 2026: pay at boarding still to collect - passengers and ₹ (not in cash / online)
        'boardDue' => $tt['boardDue'], 'boardDueN' => $tt['boardDueN'],
    ];
    /* the "विशेष भुक्तानी" lines, in chalani row order, seat labels as printed */
    $xLines = [];
    foreach ($xBook as $xb) {
        $xb['seatsLbl'] = implode(', ', array_map(static fn(string $s): string =>
            Seats::displayLabel($s, (string) ($trip['coach_type'] ?? 'sleeper'), $xb['mode']), array_values(array_unique($xb['seats']))));
        $xLines[] = $xb;
    }

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
       way on paper and another in the picture.
       26 Nov 2026 (fx4): ChalaniPng::sheetFor() (includes/chalanipng.php) builds a
       copy of this block for download-chalan.php's on-demand redraw - change the two
       together; tests/fx1-chalani-test.php (PARITY) fails when they drift. */
    $chalaniFmt = ['money' => $money, 'stopShort' => $stopShort, 'isLate' => $isLate,
                   'bookedBy' => $bookedByShort, 'stopsLine' => $stopsLine,
                   'stopsLineRoman' => $stopsLineRoman,
                   /* 6 Nov 2026: the place list, women on a single sleeper and the
                      seat chart — the PDF and the PNG pages draw the same picture
                      as the print sheet (ChalaniPdf / ChalaniPng read these). */
                   'placeList' => array_values($placeList), 'placeNo' => static fn(string $stop): int => (int) ($placeList[$stop]['no'] ?? 0),
                   'womanSingle' => $isWomanSingle, 'womenSingleCount' => $womenSingleCount,
                   'seatInfo' => $seatInfo, 'layout' => $layout,
                   'busName' => trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? '')),
                   /* 16 Nov 2026: the special-payment lines (one per booking with
                      desk extras) and the state of the booking a row belongs to */
                   'extrasLines' => $xLines, 'penLines' => 2,
                   'rowState' => static fn(array $r): string => (string) ($mfState[(int) ($r['booking_id'] ?? 0)] ?? 'due'),
                   /* 16 Nov 2026: how the row pays - बसमा ₹X / नगद ✓ / अनलाइन ✓ (ChalaniPng::payMode) */
                   'payMark' => $payMark,
                   /* 25 Nov 2026 (op5): "CTZ **1234" for the border crew - the office / counter
                      copy only (an agent copy never carries it); switch pax_id_field_on */
                   'paxId' => $scopeId === null && PaxId::enabled()
                        ? static fn(array $r): string => PaxId::short((string) ($r['id_type'] ?? ''), (string) ($r['id_number'] ?? '')) : null,
                   'infantsOf' => static fn(array $r): int => ($xFirst[(int) ($r['booking_id'] ?? 0)] ?? -1) === (int) ($r['pax_id'] ?? 0)
                        ? (int) ($xBook[(int) $r['booking_id']]['inf'] ?? 0) : 0,
                   /* 26 Nov 2026 (fx1): WHOSE copy this is - '' the whole bus, an agent's
                      copy, a desk's copy. The PNG pages print it in the head and keep a
                      copy's picture files apart from the whole-bus ones (ChalaniPng::copyInfo),
                      so drawing an agent / desk copy can no longer replace the pages a
                      signed share link serves. The PDF prints the same line. */
                   'copy' => ChalaniPng::copyInfo(
                        $agentCopy ? (string) (AgentWallet::agentCodeFor((int) $scopeId) ?? (int) $scopeId) : null,
                        $agentCopy ? (string) ($admin['full_name'] ?? '') : '', $deskF, $chalaniNo)];

    /* 19 Nov 2026 (NP-2, owner: "Nepal counter select garda NPR ma"): a Nepal
       desk's copy prints its money in NPR - the NPR frozen on each sale, else
       the desk's rate - with the rupees beside the totals. Only when exactly
       one NPR desk is chosen: INR and NPR are never added together. */
    $curSym = '₹';
    if ($dm['cur'] !== null) {
        $deskCur = $dm['cur'];
        $curSym  = (string) $deskCur['sym'];
        $rateOf  = $dm['rateOf'];
        $totals  = $dm['local'] + ['chalaniNo' => $chalaniNo, 'inr' => $totals];
        $money   = $dm['moneyLocal'];
        $payMarkInr = $payMark;
        $payMark = static function (array $r) use ($payMarkInr, $rateOf, $curSym): array {
            $pm = $payMarkInr($r);
            $pm['amt'] = (float) round((float) $pm['amt'] * $rateOf($r));
            $pm['ne']  = ChalaniPng::payModeLabel((string) $pm['mode'], 'ne')
                . ($pm['mode'] === 'boarding' && $pm['first'] && $pm['amt'] > 0 ? ' ' . $curSym . ' ' . number_format($pm['amt']) : '');
            return $pm;
        };
        foreach ($xLines as $xi => $xl) {
            $k = $rateOf(['booking_id' => (int) (array_search($xl['pnr'], array_column($rows, 'pnr', 'booking_id'), true) ?: 0)]);
            foreach (['amt', 'lugFee', 'infFee'] as $xk) { $xLines[$xi][$xk] = (float) round((float) $xl[$xk] * $k); }
        }
        $chalaniFmt['money']       = $money;
        $chalaniFmt['payMark']     = $payMark;
        $chalaniFmt['extrasLines'] = $xLines;
        $chalaniFmt['cur'] = $deskCur + ['label' => CounterDesk::label($deskCur['desk'])];
    }

    /* Where the three chalani surfaces live. Defined HERE, before any of
       the branches: the PNG picker links back to the PDF, so leaving these
       below the branches left $pdfHref undefined inside the picker — which
       under the strict error handler is a fatal, mid-page. */
    $chalaniLink = static function (array $extra) use ($base, $date, $routeId, $sidReq, $deskF): string {
        return $base . '/admin/manifest.php?' . http_build_query(array_filter(array_merge(
            ['date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null, 'desk' => $deskF !== '' ? $deskF : null],
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
    /* 25 Nov 2026 (release ch): WhatsApp share - draw the sheet pages fresh (so the
       signed link serves THIS data), then open wa.me with the text + one signed,
       expiring link per page (download-chalan.php; chalani_share_link_days, default 2). */
    if ($format === 'chalanishare') {
        $sheetPages = ChalaniPng::pageCount($rows);
        try {
            for ($p = 1; $p <= $sheetPages; $p++) { ChalaniPng::render($trip, $rows, $date, $totals, $chalaniFmt, $p); }
        } catch (Throwable $ex) {
            Logger::error('Chalani share render failed: ' . $ex->getMessage(), ['sid' => $trip['schedule_id'] ?? 0]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('The chalani page could not be drawn: ' . $ex->getMessage());
        }
        $shStrip = ChalaniStrip::fromRows($rows, $chalaniFmt['money'], $chalaniFmt['payMark']);
        /* 26 Nov 2026 (fx1): a desk copy's links name that copy (its own picture files);
           the whole-bus links are exactly as before */
        $links   = ChalaniStrip::shareLinks((int) $trip['schedule_id'], $sheetPages, null, ChalaniPng::copyTag($chalaniFmt));
        $waText  = ChalaniStrip::shareText($trip, $date, $chalaniNo, $shStrip, $links, (string) ($chalaniFmt['cur']['code'] ?? 'INR'));
        Logger::audit('manifest.chalanishare', 'schedule', (string) $trip['schedule_id'], null,
            ['pages' => $sheetPages, 'desk' => $deskF], 'WhatsApp share opened, ' . count($rows) . ' passengers, ' . $date);
        header('Location: https://wa.me/?text=' . rawurlencode($waText), true, 302);
        exit;
    }

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

    /* =================================================================
     *  26 Nov 2026 (fx1) - THE 80 mm THERMAL LIST (?format=chalani80)
     *
     *  The same chalani, cut to what the conductor reads at the bus door on
     *  a strip from the counter's thermal printer: number, BS + AD date, bus,
     *  driver, then place by place - seat, name, drop, how the seat pays -
     *  and the Total | Cash | Online | Due figures. Same gate as the sheet
     *  above (an agent prints only its AGENT COPY; the whole bus needs
     *  chalani.export), same rows, same money rule ($money / $payMark - a
     *  Nepal desk copy in NPR). Reads only.
     *  Phones: the last 4 digits; the whole number only when the office has
     *  switched passenger phones on for the crew (driver_list_phones).
     *  Switch chalani_thermal_on (default on).
     * ================================================================= */
    if ($format === 'chalani80') {
        $t80Strip  = ChalaniStrip::fromRows($rows, $money, $payMark);
        $t80Full   = Settings::getBool('driver_list_phones', false);
        $t80Phone  = static function (string $ph) use ($t80Full): string {
            $d = (string) preg_replace('/\D+/', '', $ph);
            if ($d === '' || $d === '0000000000') { return ''; }
            return $t80Full ? $ph : '••••' . substr($d, -4);
        };
        $t80Bus    = trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? ''));
        $t80Co     = Settings::company();
        $t80Copy   = $chalaniFmt['copy'];
        $t80Rows   = [];   // place name => its rows, in the place list's own order
        foreach ($placeList as $pl) { $t80Rows[(string) $pl['name']] = []; }
        foreach ($rows as $r) {
            $t80Rows[$r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—'][] = $r;
        }
        Logger::audit('manifest.chalani80', 'schedule', (string) $trip['schedule_id'], null, null, count($rows) . ' passengers, ' . $date);
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html><html lang="ne"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>बस चलानी ८० मिमि · <?= $e($date) ?> · <?= $e($trip['route_code']) ?></title>
<style>
  @page { size: 80mm auto; margin: 3mm; }
  * { box-sizing:border-box; }
  body { margin:0; padding:10px; background:#EEF1F6; color:#000; font-family:'Noto Sans Devanagari','Mangal',Arial,sans-serif; font-size:12px; line-height:1.35; }
  .t80 { width:72mm; max-width:100%; margin:0 auto; background:#fff; padding:3mm 2.5mm 4mm; }
  .no-print { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; width:72mm; max-width:100%; margin:0 auto 10px; }
  .no-print a, .no-print button { min-height:44px; padding:0 14px; font:inherit; font-size:16px; font-weight:700; border-radius:10px; border:1px solid #12264E; background:#fff; color:#12264E; text-decoration:none; display:inline-flex; align-items:center; cursor:pointer; }
  .no-print .pri { background:#12264E; color:#fff; }
  h1 { margin:0; font-size:14px; text-align:center; line-height:1.25; }
  h2 { margin:2px 0 0; font-size:15px; text-align:center; letter-spacing:.3px; }
  .cp { margin:3px 0 0; text-align:center; font-weight:800; border:1.5px solid #000; padding:2px 4px; }
  .kv { width:100%; border-collapse:collapse; margin:5px 0 0; }
  .kv td { padding:1px 0; vertical-align:top; } .kv td:first-child { white-space:nowrap; padding-right:6px; } .kv td:last-child { font-weight:700; text-align:right; overflow-wrap:anywhere; }
  hr { border:0; border-top:1.5px dashed #000; margin:5px 0; }
  .rt { text-align:center; font-weight:800; font-size:13.5px; }
  .pl { margin:6px 0 2px; padding:2px 5px; border:1.5px solid #000; font-weight:800; display:flex; justify-content:space-between; gap:6px; break-after:avoid; }
  table.ps { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.ps td { padding:2px 0 3px; vertical-align:top; border-bottom:1px dotted #000; overflow-wrap:anywhere; }
  table.ps tr { break-inside:avoid; }
  td.st { width:11mm; font-weight:800; font-size:13.5px; } td.pm { width:21mm; text-align:right; font-weight:800; }
  td.nm b { font-weight:700; } td.nm small, td.pm small { display:block; font-size:10.5px; font-weight:400; }
  .sum { width:100%; border-collapse:collapse; } .sum td { padding:1.5px 0; } .sum td:last-child { text-align:right; font-weight:800; white-space:nowrap; }
  .sum tr.big td { font-size:13.5px; font-weight:800; border-top:1.5px solid #000; }
  .sg { display:flex; gap:10px; margin-top:26px; } .sg div { flex:1; border-top:1px solid #000; text-align:center; font-size:10.5px; padding-top:2px; }
  .ft { margin-top:6px; text-align:center; font-size:10px; }
  @media print { body { background:#fff; padding:0; } .no-print { display:none; } .t80 { width:auto; padding:0; } }
</style></head><body>
<div class="no-print">
  <button type="button" class="pri" onclick="window.print()">🖨️ छाप्नुहोस् · Print</button>
  <a href="<?= $e($chalaniLink(['format' => 'chalani'])) ?>">A4 चलानी</a>
  <a href="<?= $e($chalaniLink([])) ?>">← फर्कनुहोस्</a>
</div>
<div class="t80">
  <h1><?= $e($t80Co['legalNe'] !== '' ? $t80Co['legalNe'] : $t80Co['name']) ?></h1>
  <h2>बस चलानी · यात्रु सूची</h2>
  <?php if ($t80Copy['kind'] === 'agent'): ?><div class="cp">एजेन्ट प्रति · AGENT COPY<?= $t80Copy['label'] !== '' ? ' — ' . $e($t80Copy['label']) : '' ?></div>
  <?php elseif ($t80Copy['kind'] === 'desk'): ?><div class="cp">काउन्टर प्रति · DESK COPY — <?= $e($t80Copy['label']) ?></div><?php endif; ?>
  <table class="kv">
    <tr><td>चलानी नं.</td><td><?= $e($chalaniNo) ?></td></tr>
    <tr><td>मिति</td><td><?= $bsNe !== '' ? $e($bsNe) . '<br>' : '' ?><?= $e($neDate($date)) ?></td></tr>
    <tr><td>बस</td><td><?= $e($t80Bus !== '' ? $t80Bus : '—') ?></td></tr>
    <tr><td>प्रस्थान समय</td><td><?= $e($neTime($trip['dep_time'] ?? '')) ?></td></tr>
    <tr><td>चालक</td><td><?= $e(trim((string) ($trip['driver_name'] ?? '')) ?: '—') ?><?= trim((string) ($trip['driver_phone'] ?? '')) !== '' ? '<br>' . $e((string) $trip['driver_phone']) : '' ?></td></tr>
  </table>
  <hr>
  <div class="rt"><?= $e((string) $trip['from_city']) ?> → <?= $e((string) $trip['to_city']) ?></div>
  <table class="kv">
    <tr><td>जम्मा यात्रु</td><td><?= $e($neDigits((string) count($rows))) ?><?= $xInfants > 0 ? ' + शिशु ' . $e($neDigits((string) $xInfants)) : '' ?></td></tr>
    <?php if ($womenSingleCount > 0): ?><tr><td>♀ महिला सिंगल स्लिपर</td><td><?= $e($neDigits((string) $womenSingleCount)) ?></td></tr><?php endif; ?>
  </table>
  <?php foreach ($placeList as $pl): $plRows = $t80Rows[(string) $pl['name']] ?? []; if ($plRows === []) { continue; } ?>
  <div class="pl"><span><?= $e($neDigits((string) $pl['no'])) ?>. <?= $e($pl['name']) ?></span><span><?= $e($neDigits((string) $pl['n'])) ?> जना</span></div>
  <table class="ps"><tbody>
  <?php foreach ($plRows as $r): $pmk = $payMark($r); $m = $money($r); $rInf = (int) $chalaniFmt['infantsOf']($r); $ph = $t80Phone((string) ($r['contact_phone'] ?? '')); ?>
    <tr>
      <td class="st"><?= $e(Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing'))) ?></td>
      <td class="nm"><b><?= $isWomanSingle($r) ? '♀ ' : '' ?><?= $e($r['full_name']) ?></b><?= $rInf > 0 ? ' + शिशु ' . $e($neDigits((string) $rInf)) : '' ?>
        <small>→ <?= $e($stopShort((string) (($r['drop_stop'] ?? '') ?: $trip['to_city']))) ?><?= $ph !== '' ? ' · ' . $e($ph) : '' ?></small></td>
      <td class="pm"><?= $e($pmk['ne']) ?><?php if ($pmk['mode'] !== 'boarding'): ?><small><?= $e($curSym) ?> <?= $e(number_format($m['ticket'] + $m['extra'])) ?></small><?php endif; ?><?= $pmk['claim'] ? '<small>' . $e(ChalaniPng::payClaimLabel('ne')) . '</small>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endforeach; ?>
  <hr>
  <table class="sum">
    <tr><td>जम्मा यात्री <?= $e($neDigits((string) $t80Strip['passengers'])) ?></td><td><?= $e($curSym) ?> <?= $e(number_format($t80Strip['amount'])) ?></td></tr>
    <tr><td>नगद · Cash</td><td><?= $e($curSym) ?> <?= $e(number_format($t80Strip['cash'])) ?></td></tr>
    <tr><td>अनलाइन · Online</td><td><?= $e($curSym) ?> <?= $e(number_format($t80Strip['online'])) ?></td></tr>
    <?php if (ChalaniStrip::hasOther($t80Strip)): ?><tr><td>अन्य भुक्तानी · Other paid</td><td><?= $e($curSym) ?> <?= $e(number_format($t80Strip['other'])) ?></td></tr><?php endif; ?>
    <tr class="big"><td>ड्यू बाँकी · Due<?= $t80Strip['dueN'] > 0 ? ' (' . $e($neDigits((string) $t80Strip['dueN'])) . ' जना)' : '' ?></td><td><?= $e($curSym) ?> <?= $e(number_format($t80Strip['due'])) ?></td></tr>
    <tr><td>बसमा उठाउनु पर्ने</td><td><?= $e($neDigits((string) $totals['boardDueN'])) ?> जना · <?= $e($curSym) ?> <?= $e(number_format($totals['boardDue'])) ?></td></tr>
  </table>
  <div class="sg"><div>कन्डक्टरको सही</div><div>चालकको सही</div></div>
  <div class="ft"><?= $e($t80Co['phone']) ?> · <?= $e($t80Co['web']) ?><br>छापिएको : <?= $e(date('Y-m-d H:i')) ?> IST · कम्प्युटरबाट तयार</div>
</div>
</body></html>
        <?php
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
    /* NP-2: an NPR desk copy keeps the rupees beside its money totals, small */
    $inrNote = static fn(string $k): string => isset($totals['inr'][$k]) ? ' <small>(₹' . Security::e(number_format((float) $totals['inr'][$k])) . ')</small>' : '';
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html><html lang="ne"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>बस चलानी · <?= $e($date) ?> · <?= $e($trip['route_code']) ?></title>
<style>
  @page { size: A4 landscape; margin: 7mm; }
  /* 26 Nov 2026 (fx1): every printed page says which chalani it is and which page -
     page 2 of a long bus used to carry the table head and nothing else. Margin boxes
     print in Chrome / Edge 131+; other browsers ignore the rule and print as before. */
  @page { @bottom-right { content: "<?= $e(preg_replace('/[^A-Za-z0-9\-]/', '', $chalaniNo)) ?> · " counter(page) " / " counter(pages); font-size:8pt; color:#3B4A66; } }
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
  .band .chs b.ok { color:#15803D; } .band .chs b.bad { color:#B91C1C; }   /* 25 Nov 2026 (release ch) */
  .band .flg { vertical-align:-2px; margin:0 2px; border-radius:1px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }

  table.ps { width:100%; border-collapse:collapse; table-layout:fixed; }
  table.ps th, table.ps td { border:1px solid #16233C; padding:2px 5px; font-size:10.5px; height:19px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
  table.ps th { background:#EEF2F8; font-weight:600; text-align:left; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.ps th.c, table.ps td.c { text-align:center; } table.ps th.r, table.ps td.r { text-align:right; }
  table.ps td.n { color:#5C6B85; }
  table.ps td small { color:#5C6B85; font-size:8.5px; }
  /* 26 Nov 2026 (fx1): in the name cell the NAME takes the "…" - the border ID and the
     ticket number after it always print whole (they were the part the cell cut off) */
  /* how it gives way (26 Nov 2026, fx4): ONE line, and the NAME is the only piece that
     shrinks - a long name takes the "…" while the infant mark, the border ID and the
     ticket number keep their full width. (It used to wrap them onto a hidden second
     line, so a long name silently dropped the ID the border desk reads.) */
  table.ps td .nmw { display:flex; flex-wrap:nowrap; align-items:baseline; column-gap:5px; height:17px; line-height:17px; margin:-1px 0; overflow:hidden; }
  table.ps td .nmw .nmn { flex:0 1 auto; min-width:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; }
  table.ps td .nmw small, table.ps td .nmw b.inf { flex:0 0 auto; white-space:nowrap; }
  table.ps td .nmw small:last-child { margin-left:auto; }
  table.ps td small.pid { color:#12264E; font-weight:700; }
  /* the booking phone's GPS place: one short line at the end of the remarks cell (it
     used to sit under the mobile number and doubled the height of every such row) */
  table.ps td a.geo { color:#12264E; text-decoration:none; font-size:8.5px; }
  .late { color:#8a1111; font-weight:700; }
  /* 6 Nov 2026: place strip, place numbers, women on a single sleeper */
  .pl { border:1.5px solid #12264E; border-radius:6px; padding:5px 9px; margin:0 0 6px; }
  .pl-bus { font-size:12.5px; color:#12264E; margin-bottom:3px; }
  .pl-ws { background:#F8BBD9; color:#880E4F; font-weight:800; padding:0 7px; border-radius:9px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .pl-list { display:flex; flex-wrap:wrap; gap:4px 14px; font-size:11px; }
  .pl-list i, i.pno { font-style:normal; display:inline-block; min-width:16px; height:16px; line-height:16px; text-align:center; border-radius:50%; background:#12264E; color:#fff; font-size:9.5px; font-weight:800; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.ps tr.ws td { background:#FCE4EC; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .wsb { color:#C2185B; }
  /* 16 Nov 2026: special (extra) payments — paid or not, plus pen lines */
  .xp { border:1.5px solid #12264E; border-radius:6px; padding:6px 9px 7px; margin-top:8px; break-inside:avoid; }
  .xp h3 { margin:0 0 5px; font-size:10.5px; letter-spacing:.4px; color:#12264E; }
  .xp table { width:100%; border-collapse:collapse; table-layout:fixed; }
  .xp th, .xp td { border:1px solid #8090A8; padding:2px 5px; font-size:10px; height:18px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; text-align:left; }
  .xp th { background:#EEF2F8; font-weight:600; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .xp .r { text-align:right; }
  .xp tr.pen td { height:21px; color:#5C6B85; }
  .xok { color:#15803D; } .xdue { color:#B91C1C; }
  /* 16 Nov 2026: how the row pays - बसमा ₹X (red) · नगद ✓ / अनलाइन ✓ (green) · एजेन्टसँग / जाँचमा (amber) */
  b.pm { font-weight:700; } .pm-boarding, .pm-due { color:#B91C1C; } .pm-cash, .pm-online, .pm-paid { color:#15803D; } .pm-agent, .pm-pending { color:#B45309; }
  .sum span.bd, .sum b.bd { color:#B91C1C; font-weight:700; }
  b.inf { color:#7C2D12; font-size:9.5px; }
  .sum .penl { display:flex; gap:6px; align-items:flex-end; } .sum .penl i { flex:1; border-bottom:1px dotted #5C6B85; min-height:12px; }
  .sum b.penb { border-bottom:0; font-weight:400; color:#5C6B85; white-space:nowrap; }
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
  <?php if (ChalaniStrip::thermalOn()): ?><a href="<?= $e($chalaniLink(['format' => 'chalani80'])) ?>">🧾 ८० मिमि</a><?php endif; ?>
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
    <?php /* 26 Nov 2026 (fx1): a desk's copy says so too (a rupee desk's copy read as the whole bus) */ ?>
    <?php if ($deskF !== ''): ?><div class="kv" style="color:#b45309;font-weight:800">काउन्टर प्रति · DESK COPY — <?= $e(CounterDesk::label($deskF)) ?> को बिक्री मात्र</div><?php endif; ?>
    <div class="rt"><?= $e($corridorNe) ?> · <?= $e($coachNe((string) $trip['coach_type'])) ?></div>
    <div class="kv">चलानी नं. : <span><?= $e($chalaniNo) ?></span></div>
    <div class="kv">मिति : <span><?= $bsNe !== '' ? $e($bsNe) . ' · ' : '' ?><?= $e($neDate($date)) ?></span></div>
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
<p class="stops"><b>चढ्ने ठाउँहरू :</b> <?= $e($stopsLine) ?> &nbsp;·&nbsp; <?php if ($toNepal): ?><b>सीमा :</b> रुपैडिहा · जमुनाहा &nbsp;·&nbsp; <b>अगाडि :</b> नेपालगंज, बाँके<?php else: /* 26 Nov 2026 (fx1): the return run crosses the other way */ ?><b>सीमा :</b> जमुनाहा · रुपैडिहा &nbsp;·&nbsp; <b>गन्तव्य :</b> गुजरात<?php endif; ?></p>
<?php endif; ?>

<?php /* 6 Nov 2026: place-wise head-count, bus name and the women-on-single count, in one strip. */ ?>
<div class="pl">
  <div class="pl-bus">🚌 <b><?= $e(trim((string) ($trip['bus_name'] ?? '') . ' ' . (string) ($trip['bus_number'] ?? ''))) ?: 'बस' ?></b>
    · जम्मा यात्रु <b><?= $e($neDigits((string) count($rows))) ?></b>
    <?php if ($xInfants > 0): ?> + शिशु <b><?= $e($neDigits((string) $xInfants)) ?></b> <small>(काखमा, बर्थ बिना)</small><?php endif; ?>
    <?php if ($womenSingleCount > 0): ?> · <span class="pl-ws">♀ महिला सिंगल <?= $e($neDigits((string) $womenSingleCount)) ?></span><?php endif; ?></div>
  <div class="pl-list">
    <?php foreach ($placeList as $pl): ?>
      <span><i><?= $e($neDigits((string) $pl['no'])) ?></i> <?= $e($pl['name']) ?> — <b><?= $e($neDigits((string) $pl['n'])) ?></b></span>
    <?php endforeach; ?>
  </div>
</div>

<div class="band"><span><?= $toNepal ? $flagSvg('IN') : $flagSvg('NP', 15) ?> <?= $e($corridorNe) ?> <?= $toNepal ? $flagSvg('NP', 15) : $flagSvg('IN') ?> · यात्रु यात्रा अभिलेख</span><?php
  /* 25 Nov 2026 (release ch): the same Total | Cash | Online | Due strip as the screen, PDF and PNG */
  $shStrip = ChalaniStrip::on() ? ChalaniStrip::fromRows($rows, $money, $payMark) : null;
  if ($shStrip !== null): ?><span class="chs"><b>जम्मा यात्री <?= $e($neDigits((string) $shStrip['passengers'])) ?> · <?= $e($curSym) ?> <?= $e(number_format($shStrip['amount'])) ?></b> | <b class="ok">नगद <?= $e($curSym) ?> <?= $e(number_format($shStrip['cash'])) ?></b> | <b class="ok">अनलाइन <?= $e($curSym) ?> <?= $e(number_format($shStrip['online'])) ?></b> | <b class="<?= $shStrip['due'] > 0 ? 'bad' : 'ok' ?>">ड्यू बाँकी <?= $e($curSym) ?> <?= $e(number_format($shStrip['due'])) ?></b><?php if (ChalaniStrip::hasOther($shStrip)): ?> | <b>अन्य भुक्तानी <?= $e($curSym) ?> <?= $e(number_format($shStrip['other'])) ?></b><?php endif; ?></span><?php else: ?><span>सिट · नाम · भुक्तानी विवरण</span><?php endif; ?></div>

<table class="ps">
  <colgroup>
    <col style="width:26px"><col style="width:44px"><col style="width:232px"><col style="width:84px">
    <col style="width:112px"><col style="width:112px"><col style="width:64px"><col style="width:64px">
    <col style="width:70px"><col style="width:52px"><col>
  </colgroup>
  <thead><tr>
    <th class="c">#</th><th class="c">सिट</th><th>यात्रुको नाम</th><th>मोबाइल नं.</th>
    <th>चढ्ने</th><th>ओर्लिने</th><th class="r">टिकट <?= $e($curSym) ?></th><th class="r">नगद <?= $e($curSym) ?></th><th class="r">अनलाइन <?= $e($curSym) ?></th>
    <th class="c">प्रा.लि.</th><th>कैफियत</th>
  </tr></thead>
  <tbody>
  <?php for ($i = 0; $i < $sheetRows; $i++): $r = $rows[$i] ?? null; $m = $r ? $money($r) : null;
        $ws = $r !== null && $isWomanSingle($r);
        $rInf = $r !== null ? (int) $chalaniFmt['infantsOf']($r) : 0; ?>
    <tr<?= $ws ? ' class="ws"' : '' ?>>
      <td class="c n"><?= $e($neDigits((string) ($i + 1))) ?></td>
      <td class="c"><b><?= $r ? $e(Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing'))) : '' ?></b></td>
      <td><?php if ($r): $pidT = is_callable($chalaniFmt['paxId'] ?? null) ? (string) $chalaniFmt['paxId']($r) : ''; ?><span class="nmw"><span class="nmn"><?= $ws ? '<b class="wsb">♀</b> ' : '' ?><?= $e($r['full_name']) ?></span><?php if ($rInf > 0): ?><b class="inf">+ शिशु <?= $e($neDigits((string) $rInf)) ?></b><?php endif; ?><?php if ($pidT !== ''): ?><small class="pid">🪪 <?= $e($pidT) ?></small><?php endif; ?><small><?= $e($r['ticket_number'] ?: $r['pnr']) ?><?php if ($isLate($r)): ?> · <span class="late">बस चलेपछि</span><?php endif; ?></small></span><?php endif; ?></td>
      <td><?= $r ? $e($r['contact_phone'] === '0000000000' ? '' : $r['contact_phone']) : '' ?></td>
      <td><?php if ($r): $pk = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—'; ?><i class="pno"><?= $e($neDigits((string) ($placeList[$pk]['no'] ?? ''))) ?></i> <?= $e($pk) ?><?php endif; ?></td>
      <td><?= $r ? $e($stopShort((string) $r['drop_stop'])) : '' ?></td>
      <td class="r"><?= $m ? $e(number_format($m['ticket'])) : '' ?></td>
      <td class="r"><?= ($m && $m['cash'] > 0) ? $e(number_format($m['cash'])) : '' ?></td>
      <td class="r"><?= ($m && $m['online'] > 0) ? $e(number_format($m['online'])) : '' ?></td>
      <td class="c"></td>
      <td><?php if ($r): $pmk = $payMark($r); ?><b class="pm pm-<?= $e($pmk['mode']) ?>"><?= $e($pmk['ne']) ?></b><?= $pmk['claim'] ? ' <b class="pm pm-pending">' . $e(ChalaniPng::payClaimLabel('ne')) . '</b>' : '' ?> · <?php endif; ?><?= $r ? $e($bookedByShort($r) === 'ONLINE' ? 'अनलाइन' : ($bookedByShort($r) === 'OFFICE' ? 'कार्यालय' : ($bookedByShort($r) === 'COUNTER' ? 'काउन्टर' : $bookedByShort($r)))) : '' ?><?php if ($m && $m['extra'] > 0): $xs = $chalaniFmt['rowState']($r); ?> · <b class="<?= $xCls($xs) ?>">थप <?= $e($curSym) ?><?= $e(number_format($m['extra'])) ?> <?= $e($xStateNe($xs)) ?></b><?php endif; ?><?php
        /* 25 Nov 2026 (cp1): where the ticket was cut - the booking phone's GPS (BookGeo).
           26 Nov 2026 (fx1): one short line here, last in the remarks (lowest priority) */
        if ($r && !empty($r['booked_lat'])): require_once INCLUDE_PATH . '/bookgeo.php'; ?> · <a class="geo" href="<?= $e(BookGeo::mapUrl($r)) ?>" target="_blank" rel="noopener" title="<?= $e(BookGeo::line($r)) ?>">&#128205; <?= $e(mb_strimwidth((string) ($r['booked_place'] ?: 'GPS'), 0, 16, '…')) ?></a><?php endif; ?></td>
    </tr>
  <?php endfor; ?>
  </tbody>
</table>

<?php /* 16 Nov 2026 (owner: "chalani ko lagi bisesh payment bhayo ki nai thapaune line rakheu"):
         every booking with desk extras on its own line — what, how much, came in or not —
         and blank lines for what the crew collects on the road. With no extras on the bus
         the blank lines sit inside the payment summary instead (same as the PDF). */ ?>
<?php if ($xLines !== []): ?>
<div class="xp">
  <h3>विशेष भुक्तानी · थप सामान / शिशु / अन्य — भयो कि भएन</h3>
  <table>
    <colgroup><col style="width:150px"><col style="width:170px"><col style="width:110px"><col><col style="width:90px"><col style="width:150px"></colgroup>
    <thead><tr><th>PNR</th><th>यात्रु</th><th>सिट</th><th>विवरण</th><th class="r">रकम <?= $e($curSym) ?></th><th>भयो / भएन</th></tr></thead>
    <tbody>
    <?php foreach ($xLines as $xl): ?>
      <tr>
        <td><?= $e($xl['pnr']) ?></td><td><?= $e($xl['lead']) ?></td><td><?= $e($xl['seatsLbl']) ?></td>
        <td><?= $xl['lug'] > 0 ? 'थप सामान ' . $e($neDigits((string) $xl['lug'])) . ' थान ' . $e($curSym) . $e(number_format($xl['lugFee'])) : '' ?><?= ($xl['lug'] > 0 && $xl['inf'] > 0) ? ' · ' : '' ?><?= $xl['inf'] > 0 ? 'शिशु ' . $e($neDigits((string) $xl['inf'])) . ' ' . ($xl['infFee'] > 0 ? $e($curSym) . $e(number_format($xl['infFee'])) : '(निःशुल्क)') : '' ?></td>
        <td class="r"><b><?= $e(number_format($xl['amt'])) ?></b></td>
        <td><b class="<?= $xCls($xl['state']) ?>"><?= $e($xStateNe($xl['state'])) ?></b></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($p = 0; $p < (int) $chalaniFmt['penLines']; $p++): ?>
      <tr class="pen"><td></td><td></td><td></td><td></td><td></td><td>भयो ☐ &nbsp; भएन ☐</td></tr>
    <?php endfor; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="bottom">
  <div class="box">
    <h3>भुक्तानी सारांश<?= isset($chalaniFmt['cur']) ? ' · ' . $e($chalaniFmt['cur']['label']) . ' · नेपाल काउन्टर प्रति (' . $e($chalaniFmt['cur']['code']) . ')' : '' ?></h3>
    <div class="sum">
      <span>जम्मा यात्रु</span><b><?= $e($neDigits((string) $totals['passengers'])) ?></b>
      <span>जम्मा टिकट रकम</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['ticket'])) ?></b>
      <span>नगद संकलन</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['cash'])) ?><?= $inrNote('cash') ?></b>
      <span>अनलाइन / UPI प्राप्ति</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['online'])) ?><?= $inrNote('online') ?></b>
      <?php /* 26 Nov 2026 (fx1): the strip above counts a verified payment the office "marked due" as
               ड्यू; the two lines above keep it. One line says how much, so the sheet agrees with itself. */
            $mkDue = $shStrip !== null ? (float) $shStrip['markedCash'] + (float) $shStrip['markedOnline'] : 0.0;
            if ($mkDue > 0): ?>
      <span class="bd">&nbsp;&nbsp;↳ यसमध्ये कार्यालयले बाँकी राखेको</span><b class="bd"><?= $e($curSym) ?> <?= $e(number_format($mkDue)) ?></b>
      <?php endif; ?>
      <?php $bdCls = $totals['boardDue'] > 0 ? ' class="bd"' : ''; ?>
      <span<?= $bdCls ?>>बसमा उठाउनु पर्ने · To collect on boarding</span><b<?= $bdCls ?>><?= $e($neDigits((string) $totals['boardDueN'])) ?> जना · <?= $e($curSym) ?> <?= $e(number_format($totals['boardDue'])) ?></b>
      <span>छुट / सहुलियत</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['discount'])) ?></b>
      <?php if ($xLines !== []): ?>
      <span>विशेष भुक्तानी (थप सामान / शिशु)</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['extras'])) ?></b>
      <span>&nbsp;&nbsp;↳ भयो / बाँकी</span><b><?= $e($curSym) ?> <?= $e(number_format($totals['extrasPaid'])) ?> / <?= $e(number_format($totals['extrasDue'])) ?></b>
      <?php else: for ($p = 0; $p < min(2, (int) $chalaniFmt['penLines']); $p++): ?>
      <span class="penl">विशेष भुक्तानी <i></i></span><b class="penb">भयो ☐ &nbsp; भएन ☐</b>
      <?php endfor; endif; ?>
      <span class="net">खुद जम्मा संकलन</span><b class="net"><?= $e($curSym) ?> <?= $e(number_format($totals['net'])) ?><?= $inrNote('net') ?></b>
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
  <span>यो एस हरि ग्लोबल प्राइभेट लिमिटेडको आधिकारिक यात्रा कागजात हो। कम्पनी अभिलेखका लागि सुरक्षित राख्नुहोस्। अधिकृत सही / छापसँग मात्र मान्य। रुट : <?= $e($corridorNe) ?> (<?= $e($landsNe) ?>)। CIN <?= $e($cin) ?> · कर्पोरेट मामिला मन्त्रालय, भारत सरकार दर्ता</span>
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
    $mfMoney[$st] += $farePer($r) + $extraOnRow($r);   // 16 Nov 2026: + the booking's desk extras, once
    $mfCount[$st]++;
}
/* 16 Nov 2026 (owner: "chalani ma seat ma sabai ma thik hos"): free = berths
   not taken. A private double cabin is ONE passenger on TWO berths, so
   "total - passengers" said one more free berth than the bus had. */
$bedsTaken = count(array_filter(array_keys($seatInfo), static fn($b): bool => !Seats::isExtraSeat((string) $b)));
$xScreenPaid = 0.0; $xScreenDue = 0.0;
foreach ($xBook as $xb) { if ($xb['state'] === 'due') { $xScreenDue += $xb['amt']; } else { $xScreenPaid += $xb['amt']; } }
/* 16 Nov 2026: नगद / अनलाइन on the screen, from the same $money rule as the sheet's columns */
$scrCash = 0.0; $scrOnline = 0.0;
foreach ($rows as $r) { $sm = $money($r); $scrCash += $sm['cash']; $scrOnline += $sm['online']; }
$revenue  = 0.0;
$seenPnr  = [];
foreach ($rows as $r) {
    if (isset($seenPnr[$r['pnr']])) continue;
    $seenPnr[$r['pnr']] = true;
    $revenue += (float) $r['total_amount'];
}
/* 25 Nov 2026 (release ch, uniform chalani): the strip and the row badges, from the
   same $dm the sheet, PDF and PNG read (ChalaniStrip). A Nepal desk copy in NPR. */
$chStripOn = ChalaniStrip::on();
$chCur     = $dm['cur'] !== null ? (string) $dm['cur']['code'] : 'INR';
$scrStrip  = ChalaniStrip::fromDm($dm, $dm['cur'] !== null);
$chExport  = $scopeId === null ? ChalaniStrip::mayExport() : Settings::getBool('agent_chalani_on', true);
$chPoll    = ChalaniStrip::pollOn() && $trip !== null;
$chCounterView = ChalaniStrip::counterView();
$chCounter = null;
if ($chCounterView !== null) {
    try { $chCounter = ChalaniStrip::counterToday($chCounterView, (int) $admin['id']); } catch (Throwable $ex) { $chCounter = null; }
}
$chBadge = static function (array $r) use ($scrStrip): array {
    $l = $scrStrip['rows'][(int) ($r['pax_id'] ?? 0)] ?? null;
    return $l === null ? ChalaniStrip::badge('due', false)
        : ChalaniStrip::badge((string) $l['mode'], (bool) $l['paid'], ((float) $l['markedCash'] + (float) $l['markedOnline']) > 0.0);
};
$chLink = static fn(array $extra): string => $base . '/admin/manifest.php?' . http_build_query(array_filter(array_merge(
    ['date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null, 'desk' => $deskF !== '' ? $deskF : null], $extra)));
$chPaxKey = (static function (array $rows): string {
    $ids = array_map(static fn(array $x): int => (int) $x['pax_id'], $rows);
    sort($ids);
    return md5(implode(',', $ids));
})($rows);
?>
<style>
  /* 25 Nov 2026 (release ch): money strip, Paid / Due badge, today's counter money, live note */
  .mf-strip { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; margin:12px 0 0; }
  .mf-strip.has-other { grid-template-columns:repeat(5,minmax(0,1fr)); }   /* review: "अन्य भुक्तानी" only when not zero */
  .mf-strip .s-other { border-left-color:#B08A2E; } .mf-strip .s-other[hidden] { display:none; }
  @media (max-width:640px) { .mf-strip, .mf-strip.has-other { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  .mf-strip > div { border:1px solid var(--line,#dcdcdc); border-left:5px solid #0B3D91; border-radius:10px; padding:7px 10px; background:var(--card,#fff); min-width:0; }
  .mf-strip small { display:block; font-size:11.5px; font-weight:700; color:var(--mut,#555); }
  .mf-strip b { display:block; font-size:17px; font-weight:750; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
  .mf-strip .s-cash, .mf-strip .s-online { border-left-color:#16A34A; } .mf-strip .s-cash b, .mf-strip .s-online b { color:#15803D; }
  .mf-strip .s-due { border-left-color:#DC2626; } .mf-strip .s-due b { color:#B91C1C; }
  .mf-strip .s-due.zero { border-left-color:#16A34A; } .mf-strip .s-due.zero b { color:#15803D; }
  :root[data-theme="dark"] .mf-strip .s-cash b, :root[data-theme="dark"] .mf-strip .s-online b, :root[data-theme="dark"] .mf-strip .s-due.zero b { color:#6EE7A0; }
  :root[data-theme="dark"] .mf-strip .s-due b { color:#FCA5A5; }
  .mf-pd { display:inline-block; border-radius:6px; padding:2px 8px; font-size:12px; font-weight:750; margin:0 0 3px; white-space:normal; }
  .mf-pd.mf-pd-paid { background:#16A34A; color:#fff; } .mf-pd.mf-pd-due { background:#DC2626; color:#fff; }
  .mf-live { font-size:12px; color:var(--mut,#555); margin-top:6px; }
  .mf-live button { margin-left:6px; min-height:32px; border-radius:8px; border:1px solid #1D4FA3; background:#EEF4FF; color:#1D4FA3; font:inherit; font-weight:700; padding:0 10px; cursor:pointer; }
  .mf-live.stale { color:#B45309; font-weight:700; }
  details.mf-ctr { border:1px solid var(--line,#dcdcdc); border-radius:12px; padding:8px 12px; margin:0 0 12px; background:var(--card,#fff); }
  details.mf-ctr summary { cursor:pointer; font-weight:750; font-size:15px; padding:4px 0; }
  .mf-ctr-scroll { overflow-x:auto; }
  .mf-ctr table { width:100%; border-collapse:collapse; font-size:13.5px; margin-top:6px; }
  .mf-ctr th, .mf-ctr td { padding:6px 6px; border-bottom:1px solid var(--line,#e4e4e4); text-align:left; }
  .mf-ctr .r { text-align:right; font-variant-numeric:tabular-nums; }
  .mf-ctr .due { color:#B91C1C; font-weight:700; } :root[data-theme="dark"] .mf-ctr .due { color:#FCA5A5; }
  .mf-ctr h4 { margin:10px 0 2px; font-size:13px; color:var(--mut,#555); }
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
  /* 26 Nov 2026 (fx1): dark theme. The women-single row kept its pale pink under the
     theme's near-white text (unreadable); the seat chart's white cards carried a light
     grey heading; the legend, the late / priority notes and the women count were dark
     ink on the dark page. Colours only - nothing moves. */
  :root[data-theme="dark"] tr.mf-ws td { background:rgba(236,72,153,.24) !important; }
  :root[data-theme="dark"] .scx-deck h4 { color:#475569; }
  :root[data-theme="dark"] .scx-leg { color:var(--mut,#9AA8C0); }
  .mf-wsn { color:#880E4F; } :root[data-theme="dark"] .mf-wsn { color:#F9A8D4; }
  .mf-late { color:#8a1111; font-weight:700; } :root[data-theme="dark"] .mf-late { color:#FCA5A5; }
  .mf-prio { color:#0f5c36; font-weight:700; } :root[data-theme="dark"] .mf-prio { color:#6EE7A0; }
  a.mf-geo { display:inline-block; margin-top:3px; font-size:12px; color:#1D4FA3; text-decoration:none; white-space:normal; overflow-wrap:anywhere; font-family:inherit; }
  :root[data-theme="dark"] a.mf-geo { color:#BFDBFE; }
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
  /* 14 Nov 2026 (staff 360px audit): on a touch phone the row buttons
     (Transfer / Seat / Mark due / Delete), the fine box and the tools row are
     finger-sized. Desktop keeps the compact 30 / 36px. !important only beats
     the fine box's inline min-height:30px. */
  /* 26 Nov 2026 (fx1): 44 px, not 40 (the panel's touch rule); typed boxes at 16 px so
     a phone does not zoom the page on focus; the call / WhatsApp / map circles were
     22 px - too small to hit on a moving bus. Touch phones only. */
  @media (max-width:820px) and (pointer:coarse) {
    .mf-tools .btn, .mf-tools input, .mf-tools select, .mf-link input { min-height:44px; }
    .mf-acts .btn, .mf-pay .btn, .mf-pay select, .mf-pay input { min-height:44px; }
    .mf-acts input { min-height:44px !important; }
    .mf-tools input, .mf-tools select, .mf-link input, .mf-pay select, .mf-pay input, .mf-acts input { font-size:16px; }
    .mf-live button { min-height:44px; }
    .wa-mini { width:44px; height:44px; margin:2px 2px 2px 0; }
    .wa-mini svg, .wa-mini .a-ic { width:20px; height:20px; }
    a.mf-geo { min-height:44px; padding:10px 0; font-size:13px; }
    details.mf-chart summary, details.mf-ctr summary { padding:12px 0; }
  }
  /* the place list's total row is a card on a phone: no rule above each line */
  @media (max-width:820px) { table.mf-pl.card-table tfoot td { border-top:0; } }

  /* 16 Nov 2026: pay at boarding - the row's pay mark and the crew's 💳 QR */
  .mf-pm { display:inline-block; max-width:100%; margin-top:3px; border-radius:6px; padding:1px 7px; font-size:12px; font-weight:700; border:1px solid transparent; }
  .mf-money .pc-badge { white-space:normal; max-width:100%; }   /* the long "To collect on boarding" line wraps on a 360px phone */
  .mf-pm.pm-boarding, .mf-pm.pm-due { background:#FEF2F2; color:#B91C1C; border-color:#F6C9C9; }
  .mf-pm.pm-cash, .mf-pm.pm-online, .mf-pm.pm-paid { background:#ECFDF3; color:#15803D; border-color:#BBF0CF; }
  .mf-pm.pm-agent, .mf-pm.pm-pending { background:#FFF7E8; color:#B45309; border-color:#F5D9A8; }
  :root[data-theme="dark"] .mf-pm.pm-boarding, :root[data-theme="dark"] .mf-pm.pm-due { background:rgba(220,38,38,.14); border-color:rgba(220,38,38,.35); color:#FCA5A5; }
  :root[data-theme="dark"] .mf-pm.pm-cash, :root[data-theme="dark"] .mf-pm.pm-online, :root[data-theme="dark"] .mf-pm.pm-paid { background:rgba(22,163,74,.15); border-color:rgba(22,163,74,.35); color:#6EE7A0; }
  :root[data-theme="dark"] .mf-pm.pm-agent, :root[data-theme="dark"] .mf-pm.pm-pending { background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.35); color:#FBC56B; }
  .mf-qr { display:inline-flex; align-items:center; gap:4px; margin:3px 0 0 4px; min-height:30px; padding:0 10px; border-radius:7px; border:1px solid #1D4FA3; background:#EEF4FF; color:#1D4FA3; font:inherit; font-size:12.5px; font-weight:700; cursor:pointer; white-space:nowrap; }
  :root[data-theme="dark"] .mf-qr { background:rgba(59,130,246,.16); border-color:rgba(147,197,253,.5); color:#BFDBFE; }
  @media (max-width:820px) and (pointer:coarse) { .mf-qr { min-height:44px; } }
  dialog.pq { width:min(92vw, 360px); max-width:calc(100vw - 32px); border:1px solid var(--line); border-radius:14px; padding:14px 16px 16px; background:var(--card); color:var(--ink); }
  dialog.pq::backdrop { background:rgba(8,15,30,.55); }
  .pq h3 { margin:0 28px 4px 0; font-size:16px; }
  .pq .pq-x { position:absolute; top:8px; right:8px; width:36px; height:36px; border-radius:50%; border:1px solid var(--line); background:var(--card); color:var(--ink); font-size:16px; cursor:pointer; }
  .pq p { margin:4px 0; font-size:13px; color:var(--mut); overflow-wrap:anywhere; }
  .pq .pq-amt { font-size:22px; font-weight:800; color:#B91C1C; margin:2px 0 6px; }
  :root[data-theme="dark"] .pq .pq-amt { color:#FCA5A5; }
  .pq .pq-img { background:#fff; border-radius:10px; padding:8px; display:flex; justify-content:center; margin:6px 0; }
  .pq .pq-img img { width:100%; max-width:240px; height:auto; aspect-ratio:1/1; image-rendering:pixelated; }
  .pq .pq-link { display:flex; gap:6px; margin-top:8px; }
  .pq .pq-link input { flex:1 1 auto; min-width:0; min-height:38px; border-radius:8px; border:1px solid var(--line); background:var(--bg); color:var(--ink); padding:0 8px; font-size:12px; }
  .pq .pq-link button, .pq .pq-link a { display:inline-flex; align-items:center; min-height:38px; padding:0 10px; border-radius:8px; border:1px solid var(--line); background:var(--card); color:var(--ink); font:inherit; font-size:12.5px; font-weight:650; text-decoration:none; cursor:pointer; }

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

  <?php /* 19 Nov 2026 (NP-2): one desk's sales only - a Nepal desk's chalani prints NPR */ ?>
  <?php if (CounterDesk::stampColumn()): ?>
  <label>Desk · काउन्टर<br>
    <select name="desk" onchange="this.form.submit()">
      <option value="">All desks · सबै</option>
      <?php foreach (CounterDesk::all() as $dc => $dd): ?>
        <option value="<?= Security::e((string) $dc) ?>" <?= $deskF === (string) $dc ? 'selected' : '' ?>><?= Security::e(CounterDesk::label((string) $dc) . ((string) ($dd['currency'] ?? 'INR') === 'NPR' ? ' · NPR' : '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

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
    <a class="btn ghost" href="<?= $base ?>/admin/manifest.php?<?= Security::e(http_build_query(array_filter(['date' => $date, 'route' => $routeId, 'sid' => $sidReq > 0 ? $sidReq : null, 'order' => $order !== 'group' ? $order : null, 'desk' => $deskF !== '' ? $deskF : null, 'format' => 'csv']))) ?>"><?= pc_icon('download') ?> CSV</a>
    <?php /* 25 Nov 2026 (release ch): PDF + PNG one click, and a WhatsApp share of the PNG links (chalani.export) */ ?>
    <?php if ($chExport): ?>
    <a class="btn" href="<?= Security::e($chLink(['format' => 'chalanipdf'])) ?>"><?= pc_icon('download') ?> PDF</a>
    <a class="btn" href="<?= Security::e($chLink(['format' => 'chalanipng'])) ?>"><?= pc_icon('image') ?> PNG</a>
    <?php /* 26 Nov 2026 (fx1): the 80 mm thermal list for the counter's receipt printer (switch chalani_thermal_on) */ ?>
    <?php if (ChalaniStrip::thermalOn()): ?><a class="btn" target="_blank" rel="noopener" href="<?= Security::e($chLink(['format' => 'chalani80'])) ?>" title="८० मिमि थर्मल प्रिन्टरका लागि चलानी · Chalani for the 80 mm thermal printer"><?= pc_icon('printer') ?> ८० मिमि · 80 mm</a><?php endif; ?>
    <?php if ($scopeId === null): ?><a class="btn" target="_blank" rel="noopener" href="<?= Security::e($chLink(['format' => 'chalanishare'])) ?>" title="PNG पेजको लिंक WhatsApp मा पठाउनुहोस् · Share the PNG page links on WhatsApp">💬 WhatsApp</a><?php endif; ?>
    <?php endif; ?>
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
      <span><b><?= max(0, (int) $trip['total_seats'] - $bedsTaken) ?></b> seats free</span>
      <?php if ($xInfants > 0): ?><span><b>+<?= (int) $xInfants ?></b> शिशु · infants (lap)</span><?php endif; ?>
      <span><b><?= $boarded ?></b> boarded</span>
      <span><b><?= $unpaid ?></b> unpaid</span>
      <span><b><?= Security::e(inr($revenue)) ?></b> booked value</span>
      <?php if ($womenSingleCount > 0): ?><span class="mf-wsn"><b>♀ <?= (int) $womenSingleCount ?></b> women on single sleeper</span><?php endif; ?>
    </div>
    <div class="mf-money">
      <?= pc_badge('Paid ' . $mfCount['paid'] . ' · ' . inr($mfMoney['paid']), 'ok') ?>
      <?= pc_badge('With agents ' . $mfCount['agent'] . ' · ' . inr($mfMoney['agent']), 'warn') ?>
      <?= pc_badge('Due ' . $mfCount['due'] . ' · ' . inr($mfMoney['due']), 'bad') ?>
      <?php /* 16 Nov 2026: cash vs online that came in, and the pay-at-boarding money the crew still collects */ ?>
      <?= pc_badge('नगद · Cash ' . inr($scrCash) . ' — अनलाइन · Online ' . inr($scrOnline), 'info') ?>
      <?= pc_badge('बसमा उठाउनु पर्ने · To collect on boarding: ' . $boardDueN . ' जना · ' . inr($boardDue), $boardDue > 0 ? 'bad' : 'ok') ?>
      <?php if ($xBook !== []): ?>
        <?= pc_badge('विशेष भुक्तानी · Extras ' . inr($xScreenPaid + $xScreenDue) . ' — भयो ' . inr($xScreenPaid) . ' · बाँकी ' . inr($xScreenDue), $xScreenDue > 0 ? 'warn' : 'ok') ?>
      <?php endif; ?>
    </div>
    <?php if ($chStripOn): $chL = ChalaniStrip::labels(); $chOther = ChalaniStrip::hasOther($scrStrip); ?>
    <div class="mf-strip<?= $chOther ? ' has-other' : '' ?>" id="mfStrip" aria-live="polite">
      <div class="s-pax"><small><?= Security::e($chL['pax']) ?></small><b data-strip="pax"><?= Security::e($scrStrip['passengers'] . ' · ' . ChalaniStrip::money($scrStrip['amount'], $chCur)) ?></b></div>
      <div class="s-cash"><small><?= Security::e($chL['cash']) ?></small><b data-strip="cash"><?= Security::e(ChalaniStrip::money($scrStrip['cash'], $chCur)) ?></b></div>
      <div class="s-online"><small><?= Security::e($chL['online']) ?></small><b data-strip="online"><?= Security::e(ChalaniStrip::money($scrStrip['online'], $chCur)) ?></b></div>
      <div class="s-due<?= $scrStrip['due'] > 0 ? '' : ' zero' ?>"><small><?= Security::e($chL['due']) ?></small><b data-strip="due"><?= Security::e(ChalaniStrip::money($scrStrip['due'], $chCur) . ($scrStrip['dueN'] > 0 ? ' · ' . $scrStrip['dueN'] . ' जना' : '')) ?></b></div>
      <?php /* review 25 Nov 2026: settled but neither cash nor online (an office tick with no
               verified payment) - its own figure, so the boxes always add up to the Total */ ?>
      <div class="s-other"<?= $chOther ? '' : ' hidden' ?> title="कार्यालयले तिरेको भनेको, तर नगद / अनलाइन थाहा छैन · Paid per the office, way not known"><small><?= Security::e($chL['other']) ?></small><b data-strip="other"><?= Security::e(ChalaniStrip::money($scrStrip['other'], $chCur)) ?></b></div>
    </div>
    <?php endif; ?>
    <?php if ($chPoll): ?><div class="mf-live no-print" id="mfLive">⟳ हरेक ३० सेकेन्डमा आफैं अपडेट · Updates itself every 30 s<span data-live="at"></span></div><?php endif; ?>
  </div>

  <?php /* 25 Nov 2026 (release ch): आजको काउन्टर हिसाब - today's money at this counter
           (CounterBook: taken today, cash / online, pay at boarding still to collect) and
           per person who cut tickets today. Counter and office logins only, never an agent. */ ?>
  <?php if (is_array($chCounter)): $ct = $chCounter['totals']; ?>
  <details class="mf-ctr no-print" id="mfCounter">
    <summary>आजको काउन्टर हिसाब · Today's counter money<?= $chCounter['deskName'] !== '' ? ' — ' . Security::e($chCounter['deskName']) : '' ?>
      · नगद <?= Security::e(inr((float) $ct['cash'])) ?> · अनलाइन <?= Security::e(inr((float) $ct['online'])) ?> · बाँकी <?= Security::e(inr((float) $ct['due'])) ?></summary>
    <div class="mf-ctr-scroll"><table>
      <thead><tr><th>काउन्टर · Counter</th><th class="r">टिकट</th><th class="r">यात्री</th><th class="r">नगद · Cash</th><th class="r">अनलाइन · Online</th><th class="r">बाँकी · Due (बसमा)</th></tr></thead>
      <tbody>
      <?php foreach ($chCounter['lines'] as $cl): ?>
        <tr><td><?= Security::e($cl['label']) ?></td><td class="r"><?= (int) $cl['tickets'] ?></td><td class="r"><?= (int) $cl['pax'] ?></td>
          <td class="r"><?= Security::e(inr((float) $cl['cash'])) ?><?= $cl['currency'] !== 'INR' && $cl['cashLocal'] > 0 ? ' <small>(' . Security::e(CounterDesk::format((float) $cl['cashLocal'], (string) $cl['currency'])) . ')</small>' : '' ?></td>
          <td class="r"><?= Security::e(inr((float) $cl['online'])) ?></td>
          <td class="r<?= $cl['due'] > 0 ? ' due' : '' ?>"><?= Security::e(inr((float) $cl['due'])) ?><?= $cl['dueN'] > 0 ? ' · ' . (int) $cl['dueN'] : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($chCounter['lines'] === []): ?><tr><td colspan="6" class="muted">आज अहिलेसम्म केही छैन · Nothing taken today yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <h4>आज टिकट काट्ने · Who cut tickets today (those tickets' money)</h4>
    <div class="mf-ctr-scroll"><table>
      <thead><tr><th>नाम · Name</th><th class="r">टिकट</th><th class="r">यात्री</th><th class="r">जम्मा</th><th class="r">नगद · Cash</th><th class="r">अनलाइन · Online</th><th class="r">बाँकी · Due</th></tr></thead>
      <tbody>
      <?php foreach ($chCounter['people'] as $pp): ?>
        <tr><td><?= Security::e((string) ($pp['name'] ?? ('#' . (int) $pp['who']))) ?> <small class="muted"><?= Security::e(trim((string) ($pp['role'] ?? '') . ((string) ($pp['desk'] ?? '') !== '' ? ' · ' . $pp['desk'] : ''))) ?></small></td>
          <td class="r"><?= (int) $pp['tickets'] ?></td><td class="r"><?= (int) $pp['pax'] ?></td><td class="r"><?= Security::e(inr((float) $pp['amount'])) ?></td>
          <td class="r"><?= Security::e(inr((float) $pp['cash'])) ?></td><td class="r"><?= Security::e(inr((float) $pp['online'])) ?></td>
          <td class="r<?= $pp['due'] > 0 ? ' due' : '' ?>"><?= Security::e(inr((float) $pp['due'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($chCounter['people'] === []): ?><tr><td colspan="7" class="muted">आज कसैले टिकट काटेको छैन · No tickets cut today.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php if (Auth::can('reports.view')): ?>
      <p style="margin:8px 0 0"><a class="btn ghost" href="<?= $base ?>/admin/desk-day.php<?= $chCounter['desk'] !== '' ? '?' . Security::e(http_build_query(['desk' => $chCounter['desk']])) : '' ?>">विस्तृत · Desk day detail →</a></p>
    <?php endif; ?>
  </details>
  <?php endif; ?>

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
        <tfoot><tr><td></td><td><b>जम्मा · Total</b></td><td class="r"><b><?= $paxCount ?></b></td><td><?= $xInfants > 0 ? '+ ' . (int) $xInfants . ' शिशु · infant (lap)' : '' ?></td></tr></tfoot>
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
        <?php $gSt = $mfState[$gid] ?? 'due'; [$gLbl, $gCls] = OpsHub::payLabel($gSt); $gTick = $mfTicks[$gid] ?? null; $gPm = $payMark($r); ?>
        <tr class="mf-grp mf-st-<?= Security::e($gSt) ?>" data-mf-bid="<?= $gid ?>"><td colspan="11">
          <span class="mf-tag <?= Security::e($gCls) ?>" data-mf-tag><?= Security::e($gLbl) ?></span>
          <span class="mf-pm pm-<?= Security::e($gPm['mode']) ?>" data-mf-pm><?= Security::e($pmStaff($gPm, true)) ?></span>
          <?php if ($gPm['claim']): ?><span class="mf-pm pm-pending"><?= Security::e(ChalaniPng::payClaimLabel('staff')) ?></span><?php endif; ?>
          <b><?= Security::e($g['lead']) ?></b> — <?= (int) $g['n'] ?> passenger<?= $g['n'] === 1 ? '' : 's' ?>
          — <?= Security::e($stopNow) ?> → <?= Security::e($stopShort((string) ($r['drop_stop'] ?: $trip['to_city']))) ?>
          — <span class="mono"><?= Security::e((string) $r['contact_phone']) ?></span>
          — seats <b><?= Security::e(implode(', ', $g['seats'])) ?></b>
          <?php if (isset($xBook[$gid])): $xg = $xBook[$gid]; ?>
            — <span class="mf-tag <?= $xg['state'] === 'due' ? 'pay-due' : ($xg['state'] === 'free' ? '' : 'pay-paid') ?>" title="विशेष भुक्तानी · desk extras">
              <?= $xg['lug'] > 0 ? '🧳 ' . (int) $xg['lug'] . ' ' : '' ?><?= $xg['inf'] > 0 ? '👶 ' . (int) $xg['inf'] . ' ' : '' ?>थप <?= Security::e(inr($xg['amt'])) ?> · <?= Security::e($xStateNe($xg['state'])) ?></span>
          <?php endif; ?>
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
        <tr class="mf-st-<?= Security::e($rSt) ?><?= $rowWs ? ' mf-ws' : '' ?>" data-mf-pax="<?= (int) $r['pax_id'] ?>">
          <td class="seat"><?= Security::e(Seats::displayLabel((string) $r['seat_no'], (string) ($trip['coach_type'] ?? 'sleeper'), (string) ($r['booking_mode'] ?? 'sharing'))) ?></td>
          <td>
            <?= $rowWs ? '<b style="color:#C2185B" title="Woman on a single sleeper">♀ </b>' : '' ?><?= Security::e((string) $r['full_name']) ?>
            <?php if ($r['age'] || $r['gender']): ?>
              <br><small style="opacity:.7"><?= Security::e(trim(($r['age'] ? $r['age'] . 'y ' : '') . (string) $r['gender'])) ?></small>
            <?php endif; ?>
            <?php if (!empty($r['special_need'])): ?>
              <br><small class="mf-prio" title="Priority boarding">Priority · <?= Security::e(ucfirst((string) $r['special_need'])) ?></small>
            <?php endif; ?>
            <?php /* 25 Nov 2026 (op5): the optional border ID, office / counter screen only */
              $pidS = $scopeId === null && PaxId::enabled() ? PaxId::short((string) ($r['id_type'] ?? ''), (string) ($r['id_number'] ?? '')) : '';
              if ($pidS !== ''): ?>
              <br><small style="opacity:.8" title="Passenger ID (border)">🪪 <?= Security::e($pidS) ?></small>
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
            <?php /* 26 Nov 2026 (fx1): where the ticket was cut - the booking phone's GPS (BookGeo, cp1).
                     It was only on the printed sheet, where a link cannot be tapped. */
              if (!empty($r['booked_lat'])): require_once INCLUDE_PATH . '/bookgeo.php'; $bgUrl = BookGeo::mapUrl($r); if ($bgUrl !== ''): ?>
              <br><a class="mf-geo" href="<?= Security::e($bgUrl) ?>" target="_blank" rel="noopener" title="<?= Security::e('टिकट काटेको ठाउँ · Booked from: ' . BookGeo::line($r)) ?>">📍 <?= Security::e(mb_strimwidth((string) (($r['booked_place'] ?? '') ?: 'GPS'), 0, 24, '…')) ?></a>
            <?php endif; endif; ?>
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
          <td><?php if ($chStripOn): $rBd = $chBadge($r); ?><span class="mf-pd mf-pd-<?= Security::e($rBd['cls']) ?>" data-mf-pd><?= Security::e($rBd['ne'] . ' · ' . $rBd['en']) ?></span><br><?php endif; ?>
            <?php [$rLbl, $rCls] = OpsHub::payLabel($rSt); ?><span class="mf-tag <?= Security::e($rCls) ?>" data-mf-tag><?= Security::e($rLbl) ?></span>
            <br><small class="muted"><?= Security::e($payLabel($r)) ?><?= !empty($r['pay_method']) ? ' · ' . Security::e(strtoupper((string) $r['pay_method'])) : '' ?></small>
            <?php /* 16 Nov 2026: how this row pays (the amount once per booking), and for a
                     pay-at-boarding passenger still due the crew's 💳 QR to their pay page */ ?>
            <?php $rPm = $payMark($r); ?><br><span class="mf-pm pm-<?= Security::e($rPm['mode']) ?>" data-mf-pm><?= Security::e($pmStaff($rPm, $rPm['first'])) ?></span><?php if ($rPm['claim']): ?> <span class="mf-pm pm-pending"><?= Security::e(ChalaniPng::payClaimLabel('staff')) ?></span><?php endif; ?>
            <?php if ($rPm['mode'] === 'boarding'): ?><button type="button" class="mf-qr no-print" data-payqr
              data-pnr="<?= Security::e((string) $r['pnr']) ?>" data-name="<?= Security::e($pmLead[(int) $r['booking_id']] ?? (string) $r['full_name']) ?>"
              data-amt="<?= Security::e(inr((float) $rPm['amt'])) ?>" data-link="<?= Security::e(ChalaniPng::payLink((string) $r['pnr'], true)) ?>"
              data-qr="<?= Security::e($payQrSrc((string) $r['pnr'])) ?>" title="यात्रुलाई अनलाइन तिर्ने QR देखाउनुहोस् · Show the passenger a QR to pay online (free)">💳 QR</button><?php endif; ?></td>
          <td><?= Security::e($sourceLabel($r)) ?>
            <?php /* 25 Nov 2026 (release ch): who cut it, by name (the seller login) */ ?>
            <?php if (!empty($r['sold_by_admin_id']) && (string) (($r['agent_name'] ?? '') ?: ($r['agent_user'] ?? '')) !== ''): ?>
              <br><small>बुक गर्ने · by <b><?= Security::e((string) (($r['agent_name'] ?? '') ?: ($r['agent_user'] ?? ''))) ?></b></small>
            <?php endif; ?>
            <?php if ($bookedTime($r) !== ''): ?>
              <br><small<?= $isLate($r) ? ' class="mf-late"' : ' class="muted"' ?>><?= $isLate($r) ? '⚠ after dep · ' : 'booked ' ?><?= Security::e($bookedTime($r)) ?></small>
            <?php endif; ?>
          </td>
          <td><?= Security::e($agentLabel($r) ?: '—') ?></td>
          <td class="mono"><?= Security::e($ticketLabel($r)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php /* 16 Nov 2026 (owner: "redirect payment lina sab suvidha hunu paryo, free wala"):
             the 💳 QR card - the passenger scans and pays on their own pay page (UPI / eSewa,
             free). Nothing here marks a payment: the office verifies it on Payments. */ ?>
    <dialog class="pq" id="mfPayQr" aria-labelledby="mfPayQrH">
      <form method="dialog" style="margin:0"><button class="pq-x" type="submit" aria-label="Close · बन्द">✕</button></form>
      <h3 id="mfPayQrH">💳 बसमा भुक्तानी · Pay on boarding</h3>
      <p><b data-f="name"></b> · <span data-f="pnr"></span></p>
      <div class="pq-amt" data-f="amt"></div>
      <div class="pq-img"><img alt="Pay QR" width="240" height="240" data-f="img"></div>
      <p>यात्रुले फोनको UPI / eSewa एपले स्क्यान गरेर निःशुल्क तिर्नुहोस् · The passenger scans with any UPI / eSewa app and pays free.</p>
      <p>कार्यालयले जाँचेपछि मात्र "भुक्तानी" हुन्छ — यहाँबाट केही paid हुँदैन · It counts as paid only after the office checks it.</p>
      <div class="pq-link"><input readonly data-f="link" aria-label="Pay link" onclick="this.select()"><button type="button" data-f="copy">Copy</button><a data-f="open" target="_blank" rel="noopener">Open</a></div>
    </dialog>
    <script>
    (function () {
      var d = document.getElementById('mfPayQr');
      if (!d) { return; }
      var f = function (k) { return d.querySelector('[data-f="' + k + '"]'); };
      document.addEventListener('click', function (ev) {
        var b = ev.target && ev.target.closest ? ev.target.closest('[data-payqr]') : null;
        if (!b) { return; }
        ev.preventDefault();
        var link = b.getAttribute('data-link') || '';
        if (typeof d.showModal !== 'function') { window.open(link, '_blank', 'noopener'); return; }
        f('name').textContent = b.getAttribute('data-name') || '';
        f('pnr').textContent = b.getAttribute('data-pnr') || '';
        f('amt').textContent = b.getAttribute('data-amt') || '';
        f('img').src = b.getAttribute('data-qr') || '';
        f('link').value = link;
        f('open').href = link;
        d.showModal();
      });
      f('copy').addEventListener('click', function () {
        var v = f('link').value, btn = this;
        var done = function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, function () { f('link').select(); }); }
        else { f('link').select(); try { document.execCommand('copy'); done(); } catch (e) {} }
      });
    })();
    </script>
  <?php endif; ?>
  <?php /* 25 Nov 2026 (release ch): the live poll - every 30 s while this tab is visible,
           ?format=json (same gate, same rows) refreshes the row colours, Paid / Due badges and
           the strip; a passenger added or removed offers a reload instead of jumping the page. */ ?>
  <?php if ($chPoll): ?>
  <script>
  (function () {
    var url = <?= json_encode($chLink(['format' => 'json', 'order' => $order !== 'group' ? $order : null]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var key = <?= json_encode($chPaxKey) ?>;
    var live = document.getElementById('mfLive'), last = '', busy = false;
    var swap = function (el, prefix, val) {
      if (!el) { return; }
      el.className = el.className.split(' ').filter(function (c) { return c.indexOf(prefix) !== 0; }).concat([prefix + val]).join(' ');
    };
    var apply = function (d) {
      if (!d || !d.ok || d.fp === last) { return; }
      last = d.fp;
      var s = d.strip || {}, t = s.txt || {};
      ['pax', 'cash', 'online', 'due', 'other'].forEach(function (k) {
        var b = document.querySelector('#mfStrip [data-strip="' + k + '"]');
        if (b && typeof t[k] === 'string') { b.textContent = t[k]; }
      });
      var dueBox = document.querySelector('#mfStrip .s-due');
      if (dueBox) { dueBox.classList.toggle('zero', !(s.due > 0)); }
      var otherBox = document.querySelector('#mfStrip .s-other'), stripEl = document.getElementById('mfStrip');
      if (otherBox) { otherBox.hidden = !s.hasOther; }
      if (stripEl) { stripEl.classList.toggle('has-other', !!s.hasOther); }
      (d.rows || []).forEach(function (r) {
        var tr = document.querySelector('tr[data-mf-pax="' + r.p + '"]');
        if (!tr) { return; }
        swap(tr, 'mf-st-', r.st);
        var tg = tr.querySelector('[data-mf-tag]'); if (tg) { tg.textContent = r.lbl; tg.className = 'mf-tag ' + r.cls; }
        var pm = tr.querySelector('[data-mf-pm]'); if (pm) { pm.textContent = r.pmTxt; pm.className = 'mf-pm pm-' + r.pm; }
        var pd = tr.querySelector('[data-mf-pd]'); if (pd) { pd.textContent = r.pdTxt; pd.className = 'mf-pd mf-pd-' + r.pd; }
      });
      (d.groups || []).forEach(function (g) {
        var tr = document.querySelector('tr[data-mf-bid="' + g.b + '"]');
        if (!tr) { return; }
        swap(tr, 'mf-st-', g.st);
        var tg = tr.querySelector('[data-mf-tag]'); if (tg) { tg.textContent = g.lbl; tg.className = 'mf-tag ' + g.cls; }
        var pm = tr.querySelector('[data-mf-pm]'); if (pm) { pm.textContent = g.pmTxt; pm.className = 'mf-pm pm-' + g.pm; }
      });
      if (live) {
        var at = live.querySelector('[data-live="at"]'); if (at) { at.textContent = ' · ' + (d.at || ''); }
        if (d.key !== key && !live.querySelector('button')) {
          live.classList.add('stale');
          live.appendChild(document.createTextNode(' — नयाँ / रद्द बुकिङ · Passenger list changed '));
          var rb = document.createElement('button'); rb.type = 'button'; rb.textContent = 'Reload';
          rb.addEventListener('click', function () { location.reload(); });
          live.appendChild(rb);
        }
      }
    };
    var poll = function () {
      if (busy || document.visibilityState !== 'visible') { return; }
      busy = true;
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(apply, function () {})
        .then(function () { busy = false; }, function () { busy = false; });
    };
    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { poll(); } });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
<?php
admin_footer();
