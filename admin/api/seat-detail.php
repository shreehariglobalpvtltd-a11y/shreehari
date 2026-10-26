<?php
/**
 * admin/api/seat-detail.php — everything about ONE berth on ONE departure.
 *
 *     GET /admin/api/seat-detail.php?sid=<schedule id>&seat=<canonical seat>
 *
 * Owner, 2 Oct 2026: "seat ma click garne bittikai tyasko details — ticket,
 * naam, j j order gareko thiyo sabai dekhinu paryo." The seat map's popover
 * used to offer only links; this is the card it now opens with: the ticket
 * (PNR, status, booked when / by whom), every passenger on it with their
 * berth, phone, pickup and drop, fare and what is paid, which agent it is
 * credited to, the bus and its departure, the latest changes, and the
 * ticket picture / PDF.
 *
 * Read-only. Same gate as the seat map (schedules.view) and the SAME privacy
 * split: a counter agent gets the card only for a berth they sold — for
 * anyone else's they learn "sold" and the gender (needed to sell the berth
 * beside it legally), exactly what the map already shows them.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
admin_boot('schedules.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = static function (array $body, int $code = 200): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$sid  = (int) ($_GET['sid'] ?? 0);
$seat = strtoupper(Security::clean((string) ($_GET['seat'] ?? ''), 10));
if ($sid <= 0 || $seat === '') {
    $out(['ok' => false, 'error' => 'Choose a seat.'], 422);
}

try {
    $schedule = Seats::scheduleById($sid);
} catch (Throwable $e) {
    $out(['ok' => false, 'error' => 'That departure no longer exists.'], 404);
}
$route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => (int) $schedule['route_id']]);
if ($route === null) {
    $out(['ok' => false, 'error' => 'Unknown route.'], 404);
}
$coach = Seats::effectiveCoach($schedule, $route);

/* The map's own answer for this berth — status, and whether the viewer may
   read the passenger book behind it. One source, so the card can never show
   more (or something different) than the tile it was opened from. */
$map = Seats::adminSeatMap($sid, $coach);
if (!isset($map[$seat])) {
    $out(['ok' => false, 'error' => 'No such seat on this coach.'], 404);
}
$cell  = $map[$seat];
$label = Seats::displayLabel($seat, $coach, 'sharing');
$base  = [
    'ok'     => true,
    'seat'   => $seat,
    'label'  => $label,
    'floor'  => Seats::label($seat),
    'status' => (string) $cell['status'],
    'mine'   => !empty($cell['mine']),
    'gender' => $cell['gender'] ?? null,
    'stop'   => (string) ($cell['stop'] ?? ''),
];

if ($cell['status'] === 'held') {
    $base['holdUntil'] = !empty($cell['holdUntil']) ? formatTime(substr((string) $cell['holdUntil'], 11, 5)) : '';
    $out($base);
}
if ($cell['status'] === 'blocked') {
    $blk = Database::fetch(
        'SELECT sb.reason, sb.created_at, a.full_name, a.username FROM seat_blocks sb
           LEFT JOIN admins a ON a.id = sb.blocked_by
          WHERE sb.schedule_id = :s AND sb.seat_no = :n ORDER BY sb.id DESC LIMIT 1',
        ['s' => $sid, 'n' => $seat]
    );
    if ($blk !== null) {
        $base['blockReason'] = (string) ($blk['reason'] ?? '');
        $base['blockedBy']   = (string) (($blk['full_name'] ?? '') ?: ($blk['username'] ?? ''));
        $base['blockedAt']   = formatDate(substr((string) $blk['created_at'], 0, 10)) . ' ' . date('g:i A', strtotime((string) $blk['created_at']));
    }
    $out($base);
}
if ($cell['status'] !== 'booked' || empty($cell['mine']) || empty($cell['pnr'])) {
    // Open / staff berth, or a sale the viewer may not read.
    $out($base);
}

/* ---- The ticket behind the berth ----------------------------------- */
$b = Database::fetch(
    "SELECT b.*, bl.id AS leg_id, bl.leg_type, bl.travel_date, bl.boarding_stop, bl.drop_stop, bl.boarding_time,
            r.from_city, r.to_city, r.route_code,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time, s.slot, s.status AS trip_status,
            bus.bus_name, bus.bus_number,
            a.full_name AS agent_name, a.username AS agent_username, a.phone AS agent_phone, a.role AS agent_role,
            ap.counter_name AS agent_counter, ap.counter_code AS agent_counter_code
       FROM bookings b
       JOIN booking_seats bs ON bs.booking_id = b.id AND bs.schedule_id = :sid AND bs.released_at IS NULL
       JOIN booking_legs bl ON bl.id = bs.leg_id
       JOIN schedules s ON s.id = bl.schedule_id
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses bus ON bus.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN admins a ON a.id = b.sold_by_admin_id
       LEFT JOIN admin_profiles ap ON ap.admin_id = a.id
      WHERE b.pnr = :pnr
      LIMIT 1",
    ['sid' => $sid, 'pnr' => (string) $cell['pnr']]
);
if ($b === null) {
    $out($base);
}
$bookingId = (int) $b['id'];
$mode      = (string) ($b['booking_mode'] ?? 'sharing');
if ($mode === '') { $mode = 'sharing'; }

// This booking's live berths on THIS bus, in its own mode's labels.
$liveSeats = array_map('strval', pluck(Database::fetchAll(
    'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND schedule_id = :s AND released_at IS NULL ORDER BY seat_no',
    ['b' => $bookingId, 's' => $sid]
), 'seat_no'));
$liveSet = array_flip($liveSeats);

$passengers = [];
foreach (Database::fetchAll(
    'SELECT full_name, age, gender, seat_no, is_primary, boarded_at FROM booking_passengers
      WHERE booking_id = :b ORDER BY is_primary DESC, id',
    ['b' => $bookingId]
) as $p) {
    $ps = (string) $p['seat_no'];
    // "This" passenger = the one whose berth (in the booking's own mode) covers
    // the physical bed that was clicked — a private cabin is two beds.
    $isThis = in_array($seat, Seats::physicalSeats($ps, $mode, $coach), true);
    $passengers[] = [
        'name'    => (string) $p['full_name'],
        'age'     => $p['age'] !== null ? (int) $p['age'] : null,
        'gender'  => (string) ($p['gender'] ?? ''),
        'seat'    => $ps !== '' ? Seats::displayLabel($ps, $coach, $mode) : '',
        'primary' => (int) $p['is_primary'] === 1,
        'this'    => $isThis,
        'boarded' => !empty($p['boarded_at']),
        // A berth cancelled on its own (per-seat cancel) keeps its passenger
        // row; the card says so instead of listing them as travelling.
        'cancelled' => $ps !== '' && !isset($liveSet[$ps]),
    ];
}

$payment = Database::fetch(
    'SELECT status, method, amount, utr_number, created_at FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
    ['b' => $bookingId]
);
$verified = (float) Database::scalar(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
    ['b' => $bookingId],
    0
);
$money = Ticket::paymentSummary(['total_amount' => $b['total_amount'], 'payment' => $payment ?? [], 'verified_paid' => $verified]);

$seller = Ticket::issuedBy($b);

/* Commission credited for THIS ticket (agent sales only) — net of reversals,
   read from the ledger like every other money figure on the panel. */
$commission = null;
if ((int) ($b['sold_by_admin_id'] ?? 0) > 0 && $seller['kind'] === 'agent') {
    $commission = (float) Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
          WHERE booking_id = :b AND agent_admin_id = :a AND account = 'commission'
            AND entry_type IN ('commission','commission_void')",
        ['b' => $bookingId, 'a' => (int) $b['sold_by_admin_id']],
        0
    );
}

/** "Surat — Sachin GIDC @ 13:00 [21.1,72.8]" → readable line + code. */
$stopLine = static function (?string $raw): array {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return ['code' => '', 'text' => ''];
    }
    $d    = Boarding::stopDisplay($raw);
    $text = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $raw));
    return ['code' => (string) $d['code'], 'text' => $text];
};

/* Who touched this ticket last — the change history, newest first. The
   ticket.issue noise is skipped; every other booking/seat action is named. */
$actionNames = [
    'booking.counter'         => 'Sold at the counter',
    'booking.create'          => 'Booked',
    'booking.confirm'         => 'Confirmed',
    'booking.reassign_agent'  => 'Agent changed',
    'seat.transfer'           => 'Seat changed',
    'booking.seat_change'     => 'Seat changed',
    'booking.cancel_seat'     => 'One seat cancelled',
    'booking.cancel'          => 'Cancelled',
    'booking.force_cancel'    => 'Cancelled (forced)',
    'booking.edit_passenger'  => 'Passenger edited',
    'booking.edit_contact'    => 'Contact edited',
    'booking.edit_notify'     => 'Change sent to passenger',
    'booking.reschedule'      => 'Date / bus changed',
    'booking.leg_move'        => 'Date / bus changed',
    'booking.cod.settle'      => 'Cash collected',
    'payment.proof'           => 'Payment proof added',
    'booking.resend_wa'       => 'Ticket re-sent on WhatsApp',
    'ticket.reissue'          => 'Ticket re-made',
    'ticket.regenerated'      => 'Ticket re-made',
    'ticket.board'            => 'Boarded',
];
$history = [];
try {
    foreach (Database::fetchAll(
        "SELECT action, actor_name, actor_role, detail, created_at FROM audit_logs
          WHERE entity_type = 'booking' AND entity_id IN (:p, :i) AND action <> 'ticket.issue'
          ORDER BY id DESC LIMIT 6",
        ['p' => (string) $b['pnr'], 'i' => (string) $bookingId]
    ) as $h) {
        $act = (string) $h['action'];
        $history[] = [
            'what' => $actionNames[$act] ?? ucfirst(str_replace(['booking.', '_', '.'], ['', ' ', ' '], $act)),
            'who'  => trim((string) ($h['actor_name'] ?? '')) . (($h['actor_role'] ?? '') !== '' ? ' (' . $h['actor_role'] . ')' : ''),
            'when' => date('j M, g:i A', strtotime((string) $h['created_at'])),
            'ago'  => timeAgo((string) $h['created_at']),
        ];
    }
} catch (Throwable $e) {
    $history = [];
}

/* The bus, named: a date can carry the daily bus AND extra buses on the
   same route, so "C1" alone does not say which coach the berth is on. */
$multiBus = (int) Database::scalar(
    "SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date = :d AND status <> 'cancelled'",
    ['r' => (int) $schedule['route_id'], 'd' => (string) $schedule['travel_date']],
    1
) > 1;
$busBits = array_values(array_filter([
    ($multiBus || (int) $b['slot'] > 1) ? 'Bus ' . (int) $b['slot'] : '',
    trim((string) ($b['bus_number'] ?? '')),
    trim((string) ($b['bus_name'] ?? '')),
], static fn(string $s): bool => $s !== ''));

/* wa.me number dialled the way WaTemplates::intl() / Notify do: India and
   Nepal share 10-digit mobiles, so the country stamped on the booking decides
   and only then the office default — never a guess from the digits. */
$phone = trim((string) ($b['contact_phone'] ?? ''));
$cc    = preg_replace('/\D/', '', (string) ($b['contact_country_code'] ?? '')) ?: '';
$waNum = preg_replace('/\D/', '', $phone) ?? '';
if (str_starts_with($waNum, '00')) {
    $waNum = substr($waNum, 2);
}
if (strlen($waNum) === 10) {
    $waNum = ($cc !== '' ? $cc : (preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91')) . $waNum;
}

$board = $stopLine($b['boarding_stop'] ?? null);
$drop  = $stopLine($b['drop_stop'] ?? null);

$out($base + [
    'booking' => [
        'pnr'         => (string) $b['pnr'],
        'status'      => (string) $b['status'],
        'mode'        => $mode,
        'cabin'       => (string) ($b['cabin_label'] ?? ''),
        'bookedAt'    => date('j M Y, g:i A', strtotime((string) $b['created_at'])),
        'bookedAgo'   => timeAgo((string) $b['created_at']),
        'source'      => ucfirst((string) ($b['source'] ?? 'web')),
        'seatsHere'   => array_map(static fn(string $s): string => Seats::displayLabel($s, $coach, $mode), $liveSeats),
        'passengers'  => $passengers,
        'phone'       => $phone,
        'phoneCc'     => $cc,
        'waNumber'    => $waNum,
        'email'       => (string) ($b['contact_email'] ?? ''),
        'total'       => inr((float) $b['total_amount']),
        'farePerSeat' => inr((float) ($b['fare_per_seat'] ?? 0)),
        'discount'    => (float) ($b['coupon_discount'] ?? 0) + (float) ($b['group_discount'] ?? 0) + (float) ($b['tier_discount'] ?? 0) > 0
                            ? inr((float) ($b['coupon_discount'] ?? 0) + (float) ($b['group_discount'] ?? 0) + (float) ($b['tier_discount'] ?? 0))
                            : '',
        'paid'        => inr($money['paid']),
        'due'         => $money['due'] > 0 ? inr($money['due']) : '',
        'payMethod'   => strtoupper((string) ($payment['method'] ?? ((int) ($b['is_cod'] ?? 0) === 1 ? 'cod' : ''))),
        'payStatus'   => (string) ($payment['status'] ?? ''),
        'cod'         => (int) ($b['is_cod'] ?? 0) === 1,
        'refund'      => (float) ($b['refund_amount'] ?? 0) > 0 ? inr((float) $b['refund_amount']) . ' · ' . (string) ($b['refund_status'] ?? '') : '',
        'seller'      => [
            'kind'     => $seller['kind'],
            'code'     => $seller['code'],
            'name'     => $seller['name'],
            'label'    => $seller['kind'] === 'agent' ? 'Agent' : ($seller['code'] === 'COUNTER' ? 'Counter' : ($seller['kind'] === 'office' ? 'Office' : 'Online')),
            'location' => $seller['location'],
        ],
        'commission'  => $commission !== null ? inr($commission) : '',
        'trip'        => [
            'route'  => (string) $b['from_city'] . ' → ' . (string) $b['to_city'],
            'code'   => (string) $b['route_code'],
            'date'   => formatDate((string) $b['travel_date']),
            'dep'    => formatTime($b['dep_time'] ?? null),
            'bus'    => implode(' · ', $busBits),
            'slot'   => (int) $b['slot'],
            'status' => (string) ($b['trip_status'] ?? ''),
            'leg'    => (string) ($b['leg_type'] ?? 'outbound'),
        ],
        'boarding'    => $board['text'],
        'boardCode'   => $board['code'],
        'boardTime'   => !empty($b['boarding_time']) ? formatTime((string) $b['boarding_time']) : '',
        'drop'        => $drop['text'],
        'dropCode'    => $drop['code'],
        'note'        => (string) ($b['admin_note'] ?? ''),
        'history'     => $history,
        'links'       => [
            'view'  => '/admin/booking-view.php?pnr=' . rawurlencode((string) $b['pnr']),
            'png'   => Ticket::imageUrl((string) $b['pnr']),
            'pdf'   => Ticket::downloadUrl((string) $b['pnr']),
        ],
    ],
]);
