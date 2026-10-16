<?php
/**
 * admin/customer-view.php — one customer's booking history ON SCREEN.
 *
 * Until now the only per-customer history was a PDF download
 * (export.php?format=pdf&customer=…). This is the same query
 * ReportPdf::customerReport() runs, rendered as hero cards + a list, so the
 * screen and the PDF can never disagree. Gated on customers.view — counter
 * agents cannot reach it (they see their own passengers on
 * agent-passengers.php); the block / edit actions stay on customers.php.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('customers.view');
$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)

$phone = preg_replace('/\D/', '', (string) ($_GET['phone'] ?? '')) ?? '';
if ($phone === '' || strlen($phone) < 6) {
    Response::redirect('admin/customers.php');
}

$user = Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]);

$rows = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.source, b.total_amount, b.contact_phone, b.contact_email,
            b.created_at, b.sold_by_admin_id, b.booking_mode,
            r.from_city, r.to_city, bl.travel_date, bl.boarding_stop,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
               FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
            (SELECT bp.full_name FROM booking_passengers bp
              WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger,
            (SELECT p.method FROM payments p
              WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
            ad.full_name AS seller_name, ad.username AS seller_user
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s ON s.id = bl.schedule_id
       LEFT JOIN routes r ON r.id = s.route_id
       LEFT JOIN admins ad ON ad.id = b.sold_by_admin_id
      WHERE b.contact_phone = :phone
      ORDER BY b.id DESC LIMIT 500",
    ['phone' => $phone]
);

$paidStatuses = ['confirmed', 'completed'];
$spend = 0.0; $trips = 0; $seats = 0; $lastTravel = null; $cancelled = 0;
$routeCount = [];
foreach ($rows as $r) {
    if (in_array((string) $r['status'], $paidStatuses, true)) {
        $spend += (float) $r['total_amount'];
        $trips++;
        $seats += (int) $r['seat_count'];
        if (!empty($r['travel_date']) && ($lastTravel === null || $r['travel_date'] > $lastTravel)) {
            $lastTravel = (string) $r['travel_date'];
        }
        $rk = ($r['from_city'] ?? '') . ' → ' . ($r['to_city'] ?? '');
        $routeCount[$rk] = ($routeCount[$rk] ?? 0) + 1;
    } elseif ((string) $r['status'] === 'cancelled') {
        $cancelled++;
    }
}
arsort($routeCount);
$frequentRoute = $routeCount !== [] ? (string) array_key_first($routeCount) : '';
$frequentN     = $routeCount !== [] ? (int) reset($routeCount) : 0;

/* Payment history (5 Sep 2026) — every payment row on this customer's
   bookings, newest first, so "did they pay / how / when" is on screen. */
$payRows = [];
$auditRows = [];
if ($rows !== []) {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $ph  = []; $pp = [];
    foreach ($ids as $i => $id) { $ph[] = ':b' . $i; $pp['b' . $i] = $id; }
    $payRows = Database::fetchAll(
        'SELECT p.id, p.booking_id, p.payment_ref, p.method, p.amount, p.currency, p.utr_number, p.status, p.created_at, p.verified_at,
                b.pnr, ad.full_name AS verifier
           FROM payments p
           JOIN bookings b ON b.id = p.booking_id
           LEFT JOIN admins ad ON ad.id = p.verified_by
          WHERE p.booking_id IN (' . implode(',', $ph) . ')
          ORDER BY p.id DESC LIMIT 200',
        $pp
    );
    // Audit history: office edits on the account plus every change on the bookings.
    $pnrs = array_map(static fn(array $r): string => (string) $r['pnr'], $rows);
    $ah = []; $ap = [];
    foreach ($pnrs as $i => $p) { $ah[] = ':p' . $i; $ap['p' . $i] = $p; }
    $ap['uid'] = (string) ($user['id'] ?? 0);
    $auditRows = Database::fetchAll(
        "SELECT action, actor_name, actor_type, entity_type, entity_id, detail, old_value, new_value, created_at
           FROM audit_logs
          WHERE (entity_type = 'user' AND entity_id = :uid)
             OR (entity_type = 'booking' AND entity_id IN (" . implode(',', $ah) . "))
          ORDER BY id DESC LIMIT 40",
        $ap
    );
} elseif ($user !== null) {
    $auditRows = Database::fetchAll(
        "SELECT action, actor_name, actor_type, entity_type, entity_id, detail, old_value, new_value, created_at
           FROM audit_logs WHERE entity_type = 'user' AND entity_id = :uid ORDER BY id DESC LIMIT 40",
        ['uid' => (string) $user['id']]
    );
}

$name = $user['full_name'] ?? ($rows[0]['passenger'] ?? '');
$cc   = (string) ($user['country_code'] ?? '91');
$canManage = Auth::isSuperadmin() || Auth::can('bookings.edit');

/* Passenger photos / ID documents on this number (17 Sep 2026): one cheap
   COUNT for the button label, 0 until the passenger_documents migration has
   run. The files themselves open from the ticket page, never by path. */
require_once INCLUDE_PATH . '/passengerdocs.php';
$docCount = PassengerDocs::countForPhone($phone, Auth::bookingScopeAdminId());

admin_header('Customer · ' . ($name !== '' ? $name : '+' . $cc . ' ' . $phone), 'customers');
?>
<p style="margin:-6px 0 14px"><a href="<?= $base ?>/admin/customers.php">← All customers</a></p>

<?php if ($user === null && $rows === []): ?>
  <div class="panel"><h2>No customer found</h2>
    <div style="padding:16px 20px" class="muted">Nothing is recorded for <span class="mono">+<?= Security::e($cc) ?> <?= Security::e($phone) ?></span> — no account and no booking.</div>
  </div>
<?php else: ?>

<div class="dash-hero">
  <div class="hcard hc-blue"><span class="hicon">🎫</span><div class="hk">Trips booked</div><div class="hv"><?= $trips ?></div><div class="hsub"><?= $seats ?> seat<?= $seats === 1 ? '' : 's' ?> · <?= $cancelled ?> cancelled</div></div>
  <div class="hcard hc-green"><span class="hicon">💰</span><div class="hk">Total spend</div><div class="hv"><?= Security::e(inr($spend)) ?></div><div class="hsub">confirmed bookings only</div></div>
  <div class="hcard hc-navy"><span class="hicon">📅</span><div class="hk">Last travel</div><div class="hv" style="font-size:20px"><?= $lastTravel ? Security::e(formatDate($lastTravel)) : '—' ?></div><div class="hsub"><?= $rows !== [] ? 'first booking ' . Security::e(timeAgo((string) end($rows)['created_at'])) : '' ?></div></div>
  <div class="hcard hc-orange"><span class="hicon">🛣️</span><div class="hk">Frequent route</div><div class="hv" style="font-size:16px;line-height:1.25"><?= $frequentRoute !== '' ? Security::e($frequentRoute) : '—' ?></div><div class="hsub"><?= $frequentN > 0 ? $frequentN . ' trip' . ($frequentN === 1 ? '' : 's') . ' · ' . $cancelled . ' cancelled' : 'no confirmed trip yet' ?></div></div>
  <div class="hcard <?= !empty($user['is_blocked']) ? 'hc-red' : 'hc-teal' ?>"><span class="hicon"><?= !empty($user['is_blocked']) ? '⛔' : '👤' ?></span><div class="hk">Account</div>
    <div class="hv" style="font-size:20px"><?= $user === null ? 'Guest' : (!empty($user['is_blocked']) ? 'Blocked' : ucfirst((string) ($user['role'] ?? 'customer'))) ?></div>
    <div class="hsub"><?= $user !== null ? (int) ($user['loyalty_points'] ?? 0) . ' points · ' . Security::e((string) ($user['loyalty_tier'] ?? '')) : 'no app account' ?></div>
  </div>
</div>

<div class="panel">
  <h2>👤 <?= Security::e($name !== '' ? $name : 'Customer') ?>
    <span class="muted mono" style="font-weight:400;font-size:13px">· +<?= Security::e($cc) ?> <?= Security::e($phone) ?></span>
  </h2>
  <div style="padding:14px 18px;display:flex;gap:16px;flex-wrap:wrap;align-items:center">
    <?php if (!empty($user['email'])): ?><span>✉️ <?= Security::e((string) $user['email']) ?></span><?php endif; ?>
    <?php if (!empty($user['last_login_at'])): ?><span class="muted">last sign-in <?= Security::e(timeAgo((string) $user['last_login_at'])) ?></span><?php endif; ?>
    <?php if (!empty($user['blocked_reason'])): ?><span style="color:#8a1f1f">⛔ <?= Security::e((string) $user['blocked_reason']) ?></span><?php endif; ?>
    <span style="margin-left:auto;display:inline-flex;gap:8px;flex-wrap:wrap">
      <a class="btn ghost" href="https://wa.me/<?= Security::e(strlen($phone) === 10 ? $cc . $phone : $phone) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
      <a class="btn ghost" href="<?= $base ?>/admin/export.php?format=pdf&amp;customer=<?= urlencode($phone) ?>" target="_blank" rel="noopener">📄 PDF report</a>
      <?php /* Every traveller booked from this number, one row per seat (17 Sep 2026). */ ?>
      <a class="btn ghost" href="<?= $base ?>/admin/passengers.php?phone=<?= urlencode($phone) ?>" title="Every traveller booked from this number — name, age, ID, photo / document"><svg class="a-ic"><use href="#a-id-card"/></svg> Passengers<?= $docCount > 0 ? ' · 📎 ' . (int) $docCount : '' ?></a>
      <?php if ($canManage && $user !== null): ?><a class="btn ghost" href="<?= $base ?>/admin/customers.php?q=<?= urlencode($phone) ?>">✏️ Edit / block</a><?php endif; ?>
    </span>
  </div>
</div>

<div class="panel">
  <h2><?= count($rows) ?> booking<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 500 ? ' (latest 500)' : '' ?></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>PNR</th><th>Route / Travel</th><th>Seats</th><th>Passenger</th><th>Amount</th><th>Paid via</th><th>Status</th><th>Booked</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="8" class="muted" style="padding:22px;text-align:center">No bookings yet.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td class="mono" data-label="PNR"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a></td>
        <td data-label="Route / Travel"><span><?= Security::e(($r['from_city'] ?? '—') . ' → ' . ($r['to_city'] ?? '—')) ?>
          <div class="muted"><?= $r['travel_date'] ? Security::e(formatDate((string) $r['travel_date'])) : '' ?><?= !empty($r['boarding_stop']) ? ' · ' . Security::e((string) $r['boarding_stop']) : '' ?></div></span></td>
        <td class="mono" data-label="Seats"><?= Security::e((string) ($r['seats'] ? Seats::displayLabels(explode(' ', (string) $r['seats']), 'sleeper', (string) ($r['booking_mode'] ?? 'sharing'), ' ') : '—')) ?></td>
        <td data-label="Passenger"><?= Security::e((string) ($r['passenger'] ?? '—')) ?></td>
        <td data-label="Amount"><?= Security::e(inr((float) $r['total_amount'])) ?></td>
        <td data-label="Paid via"><span class="muted"><?= Security::e(strtoupper((string) ($r['pay_method'] ?? '—'))) ?></span>
          <?php if (!empty($r['seller_name'])): ?><div class="muted" style="font-size:11px">via <?= Security::e((string) $r['seller_name']) ?></div><?php endif; ?></td>
        <td data-label="Status"><?= admin_pill((string) $r['status']) ?></td>
        <td class="muted" data-label="Booked"><?= Security::e(timeAgo((string) $r['created_at'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="panel">
  <h2>💳 Payment history <span class="muted" style="font-weight:400;font-size:13px">· <?= count($payRows) ?> record<?= count($payRows) === 1 ? '' : 's' ?></span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>When</th><th>PNR</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th>Verified</th></tr></thead>
    <tbody>
    <?php if ($payRows === []): ?>
      <tr><td colspan="7" class="muted" style="padding:18px;text-align:center">No payment records.</td></tr>
    <?php else: foreach ($payRows as $p): ?>
      <tr>
        <td data-label="When" class="muted"><?= Security::e(formatDate((string) $p['created_at'], 'j M Y g:i A')) ?></td>
        <td data-label="PNR" class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>"><?= Security::e((string) $p['pnr']) ?></a></td>
        <td data-label="Method"><?= Security::e(strtoupper((string) $p['method'])) ?></td>
        <td data-label="Reference" class="mono muted"><?= Security::e((string) ($p['utr_number'] ?: $p['payment_ref'])) ?></td>
        <td data-label="Amount"><?= Security::e(($p['currency'] === 'NPR' ? 'रू ' : '') . inr((float) $p['amount'])) ?></td>
        <td data-label="Status"><?= admin_pill((string) $p['status']) ?></td>
        <td data-label="Verified" class="muted"><?= !empty($p['verified_at']) ? Security::e(formatDate((string) $p['verified_at'], 'j M Y') . (!empty($p['verifier']) ? ' · ' . $p['verifier'] : '')) : '—' ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="panel">
  <h2>📋 Change history <span class="muted" style="font-weight:400;font-size:13px">· who / when / what on this customer and their bookings</span></h2>
  <?php if ($auditRows === []): ?>
    <div style="padding:16px 20px" class="muted">No office edits recorded yet.</div>
  <?php else: ?>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>On</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($auditRows as $a): ?>
      <tr>
        <td data-label="When" class="muted"><?= Security::e(formatDate((string) $a['created_at'], 'j M Y g:i A')) ?></td>
        <td data-label="Who"><?= Security::e((string) ($a['actor_name'] ?: strtoupper((string) $a['actor_type']))) ?></td>
        <td data-label="What" class="mono"><?= Security::e((string) $a['action']) ?></td>
        <td data-label="On" class="mono"><?= Security::e((string) $a['entity_type'] . ' ' . (string) $a['entity_id']) ?></td>
        <td data-label="Detail" style="white-space:normal;max-width:420px"><?= Security::e((string) ($a['detail'] ?? '')) ?>
          <?php if (!empty($a['old_value']) || !empty($a['new_value'])): ?>
            <div class="muted" style="font-size:11px;margin-top:3px"><?= !empty($a['old_value']) ? 'old: ' . Security::e(mb_substr((string) $a['old_value'], 0, 140)) : '' ?><?= !empty($a['new_value']) ? ' → new: ' . Security::e(mb_substr((string) $a['new_value'], 0, 140)) : '' ?></div>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
admin_footer();
