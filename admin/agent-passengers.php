<?php
/**
 * admin/agent-passengers.php — passenger list and customer book for one agent.
 *
 * Two views over the same scoped set of sales, because at a counter they are
 * the same question asked twice:
 *
 *   ?view=manifest  — who is travelling, by departure. What the agent reads
 *                     out at the door.
 *   ?view=customers — who has bought from this agent, collapsed to one row
 *                     per phone number with their trip count and spend.
 *
 * Both are restricted to bookings.sold_by_admin_id. There is deliberately no
 * "all passengers" mode: a counter agent's customer book is their own sales
 * and nothing else, and the company customer database lives on
 * admin/customers.php behind customers.view.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
// Office viewers need the commissions permission — an agent sees their own
// passengers, but counter/support roles must not browse other sellers' books.
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

$base         = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$selfId       = (int) ($admin['id'] ?? 0);
$isSupervisor = Auth::can('dashboard.view');
$viewId       = $selfId;

if ($isSupervisor && isset($_GET['agent']) && (int) $_GET['agent'] > 0) {
    $viewId = (int) $_GET['agent'];
}
$scopeId = Auth::bookingScopeAdminId();
if ($scopeId !== null) {
    $viewId = $scopeId;                     // an agent is always pinned to self
}
$isOwn = $viewId === $selfId;

$view = (string) ($_GET['view'] ?? 'manifest');
if (!in_array($view, ['manifest', 'customers'], true)) {
    $view = 'manifest';
}

$q      = Security::clean($_GET['q'] ?? '', 60);
$today  = todayISO();
$date   = Security::clean($_GET['date'] ?? '', 10);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = '';
}

$agentList = $isSupervisor && $scopeId === null
    ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' ORDER BY full_name, username")
    : [];
$viewing = Database::fetch('SELECT username, full_name FROM admins WHERE id = :id', ['id' => $viewId]) ?? [];

/* =====================================================================
 *  MANIFEST — passengers grouped by departure
 * ===================================================================== */
$manifest = [];
$trips    = [];

if ($view === 'manifest') {
    $where  = ['b.sold_by_admin_id = :agent', "b.status = 'confirmed'"];
    $params = ['agent' => $viewId];

    // Default to trips that have not departed yet — the list an agent
    // actually works from. An explicit date overrides that.
    if ($date !== '') {
        $where[]        = 'bl.travel_date = :d';
        $params['d']    = $date;
    } else {
        $where[]        = 'bl.travel_date >= :d';
        $params['d']    = $today;
    }
    if ($q !== '') {
        $where[] = sqlSearchClause([
            'bp.full_name LIKE %s',
            'b.contact_phone LIKE %s',
            'b.pnr LIKE %s',
        ], $q, $params);
    }

    $manifest = Database::fetchAll(
        "SELECT bp.full_name, bp.seat_no, bp.age, bp.gender, bp.boarded_at,
                b.pnr, b.contact_phone, b.total_amount,
                bl.travel_date, bl.boarding_stop, bl.drop_stop,
                r.from_city, r.to_city, r.dep_time, r.route_code
           FROM booking_passengers bp
           JOIN bookings b  ON b.id = bp.booking_id
           LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           LEFT JOIN schedules s ON s.id = bl.schedule_id
           LEFT JOIN routes r ON r.id = s.route_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY bl.travel_date ASC, r.dep_time ASC, bp.seat_no ASC
          LIMIT 500",
        $params
    );

    // Group in PHP rather than running a query per trip.
    foreach ($manifest as $row) {
        $key = ($row['travel_date'] ?? '—') . '|' . ($row['route_code'] ?? '');
        if (!isset($trips[$key])) {
            $trips[$key] = [
                'date'  => $row['travel_date'],
                'from'  => $row['from_city'],
                'to'    => $row['to_city'],
                'dep'   => $row['dep_time'],
                'code'  => $row['route_code'],
                'pax'   => [],
                'value' => 0.0,
            ];
        }
        $trips[$key]['pax'][] = $row;
    }
}

/* =====================================================================
 *  CUSTOMERS — one row per phone number this agent has sold to
 * ===================================================================== */
$customers = [];

if ($view === 'customers') {
    $where  = ['b.sold_by_admin_id = :agent'];
    $params = ['agent' => $viewId];

    if ($q !== '') {
        $where[] = sqlSearchClause([
            'b.contact_phone LIKE %s',
            'EXISTS (SELECT 1 FROM booking_passengers bp2
                      WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
        ], $q, $params);
    }

    // Grouped on the contact phone: that is the identity a counter actually
    // has. Spend counts confirmed bookings only, so a cancelled ticket does
    // not inflate a customer's value.
    //
    // The scope id is bound twice under two names (:agent and :agent2).
    // Prepares are not emulated on this connection, so a named placeholder
    // reused in two places is an "Invalid parameter number", not a rebind.
    $params['agent2'] = $viewId;

    $customers = Database::fetchAll(
        "SELECT b.contact_phone,
                MAX(b.contact_email) AS email,
                COUNT(*) AS bookings,
                COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS spend,
                COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
                MAX(b.created_at) AS last_booked,
                (SELECT bp.full_name FROM booking_passengers bp
                   JOIN bookings b2 ON b2.id = bp.booking_id
                  WHERE b2.contact_phone = b.contact_phone
                    AND b2.sold_by_admin_id = :agent2
                  ORDER BY b2.id DESC LIMIT 1) AS name
           FROM bookings b
          WHERE " . implode(' AND ', $where) . "
          GROUP BY b.contact_phone
          ORDER BY spend DESC, last_booked DESC
          LIMIT 300",
        $params
    );
}

$tab = static function (string $key, string $label) use ($view, $base, $viewId, $isSupervisor, $scopeId): string {
    $qs = ['view' => $key];
    if ($isSupervisor && $scopeId === null) { $qs['agent'] = $viewId; }
    $on = $view === $key;
    return '<a class="btn' . ($on ? '' : ' ghost') . '" href="' . $base . '/admin/agent-passengers.php?'
         . Security::e(http_build_query($qs)) . '">' . Security::e($label) . '</a>';
};

admin_header(
    ($isOwn ? 'My ' : (string) ($viewing['full_name'] ?: $viewing['username']) . ' · ')
    . ($view === 'manifest' ? 'Passengers' : 'Customers'),
    'agent-passengers'
);
?>
<style>
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.1)}
.hcard .hicon{font-size:28px;margin-bottom:8px;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:12px;text-transform:uppercase;letter-spacing:.5px;opacity:.85}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1}
.hcard .hsub{font-size:12px;opacity:.75}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}

.dash-panel{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden}
.dash-panel .dp-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;background:var(--head);flex-wrap:wrap}
.dash-panel .dp-body{padding:20px}

@media(max-width:900px){
  .dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
}
</style>
<div class="toolbar">
  <?= $tab('manifest', '🧍 Passenger list') ?>
  <?= $tab('customers', '👥 My customers') ?>
</div>

<form class="toolbar" method="get">
  <input type="hidden" name="view" value="<?= Security::e($view) ?>">
  <?php if ($agentList !== []): ?>
    <select name="agent">
      <?php foreach ($agentList as $a): ?>
        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $viewId ? 'selected' : '' ?>>
          <?= Security::e($a['full_name'] ?: $a['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <?php if ($view === 'manifest'): ?>
    <input type="date" name="date" value="<?= Security::e($date) ?>" title="Travel date">
  <?php endif; ?>
  <input type="search" name="q" placeholder="<?= $view === 'manifest' ? 'Name, phone or PNR' : 'Name or phone' ?>"
         value="<?= Security::e($q) ?>" style="min-width:220px">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $date !== ''): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/agent-passengers.php?view=<?= Security::e($view) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php if ($view === 'manifest'): ?>

  <?php
    // Presentation-only tallies over the rows already fetched above.
    $paxTotal = count($manifest);
    $boarded  = 0;
    foreach ($manifest as $mrow) { if (!empty($mrow['boarded_at'])) { $boarded++; } }
  ?>
  <div class="dash-hero">
    <div class="hcard hc-blue">
      <span class="hicon">🧍</span>
      <div class="hk">Passengers</div>
      <div class="hv"><?= $paxTotal ?></div>
      <div class="hsub">confirmed · <?= $date !== '' ? Security::e(formatDate($date)) : 'upcoming trips' ?></div>
    </div>
    <div class="hcard hc-navy">
      <span class="hicon">🚌</span>
      <div class="hk">Departures</div>
      <div class="hv"><?= count($trips) ?></div>
      <div class="hsub">trips listed below</div>
    </div>
    <div class="hcard hc-green">
      <span class="hicon">✅</span>
      <div class="hk">Boarded</div>
      <div class="hv"><?= $boarded ?></div>
      <div class="hsub">of <?= $paxTotal ?> checked in</div>
    </div>
  </div>

  <?php if ($trips === []): ?>
    <div class="dash-panel"><div class="muted" style="padding:24px;text-align:center">
      No confirmed passengers <?= $date !== '' ? 'on ' . Security::e(formatDate($date)) : 'on upcoming trips' ?>.
    </div></div>
  <?php else: foreach ($trips as $t):
    $value = 0.0;
    foreach ($t['pax'] as $p) { $value += (float) $p['total_amount']; }
  ?>
    <div class="dash-panel" style="margin-bottom:20px">
      <div class="dp-head">🚌 <?= Security::e(($t['from'] ?? '—') . ' → ' . ($t['to'] ?? '—')) ?>
        · <?= Security::e(formatDate((string) $t['date'])) ?>
        <span class="muted" style="font-weight:400;margin-left:auto">
          <?= Security::e(formatTime((string) $t['dep'])) ?>
          · <?= count($t['pax']) ?> passenger<?= count($t['pax']) === 1 ? '' : 's' ?>
        </span>
      </div>
      <div style="overflow-x:auto">
      <table>
        <thead><tr><th>Seat</th><th>Passenger</th><th>Age / Gender</th><th>Phone</th><th>Boarding</th><th>PNR</th><th>Boarded</th></tr></thead>
        <tbody>
        <?php foreach ($t['pax'] as $p): ?>
          <tr>
            <td class="mono"><strong><?= Security::e((string) $p['seat_no']) ?></strong></td>
            <td><?= Security::e((string) $p['full_name']) ?></td>
            <td class="muted"><?= $p['age'] !== null ? (int) $p['age'] : '—' ?><?= $p['gender'] ? ' · ' . Security::e((string) $p['gender']) : '' ?></td>
            <td class="mono"><?= Security::e(maskPhone((string) $p['contact_phone'])) ?></td>
            <td class="muted" style="font-size:12px"><?= Security::e((string) ($p['boarding_stop'] ?: '—')) ?></td>
            <td class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>"><?= Security::e((string) $p['pnr']) ?></a></td>
            <td><?= $p['boarded_at'] ? '<span style="color:#0a6b3b;font-weight:700">✓ boarded</span>' : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endforeach; endif; ?>

<?php else: ?>

  <?php
    // Presentation-only tallies over the rows already fetched above.
    $custTickets = 0; $custSpend = 0.0;
    foreach ($customers as $crow) {
        $custTickets += (int) $crow['confirmed'];
        $custSpend   += (float) $crow['spend'];
    }
  ?>
  <div class="dash-hero">
    <div class="hcard hc-blue">
      <span class="hicon">👥</span>
      <div class="hk">Customers</div>
      <div class="hv"><?= count($customers) ?></div>
      <div class="hsub">people <?= $isOwn ? 'you have' : 'this agent has' ?> sold to</div>
    </div>
    <div class="hcard hc-navy">
      <span class="hicon">🎫</span>
      <div class="hk">Confirmed tickets</div>
      <div class="hv"><?= $custTickets ?></div>
      <div class="hsub">across the customers listed</div>
    </div>
    <div class="hcard hc-green">
      <span class="hicon">💰</span>
      <div class="hk">Total spend</div>
      <div class="hv"><?= Security::e(inr($custSpend)) ?></div>
      <div class="hsub">confirmed bookings only</div>
    </div>
  </div>

  <div class="dash-panel">
    <div class="dp-head">👥 <?= count($customers) ?> customer<?= count($customers) === 1 ? '' : 's' ?>
      <span class="muted" style="font-weight:400">· people <?= $isOwn ? 'you have' : 'this agent has' ?> sold to</span>
    </div>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Customer</th><th>Phone</th><th>Tickets</th><th>Spend</th><th>Last booked</th><th></th></tr></thead>
      <tbody>
      <?php if ($customers === []): ?>
        <tr><td colspan="6" class="muted" style="padding:22px;text-align:center">
          No customers yet<?= $q !== '' ? ' matching “' . Security::e($q) . '”' : '' ?>.
        </td></tr>
      <?php else: foreach ($customers as $c): ?>
        <tr>
          <td><strong><?= Security::e((string) ($c['name'] ?: '—')) ?></strong>
            <?php if (!empty($c['email'])): ?>
              <div class="muted" style="font-size:11px"><?= Security::e((string) $c['email']) ?></div>
            <?php endif; ?></td>
          <td class="mono"><?= Security::e(maskPhone((string) $c['contact_phone'])) ?></td>
          <td><?= (int) $c['confirmed'] ?>
            <?php if ((int) $c['bookings'] > (int) $c['confirmed']): ?>
              <span class="muted" style="font-size:11px">of <?= (int) $c['bookings'] ?></span>
            <?php endif; ?></td>
          <td><?= Security::e(inr((float) $c['spend'])) ?></td>
          <td class="muted"><?= Security::e(timeAgo((string) $c['last_booked'])) ?></td>
          <td><a class="btn ghost" style="padding:5px 10px;font-size:12px"
                 href="<?= $base ?>/admin/agent-sales.php?range=all&q=<?= urlencode((string) $c['contact_phone']) ?>">Tickets →</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php endif; ?>
<?php
admin_footer();
