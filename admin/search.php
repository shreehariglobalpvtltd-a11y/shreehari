<?php
/**
 * admin/search.php — full-page global search results.
 *
 * The top-bar autosuggest lives at admin/api/search.php and shows the
 * top 8 hits per query. Enter (or "See all results…") lands here for
 * up to 30 rows per category, grouped by type.
 *
 * Same auth gate + agent scope as the API endpoint.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/customerbook.php';
$base = '';

$q = trim((string) ($_GET['q'] ?? ''));

admin_header('Search results', 'search');

if ($q === '' || mb_strlen($q) < 2) {
    echo '<div class="panel"><h2>🔎 Global search</h2>'
       . '<div style="padding:20px">Type a query of two or more characters in the top-bar search — mobile (the last 4–6 digits are enough), customer or passenger name, PNR, ticket number, payment reference / UTR, seat, bus number, route code, or agent SHG code.</div></div>';
    admin_footer();
    exit;
}

$scopeId = Auth::bookingScopeAdminId();
$scopeSql = $scopeId !== null ? ' AND b.sold_by_admin_id = :agentId ' : '';
$scopeParam = $scopeId !== null ? ['agentId' => $scopeId] : [];
$like  = '%' . $q . '%';
/* A mobile typed with spaces or dashes ("98 1234 5678") matches on its
   digits (3 Oct 2026); the last 4–6 digits alone find the customer too. */
$qDigits = preg_replace('/\D/', '', $q) ?? '';
$phoneLike = strlen($qDigits) >= 4 ? '%' . $qDigits . '%' : $like;
/* PDO real-prepare mode rejects a placeholder reused twice in one
   statement (HY093). Every LIKE arm gets its own key. */

/* --- Bookings ------------------------------------------------------- */
$bookings = Database::fetchAll(
    "SELECT b.pnr, b.contact_phone, b.status, b.total_amount, b.created_at,
            b.source, r.from_city, r.to_city, r.route_code,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id=b.id ORDER BY bp.id LIMIT 1) AS pax_name
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes r        ON r.id=s.route_id
      WHERE (b.pnr LIKE :qA OR b.contact_phone LIKE :qB OR b.contact_email LIKE :qC
             OR EXISTS (SELECT 1 FROM booking_passengers bp
                         WHERE bp.booking_id=b.id AND bp.full_name LIKE :qD))"
    . $scopeSql .
   " ORDER BY b.id DESC LIMIT 30",
    ['qA' => $like, 'qB' => $phoneLike, 'qC' => $like, 'qD' => $like] + $scopeParam
);

$tickets = Database::fetchAll(
    "SELECT t.ticket_number, b.pnr, t.issued_at, r.from_city, r.to_city
       FROM tickets t
       JOIN bookings b ON b.id = t.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes r        ON r.id=s.route_id
      WHERE (t.ticket_number LIKE :qA OR b.pnr LIKE :qB)"
    . $scopeSql .
   " ORDER BY t.id DESC LIMIT 30",
    ['qA' => $like, 'qB' => $like] + $scopeParam
);

/* 15 Oct 2026: the seat as PRINTED on the ticket (A7, B5) finds its
   passenger too - the stored id (L1/U1) is not what the desk sees. */
require_once __DIR__ . '/_booking_filters.php';
$paxParams = ['qA' => $like, 'seatExact' => strtoupper($q)] + $scopeParam;
$seatLbl   = preg_match('/^[A-Za-z]{1,2}\d{1,2}$/', $q) === 1
    ? shg_seat_label_match(strtoupper($q), 'bp.seat_no', $paxParams, 'gsl') : null;
$passengers = Database::fetchAll(
    "SELECT bp.full_name, bp.seat_no, bp.gender, b.pnr, b.contact_phone, r.from_city, r.to_city
       FROM booking_passengers bp
       JOIN bookings b ON b.id = bp.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s     ON s.id=bl.schedule_id
       LEFT JOIN routes r        ON r.id=s.route_id
      WHERE (bp.full_name LIKE :qA OR bp.seat_no = :seatExact" . ($seatLbl !== null ? ' OR ' . $seatLbl : '') . ")"
    . $scopeSql .
   " ORDER BY bp.id DESC LIMIT 30",
    $paxParams
);

$buses = Database::fetchAll(
    "SELECT id, bus_number, bus_name, coach_type, total_seats, is_active
       FROM buses
      WHERE bus_number LIKE :qA OR bus_name LIKE :qB
      ORDER BY is_active DESC, id LIMIT 30",
    ['qA' => $like, 'qB' => $like]
);

$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, dep_time, is_active
       FROM routes
      WHERE route_code LIKE :qA OR from_city LIKE :qB OR to_city LIKE :qC
      ORDER BY is_active DESC, id LIMIT 30",
    ['qA' => $like, 'qB' => $like, 'qC' => $like]
);

/* --- Agent code (SHG-NNN), supervisors only ---------------------------
   Codes live in the agent_codes settings map (not a column), so resolve the
   typed code to an admin and show them. Gated to unscoped (supervisor)
   searches — a counter agent must not look another agent up by code. */
$agents = [];
if ($scopeId === null && preg_match('/^\s*(?:SHG[-\s]?)?0*(\d{1,4})\s*$/i', $q, $mCode)) {
    $agAdminId = AgentWallet::adminForAgentCode((int) $mCode[1]);
    if ($agAdminId !== null) {
        $agRow = Database::fetch(
            "SELECT id, username, full_name, phone FROM admins WHERE id = :id AND role = 'agent'",
            ['id' => $agAdminId]
        );
        if ($agRow) {
            $agRow['code'] = 'SHG-' . str_pad((string) (int) $mCode[1], 4, '0', STR_PAD_LEFT);
            $agRow['sold'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :sid', ['sid' => (int) $agRow['id']], 0);
            $agents[] = $agRow;
        }
    }
}

/* --- Customers (3 Oct 2026) — one row per customer (main number, its
   linked numbers folded in), by any part of the mobile or a name. --- */
// Office only: the profile page refuses a counter agent's session.
$customers = $scopeId === null ? CustomerBook::searchCustomers($q, null, 30) : [];

/* --- Transactions: payment reference, UTR, payer phone, PNR ---------- */
$transactions = CustomerBook::searchTransactions($q, $scopeId, 30);

$total = count($customers) + count($transactions) + count($bookings) + count($tickets) + count($passengers) + count($buses) + count($routes) + count($agents);
$qE    = Security::e($q);
?>
<style>
.gsr-hdr{background:linear-gradient(135deg,var(--navy),#0b1a36);color:#fff;padding:18px 22px;border-radius:14px;margin-bottom:20px}
.gsr-hdr h2{margin:0 0 4px;font-size:22px}
.gsr-hdr small{opacity:.75;font-size:13px}
.gsr-sec{margin-bottom:22px}
.gsr-sec h3{margin:0 0 10px;font-size:14px;font-weight:800;color:var(--mut);text-transform:uppercase;letter-spacing:1px}
.gsr-list{background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.gsr-list a{display:grid;grid-template-columns:32px 1fr 200px 90px;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid var(--line);color:var(--ink);font-size:14px}
.gsr-list a:last-child{border-bottom:0}
.gsr-list a:hover{background:var(--hover)}
.gsr-icon{font-size:20px;text-align:center}
.gsr-title{font-weight:700}
.gsr-title small{display:block;font-weight:500;color:var(--mut);font-size:12px;margin-top:2px}
.gsr-sub{color:var(--mut);font-size:13px}
.gsr-pill{text-align:right;font-size:11px;font-weight:800;color:var(--mut);text-transform:uppercase}
.gsr-empty{padding:30px;text-align:center;color:var(--mut);background:var(--card);border:1px solid var(--line);border-radius:12px}
@media(max-width:820px){
  .gsr-list a{grid-template-columns:32px 1fr;gap:8px}
  .gsr-sub, .gsr-pill{display:none}
}
</style>

<div class="gsr-hdr">
  <h2>Results for "<?= $qE ?>"</h2>
  <small><?= $total ?> match<?= $total === 1 ? '' : 'es' ?> across customers, tickets, passengers, agents, transactions, buses, and routes.</small>
</div>

<?php if ($total === 0): ?>
  <div class="gsr-empty">
    Nothing matched "<?= $qE ?>".<br><br>
    Try a shorter substring, or search a different field — PNR, phone, ticket number, seat (L5 / U12), bus number, or route code.
  </div>
<?php endif; ?>

<?php if ($customers): ?>
<div class="gsr-sec"><h3>👤 Customers (<?= count($customers) ?>)</h3><div class="gsr-list">
<?php foreach ($customers as $cu): ?>
<a href="<?= $base ?>/admin/customer-view.php?phone=<?= urlencode((string) $cu['phone']) ?>">
  <span class="gsr-icon">👤</span>
  <div class="gsr-title"><?= Security::e($cu['name'] !== '' ? (string) $cu['name'] : 'Customer') ?><?php if ($cu['customer_id'] !== ''): ?> <span class="mono" style="font-size:11px;color:var(--mut)"><?= Security::e((string) $cu['customer_id']) ?></span><?php endif; ?>
    <small class="mono">+<?= Security::e((string) $cu['phone']) ?><?= count($cu['phones']) > 1 ? ' · also ' . Security::e(implode(', ', array_slice($cu['phones'], 1))) : '' ?></small>
  </div>
  <div class="gsr-sub"><?= (int) $cu['bookings'] ?> booking<?= (int) $cu['bookings'] === 1 ? '' : 's' ?> · <?= (int) $cu['pax'] ?> pax · <?= inr((float) $cu['spend']) ?><?= $cu['last_travel'] !== '' ? ' · last ' . Security::e(date('d M Y', strtotime((string) $cu['last_travel']))) : '' ?></div>
  <div class="gsr-pill">Customer</div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($agents): ?>
<div class="gsr-sec"><h3>🧑‍💼 Agents (<?= count($agents) ?>)</h3><div class="gsr-list">
<?php foreach ($agents as $ag): ?>
<a href="<?= $base ?>/admin/agent.php?agent=<?= (int) $ag['id'] ?>">
  <span class="gsr-icon">🧑‍💼</span>
  <div class="gsr-title"><?= Security::e((string) $ag['code']) ?> — <?= Security::e((string) ($ag['full_name'] ?: $ag['username'])) ?>
    <small><?= Security::e((string) ($ag['phone'] ?? '')) ?></small>
  </div>
  <div class="gsr-sub"><?= (int) $ag['sold'] ?> booking<?= (int) $ag['sold'] === 1 ? '' : 's' ?> sold</div>
  <div class="gsr-pill">Agent</div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($bookings): ?>
<div class="gsr-sec"><h3>🎫 Bookings (<?= count($bookings) ?>)</h3><div class="gsr-list">
<?php foreach ($bookings as $b): ?>
<a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $b['pnr']) ?>">
  <span class="gsr-icon">🎫</span>
  <div class="gsr-title"><?= Security::e((string) $b['pnr']) ?><?php if (!empty($b['pax_name'])): ?> — <?= Security::e((string) $b['pax_name']) ?><?php endif; ?>
    <small><?= Security::e(trim(($b['from_city'] ?? '') . ' → ' . ($b['to_city'] ?? ''), ' →')) ?> · <?= Security::e((string) $b['contact_phone']) ?></small>
  </div>
  <div class="gsr-sub"><?= inr((float) $b['total_amount']) ?> · <?= Security::e((string) $b['source']) ?></div>
  <div class="gsr-pill"><?= admin_pill((string) $b['status']) ?></div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($passengers): ?>
<div class="gsr-sec"><h3>👤 Passengers (<?= count($passengers) ?>)</h3><div class="gsr-list">
<?php foreach ($passengers as $p): ?>
<a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>">
  <span class="gsr-icon">👤</span>
  <div class="gsr-title"><?= Security::e((string) $p['full_name']) ?> — Seat <?= Security::e(Seats::displayLabel((string) $p['seat_no'])) ?>
    <small><?= Security::e((string) $p['pnr']) ?> · <?= Security::e((string) $p['contact_phone']) ?></small>
  </div>
  <div class="gsr-sub"><?= Security::e(trim(($p['from_city'] ?? '') . ' → ' . ($p['to_city'] ?? ''), ' →')) ?></div>
  <div class="gsr-pill"><?= Security::e((string) $p['gender']) ?></div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($tickets): ?>
<div class="gsr-sec"><h3>🎟️ Tickets (<?= count($tickets) ?>)</h3><div class="gsr-list">
<?php foreach ($tickets as $t): ?>
<a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $t['pnr']) ?>">
  <span class="gsr-icon">🎟️</span>
  <div class="gsr-title"><?= Security::e((string) $t['ticket_number']) ?>
    <small><?= Security::e((string) $t['pnr']) ?> · <?= Security::e(trim(($t['from_city'] ?? '') . ' → ' . ($t['to_city'] ?? ''), ' →')) ?></small>
  </div>
  <div class="gsr-sub"><?= date('d M Y', strtotime((string) $t['issued_at'])) ?></div>
  <div class="gsr-pill">Issued</div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($transactions): ?>
<div class="gsr-sec"><h3>💳 Transactions (<?= count($transactions) ?>)</h3><div class="gsr-list">
<?php foreach ($transactions as $tx): ?>
<a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $tx['pnr']) ?>">
  <span class="gsr-icon">💳</span>
  <div class="gsr-title"><?= Security::e(($tx['currency'] === 'NPR' ? 'रू ' : '') . inr((float) $tx['amount'])) ?> · <?= Security::e(strtoupper((string) $tx['method'])) ?>
    <small class="mono"><?= Security::e((string) ($tx['utr_number'] ?: $tx['payment_ref'])) ?> · <?= Security::e((string) $tx['pnr']) ?> · <?= Security::e((string) $tx['contact_phone']) ?></small>
  </div>
  <div class="gsr-sub"><?= date('d M Y', strtotime((string) $tx['created_at'])) ?></div>
  <div class="gsr-pill"><?= admin_pill((string) $tx['status']) ?></div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($buses): ?>
<div class="gsr-sec"><h3>🚌 Buses (<?= count($buses) ?>)</h3><div class="gsr-list">
<?php foreach ($buses as $bs): ?>
<a href="<?= $base ?>/admin/buses.php?edit=<?= (int) $bs['id'] ?>">
  <span class="gsr-icon">🚌</span>
  <div class="gsr-title"><?= Security::e((string) $bs['bus_number']) ?>
    <small><?= Security::e((string) $bs['bus_name']) ?></small>
  </div>
  <div class="gsr-sub"><?= Security::e(ucfirst((string) $bs['coach_type'])) ?> · <?= (int) $bs['total_seats'] ?> seats</div>
  <div class="gsr-pill"><?= ((int) $bs['is_active'] === 1) ? 'Active' : 'Inactive' ?></div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($routes): ?>
<div class="gsr-sec"><h3>🛣️ Routes (<?= count($routes) ?>)</h3><div class="gsr-list">
<?php foreach ($routes as $r): ?>
<a href="<?= $base ?>/admin/seatmap.php?route=<?= (int) $r['id'] ?>">
  <span class="gsr-icon">🛣️</span>
  <div class="gsr-title"><?= Security::e((string) $r['route_code']) ?> · <?= Security::e((string) $r['from_city']) ?> → <?= Security::e((string) $r['to_city']) ?>
    <small>Departure <?= Security::e(substr((string) $r['dep_time'], 0, 5)) ?></small>
  </div>
  <div class="gsr-sub"></div>
  <div class="gsr-pill"><?= ((int) $r['is_active'] === 1) ? 'Active' : 'Off' ?></div>
</a>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<?php
admin_footer();
