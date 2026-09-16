<?php
/**
 * admin/api/search.php — global search JSON endpoint.
 *
 * Backs the top-bar autosuggest (Phase 3). Same auth gate as the
 * search form itself (bookings.view), and an agent scope is applied
 * to booking / passenger / ticket hits so a counter agent only ever
 * suggests results from bookings they sold. Bus and route hits are
 * company-wide because those are catalogue lookups, not customer data.
 *
 * ?q=<query> &suggest=1 → up to 8 rows total across all categories,
 * biased toward exact PNR / phone / bus_no hits.
 * ?q=<query>            → up to 30 rows for the full-page renderer.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('bookings.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$suggest = !empty($_GET['suggest']);
$limit   = $suggest ? 8 : 30;
$scopeId = Auth::bookingScopeAdminId();
$scopeSql = $scopeId !== null ? ' AND b.sold_by_admin_id = :agentId ' : '';
$scopeParam = $scopeId !== null ? ['agentId' => $scopeId] : [];

$like  = '%' . $q . '%';

/* PDO here does NOT emulate prepares (see PDOAttr in db.php), so the
   same named placeholder cannot be reused in a single statement — 4x :q
   crashes with SQLSTATE HY093. Every LIKE arm gets its own bound key. */
$rows = [];

/* --- Bookings by PNR / phone / email / passenger name via join ------ */
$bookings = Database::fetchAll(
    "SELECT b.pnr, b.contact_phone, b.contact_email, b.status, b.total_amount, b.created_at,
            b.source, r.route_code, r.from_city, r.to_city,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id=b.id ORDER BY bp.id LIMIT 1) AS pax_name
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes r        ON r.id=s.route_id
      WHERE (b.pnr LIKE :qA OR b.contact_phone LIKE :qB OR b.contact_email LIKE :qC
             OR EXISTS (SELECT 1 FROM booking_passengers bp
                         WHERE bp.booking_id=b.id AND bp.full_name LIKE :qD))"
    . $scopeSql .
   " ORDER BY (b.pnr = :qexact) DESC, b.id DESC
      LIMIT " . (int) $limit,
    ['qA' => $like, 'qB' => $like, 'qC' => $like, 'qD' => $like, 'qexact' => $q] + $scopeParam
);
foreach ($bookings as $b) {
    $rows[] = [
        'type'     => 'booking',
        'title'    => $b['pnr'] . ($b['pax_name'] ? ' — ' . $b['pax_name'] : ''),
        'subtitle' => trim(($b['from_city'] ?? '') . ' → ' . ($b['to_city'] ?? ''), ' →') . ' · ' . ($b['contact_phone'] ?? ''),
        'meta'     => strtoupper((string) $b['status']),
        'href'     => '/admin/booking-view.php?pnr=' . urlencode((string) $b['pnr']),
    ];
}

/* --- Tickets by number ---------------------------------------------- */
$tickets = Database::fetchAll(
    "SELECT t.ticket_number, b.pnr, t.issued_at, r.from_city, r.to_city
       FROM tickets t
       JOIN bookings b ON b.id = t.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes   r      ON r.id=s.route_id
      WHERE (t.ticket_number LIKE :qA OR b.pnr LIKE :qB)"
    . $scopeSql .
   " ORDER BY t.id DESC LIMIT " . (int) $limit,
    ['qA' => $like, 'qB' => $like] + $scopeParam
);
foreach ($tickets as $t) {
    $rows[] = [
        'type'     => 'ticket',
        'title'    => (string) $t['ticket_number'],
        'subtitle' => (string) $t['pnr'] . ' · ' . trim(($t['from_city'] ?? '') . ' → ' . ($t['to_city'] ?? ''), ' →'),
        'meta'     => date('d M', strtotime((string) ($t['issued_at'] ?? 'now'))),
        'href'     => '/admin/booking-view.php?pnr=' . urlencode((string) $t['pnr']),
    ];
}

/* --- Passengers by seat / name / phone (own row) -------------------- */
$pax = Database::fetchAll(
    "SELECT bp.full_name, bp.seat_no, bp.gender, b.pnr, b.contact_phone, r.from_city, r.to_city
       FROM booking_passengers bp
       JOIN bookings b ON b.id = bp.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes r        ON r.id=s.route_id
      WHERE (bp.full_name LIKE :qA OR bp.seat_no = :seatExact)"
    . $scopeSql .
   " ORDER BY bp.id DESC LIMIT " . (int) $limit,
    ['qA' => $like, 'seatExact' => strtoupper($q)] + $scopeParam
);
foreach ($pax as $p) {
    $rows[] = [
        'type'     => 'passenger',
        'title'    => (string) $p['full_name'] . ' · ' . (string) $p['seat_no'],
        'subtitle' => (string) $p['pnr'] . ' · ' . (string) $p['contact_phone'],
        'meta'     => trim(($p['from_city'] ?? '') . '→' . ($p['to_city'] ?? ''), ' →'),
        'href'     => '/admin/booking-view.php?pnr=' . urlencode((string) $p['pnr']),
    ];
}

/* --- Buses (catalogue, unscoped) ------------------------------------ */
$buses = Database::fetchAll(
    "SELECT id, bus_number, bus_name, coach_type, total_seats
       FROM buses
      WHERE bus_number LIKE :qA OR bus_name LIKE :qB
      ORDER BY is_active DESC, id LIMIT " . (int) $limit,
    ['qA' => $like, 'qB' => $like]
);
foreach ($buses as $bus) {
    $rows[] = [
        'type'     => 'bus',
        'title'    => (string) $bus['bus_number'],
        'subtitle' => (string) $bus['bus_name'],
        'meta'     => ucfirst((string) $bus['coach_type']) . ' · ' . (int) $bus['total_seats'] . ' seats',
        'href'     => '/admin/buses.php?edit=' . (int) $bus['id'],
    ];
}

/* --- Routes (catalogue, unscoped) ----------------------------------- */
$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, dep_time
       FROM routes
      WHERE route_code LIKE :qA OR from_city LIKE :qB OR to_city LIKE :qC
      ORDER BY is_active DESC, id LIMIT " . (int) $limit,
    ['qA' => $like, 'qB' => $like, 'qC' => $like]
);
foreach ($routes as $r) {
    $rows[] = [
        'type'     => 'route',
        'title'    => (string) $r['route_code'] . ' · ' . (string) $r['from_city'] . ' → ' . (string) $r['to_city'],
        'subtitle' => 'Departure ' . substr((string) $r['dep_time'], 0, 5),
        'meta'     => '',
        'href'     => '/admin/seatmap.php?route=' . (int) $r['id'],
    ];
}

/* --- Agent code (SHG-NNN), supervisors only -------------------------
   The SHG agent code lives in the agent_codes settings map, not a column,
   so a LIKE won't find it: resolve the typed code to an admin_id and
   surface that agent + the bookings they sold. Gated to unscoped
   (supervisor) searches — a counter agent must not look another agent up
   by code. Only fires when the query looks like a code (SHG-27 / 027 / 27),
   and only costs 2 queries when it actually resolves to a real agent. */
if ($scopeId === null && preg_match('/^\s*(?:SHG[-\s]?)?0*(\d{1,4})\s*$/i', $q, $m)) {
    $codeNum      = (int) $m[1];
    $agentAdminId = AgentWallet::adminForAgentCode($codeNum);
    if ($agentAdminId !== null) {
        $ag = Database::fetch(
            "SELECT id, username, full_name, phone FROM admins WHERE id = :id AND role = 'agent'",
            ['id' => $agentAdminId]
        );
        if ($ag) {
            $codeLabel = 'SHG-' . str_pad((string) $codeNum, 4, '0', STR_PAD_LEFT);
            $rows[] = [
                'type'     => 'agent',
                'title'    => $codeLabel . ' — ' . (string) ($ag['full_name'] ?: $ag['username']),
                'subtitle' => 'Agent · ' . (string) ($ag['phone'] ?? ''),
                'meta'     => 'Panel',
                'href'     => '/admin/agent.php?agent=' . (int) $ag['id'],
            ];
            $agBookings = Database::fetchAll(
                "SELECT b.pnr, b.status, b.contact_phone, r.from_city, r.to_city
                   FROM bookings b
                   LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
                   LEFT JOIN schedules s     ON s.id=bl.schedule_id
                   LEFT JOIN routes r        ON r.id=s.route_id
                  WHERE b.sold_by_admin_id = :sid
                  ORDER BY b.id DESC LIMIT " . (int) $limit,
                ['sid' => (int) $ag['id']]
            );
            foreach ($agBookings as $ab) {
                $rows[] = [
                    'type'     => 'booking',
                    'title'    => $ab['pnr'] . ' · ' . $codeLabel,
                    'subtitle' => trim(($ab['from_city'] ?? '') . ' → ' . ($ab['to_city'] ?? ''), ' →') . ' · ' . ($ab['contact_phone'] ?? ''),
                    'meta'     => strtoupper((string) $ab['status']),
                    'href'     => '/admin/booking-view.php?pnr=' . urlencode((string) $ab['pnr']),
                ];
            }
        }
    }
}

/* Truncate to the requested limit — the per-category queries already
   capped themselves to $limit, but the total across 5 categories can
   easily exceed the autosuggest's 8-row target. */
if (count($rows) > $limit) {
    $rows = array_slice($rows, 0, $limit);
}

echo json_encode(['q' => $q, 'results' => $rows], JSON_UNESCAPED_UNICODE);
