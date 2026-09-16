<?php
/**
 * admin/payments.php — Payment Verification Center.
 *
 * A tabbed, filterable dashboard for verifying incoming payments,
 * settling COD, reviewing approved/rejected/refunded bookings.
 *
 * POST actions (approve / reject / cod_settle) funnel through
 * BookingService so the business rules live in one place.
 * When the POST includes X-Requested-With: XMLHttpRequest the
 * response is JSON, otherwise a flash + full-page render.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$base  = '';
$flash = null;
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

/* ---- Source badge helper ---------------------------------------- */
function source_badge(string $source): string
{
    $map = [
        'web'     => ['Online',  '#0a6b3b', '#d7f4e3'],
        'app'     => ['Online',  '#0a6b3b', '#d7f4e3'],
        'agent'   => ['Agent',   '#1c3b72', '#e2ecfb'],
        'counter' => ['Counter', '#7a4a00', '#ffe6c7'],
        'admin'   => ['Admin',   '#5a3fb0', '#efeaff'],
    ];
    [$label, $fg, $bg] = $map[$source] ?? [ucfirst($source ?: 'Online'), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . Security::e($label) . '</span>';
}

/** Method badge for payment type. */
function method_badge(string $method): string
{
    $map = [
        'upi'    => ['UPI',    '#1a5c2e', '#d7f4e3'],
        'esewa'  => ['eSewa',  '#3d7a1c', '#e5f7d6'],
        'cash'   => ['Cash',   '#7a4a00', '#ffe6c7'],
        'bank'   => ['Bank',   '#1c3b72', '#e2ecfb'],
        'wallet' => ['Wallet', '#5a3fb0', '#efeaff'],
        'cod'    => ['COD',    '#8a6d00', '#fff4d1'],
    ];
    [$label, $fg, $bg] = $map[strtolower($method)] ?? [strtoupper($method), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . Security::e($label) . '</span>';
}

/* ---- Handle Approve / Reject / COD Settle ----------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $action    = (string) ($_POST['action'] ?? '');
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $note      = Security::clean($_POST['note'] ?? '', 255);
        $pnr       = '';

        try {
            if ($action === 'approve') {
                Auth::requireAdmin('payments.verify');
                $b = BookingService::confirm($bookingId, (int) $admin['id'], $note);
                $pnr = $b['pnr'] ?? '';
                $flash = ['ok', 'Payment verified — ticket issued for ' . $pnr . '.'];
            } elseif ($action === 'reject') {
                Auth::requireAdmin('payments.reject');
                if ($note === '') { $note = 'Payment could not be verified.'; }
                $b = BookingService::reject($bookingId, (int) $admin['id'], $note);
                $pnr = $b['pnr'] ?? '';
                $flash = ['ok', 'Booking ' . $pnr . ' rejected and seats released.'];
            } elseif ($action === 'cod_settle') {
                Auth::requireAdmin('payments.verify');
                $b = BookingService::settleCod($bookingId, (int) $admin['id'], $note);
                $pnr = $b['pnr'] ?? '';
                $flash = ['ok', 'Cash recorded for ' . $pnr . ' — that payment is settled.'];
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }

    /* AJAX: return JSON and exit */
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'      => $flash[0] === 'ok',
            'message' => $flash[1],
            'pnr'     => $pnr ?? '',
        ]);
        exit;
    }
}

/* ---- Tab + filter params ---------------------------------------- */
$validTabs = ['all', 'pending', 'approved', 'rejected', 'cod', 'refunded'];
$tab       = Security::clean($_GET['tab'] ?? 'pending', 20);
if (!in_array($tab, $validTabs, true)) { $tab = 'pending'; }

$fSearch   = Security::clean($_GET['q'] ?? '', 60);
$fRoute    = (int) ($_GET['route'] ?? 0);
$fAgent    = (int) ($_GET['agent'] ?? 0);
$fMethod   = Security::clean($_GET['method'] ?? '', 20);
$fSource   = Security::clean($_GET['src'] ?? '', 20);
$fDateFrom = Security::clean($_GET['from'] ?? '', 10);
$fDateTo   = Security::clean($_GET['to'] ?? '', 10);
$isDate    = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

/* ---- Summary cards (efficient separate queries) ----------------- */
$pendingCount = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bookings WHERE status='pending'"
)['c'];

$approvedRow = Database::fetch(
    "SELECT COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t
       FROM bookings WHERE status='confirmed' AND confirmed_at >= CURDATE() AND confirmed_at < CURDATE() + INTERVAL 1 DAY"
);
$approvedCount = (int) $approvedRow['c'];
$approvedTotal = (float) $approvedRow['t'];

$rejectedCount = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bookings WHERE status='rejected' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY"
)['c'];

$codRow = Database::fetch(
    "SELECT COUNT(*) AS c, COALESCE(SUM(b.total_amount),0) AS t
       FROM bookings b
       JOIN payments p ON p.booking_id=b.id
            AND p.id=(SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id=b.id)
      WHERE b.is_cod=1 AND p.status='cod_pending'
        AND b.status NOT IN ('cancelled','rejected')"
);
$codCount = (int) $codRow['c'];
$codTotal = (float) $codRow['t'];

$collectionTotal = (float) Database::fetch(
    "SELECT COALESCE(SUM(total_amount),0) AS t
       FROM bookings WHERE status='confirmed' AND confirmed_at >= CURDATE() AND confirmed_at < CURDATE() + INTERVAL 1 DAY"
)['t'];

/* ---- Filter dropdowns data -------------------------------------- */
$routeList = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city FROM routes ORDER BY from_city, to_city"
);
$agentList = Database::fetchAll(
    "SELECT id, username, full_name FROM admins WHERE role='agent' ORDER BY full_name, username"
);
$agentCodes = Settings::getArray('agent_codes', []);

/* ---- Build main query ------------------------------------------- */
$where  = [];
$params = [];

/* Tab filter */
switch ($tab) {
    case 'pending':
        $where[] = "b.status = 'pending'";
        break;
    case 'approved':
        $where[] = "b.status = 'confirmed'";
        break;
    case 'rejected':
        $where[] = "b.status = 'rejected'";
        break;
    case 'cod':
        $where[] = "b.is_cod = 1 AND p.status = 'cod_pending' AND b.status NOT IN ('cancelled','rejected')";
        break;
    case 'refunded':
        $where[] = "b.refund_status IN ('pending','processed')";
        break;
    // 'all' — no status filter
}

/* Search filter */
if ($fSearch !== '') {
    $where[] = sqlSearchClause([
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
        'EXISTS (SELECT 1 FROM booking_passengers bp2
                  WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
    ], $fSearch, $params, 'sq');
}

/* Route filter */
if ($fRoute > 0) {
    $where[] = 'r.id = :fRoute';
    $params['fRoute'] = $fRoute;
}

/* Agent filter */
if ($fAgent > 0) {
    $where[] = 'b.sold_by_admin_id = :fAgent';
    $params['fAgent'] = $fAgent;
}

/* Payment method filter */
$validMethods = ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'];
if (in_array($fMethod, $validMethods, true)) {
    $where[] = 'p.method = :fMethod';
    $params['fMethod'] = $fMethod;
}

/* Booking source filter */
$validSources = ['web', 'app', 'agent', 'counter', 'admin'];
if (in_array($fSource, $validSources, true)) {
    $where[] = 'b.source = :fSource';
    $params['fSource'] = $fSource;
}

/* Date range (travel date) */
if ($isDate($fDateFrom)) {
    $where[] = 'bl.travel_date >= :fDateFrom';
    $params['fDateFrom'] = $fDateFrom;
}
if ($isDate($fDateTo)) {
    $where[] = 'bl.travel_date <= :fDateTo';
    $params['fDateTo'] = $fDateTo;
}

$orderBy = ($tab === 'pending') ? 'b.created_at ASC' : 'b.created_at DESC';

$sql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.source,
               b.sold_by_admin_id, b.is_cod, b.created_at, b.booking_mode, b.refund_status,
               p.method, p.utr_number, p.payer_name, p.status AS pay_status,
               p.reject_reason, p.verified_at,
               r.from_city, r.to_city, r.route_code,
               bl.travel_date,
               bu.bus_name,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
               (SELECT file_path FROM payment_screenshots ps WHERE ps.booking_id = b.id ORDER BY ps.id DESC LIMIT 1) AS shot,
               seller.full_name AS seller_name
          FROM bookings b
          JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
          LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = bl.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
          LEFT JOIN buses bu ON bu.id = s.bus_id
          LEFT JOIN admins seller ON seller.id = b.sold_by_admin_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY ' . $orderBy
     . ' LIMIT 200';

$rows = Database::fetchAll($sql, $params);

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/* ---- Build query string helper for tabs (preserves filters) ----- */
$filterQs = array_filter([
    'q'      => $fSearch,
    'route'  => $fRoute > 0 ? (string) $fRoute : '',
    'agent'  => $fAgent > 0 ? (string) $fAgent : '',
    'method' => $fMethod,
    'src'    => $fSource,
    'from'   => $isDate($fDateFrom) ? $fDateFrom : '',
    'to'     => $isDate($fDateTo)   ? $fDateTo   : '',
]);
$hasFilters = ($fSearch !== '' || $fRoute > 0 || $fAgent > 0 || $fMethod !== '' || $fSource !== '' || $isDate($fDateFrom) || $isDate($fDateTo));

function tabUrl(string $tabName, array $filterQs): string
{
    $qs = $filterQs;
    $qs['tab'] = $tabName;
    return '?'. http_build_query($qs);
}

admin_header('Payment Verification Center', 'payments');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<style>
/* ---- Payment page styles ---------------------------------------- */
.pay-tabs{display:flex;gap:0;margin-bottom:18px;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;flex-wrap:wrap}
.pay-tabs a{flex:1;min-width:100px;padding:12px 14px;text-align:center;font-size:13px;font-weight:700;color:var(--mut);text-decoration:none;border-right:1px solid var(--line);transition:background .15s,color .15s}
.pay-tabs a:last-child{border-right:0}
.pay-tabs a:hover{background:var(--hover);color:var(--ink)}
.pay-tabs a.active{background:var(--navy);color:#fff}
.pay-tabs .tab-count{display:block;font-size:18px;font-weight:800;margin-bottom:2px;line-height:1.2}
.pay-tabs .tab-label{font-size:11px;text-transform:uppercase;letter-spacing:.3px}



.pay-filter{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:end}
.pay-filter label{display:flex;flex-direction:column;gap:3px;font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.pay-filter input,.pay-filter select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;background:var(--card);color:var(--ink)}
.pay-filter input[type="search"]{min-width:180px}

.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
@media(min-width:821px){.tbl-wrap table{min-width:900px}}

tr.row-processing{opacity:.45;pointer-events:none;transition:opacity .3s}
tr.row-done td{background:var(--hover)}

.note-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.note-inline input{flex:1;min-width:80px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px;background:var(--card);color:var(--ink)}

.agent-tag{font-size:11px;color:var(--mut);white-space:nowrap}
.agent-tag strong{color:var(--ink);font-weight:700}

@media(max-width:820px){
  .pay-tabs a{min-width:70px;padding:10px 8px}
  .pay-tabs .tab-count{font-size:15px}
  .pay-filter{flex-direction:column}
  .pay-filter label,.pay-filter input,.pay-filter select{width:100%}
}
</style>

<!-- Summary Cards -->
<?php /* House style (.dash-hero/.hcard from _guard.php), not a third card
     system. payments.php was the only page in the panel with its own
     .sum-card/.sc-* language — and it is the screen staff look at most, so
     the odd one out was the most-seen one. data-stat hooks are unchanged:
     the AJAX approve/reject flow updates these counters in place. */ ?>
<div class="dash-hero" id="summaryCards">
  <div class="hcard hc-orange">
    <span class="hicon">⏳</span>
    <div class="hk">Pending Payments</div>
    <div class="hv" data-stat="pending"><?= $pendingCount ?></div>
  </div>
  <div class="hcard hc-green">
    <span class="hicon">✅</span>
    <div class="hk">Approved Today</div>
    <div class="hv" data-stat="approved"><?= $approvedCount ?></div>
    <div class="hsub"><?= Security::e(inr($approvedTotal)) ?></div>
  </div>
  <div class="hcard hc-red">
    <span class="hicon">✕</span>
    <div class="hk">Rejected Today</div>
    <div class="hv" data-stat="rejected"><?= $rejectedCount ?></div>
  </div>
  <div class="hcard hc-teal">
    <span class="hicon">💵</span>
    <div class="hk">Cash Due</div>
    <div class="hv" data-stat="cod"><?= $codCount ?></div>
    <div class="hsub"><?= Security::e(inr($codTotal)) ?></div>
  </div>
  <div class="hcard hc-blue">
    <span class="hicon">📈</span>
    <div class="hk">Collection Today</div>
    <div class="hv"><?= Security::e(inr($collectionTotal)) ?></div>
  </div>
</div>

<!-- Tabs -->
<div class="pay-tabs">
  <?php
  $tabDefs = [
      'all'      => ['All',       null],
      'pending'  => ['Pending',   $pendingCount],
      'approved' => ['Approved',  $approvedCount],
      'rejected' => ['Rejected',  $rejectedCount],
      'cod'      => ['Cash Due',  $codCount],
      'refunded' => ['Refunded',  null],
  ];
  foreach ($tabDefs as $tKey => [$tLabel, $tCount]): ?>
    <a href="<?= Security::e(tabUrl($tKey, $filterQs)) ?>" class="<?= $tab === $tKey ? 'active' : '' ?>">
      <?php if ($tCount !== null): ?><span class="tab-count"><?= (int) $tCount ?></span><?php endif; ?>
      <span class="tab-label"><?= Security::e($tLabel) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<form class="pay-filter" method="get">
  <input type="hidden" name="tab" value="<?= Security::e($tab) ?>">
  <label>
    Search
    <input type="search" name="q" placeholder="PNR / phone / name" value="<?= Security::e($fSearch) ?>">
  </label>
  <label>
    Travel Date From
    <input type="date" name="from" value="<?= Security::e($isDate($fDateFrom) ? $fDateFrom : '') ?>">
  </label>
  <label>
    Travel Date To
    <input type="date" name="to" value="<?= Security::e($isDate($fDateTo) ? $fDateTo : '') ?>">
  </label>
  <label>
    Route
    <select name="route">
      <option value="">All routes</option>
      <?php foreach ($routeList as $rt): ?>
        <option value="<?= (int) $rt['id'] ?>" <?= $fRoute === (int) $rt['id'] ? 'selected' : '' ?>><?= Security::e(($rt['from_city'] ?? '') . ' → ' . ($rt['to_city'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    Agent
    <select name="agent">
      <option value="">All agents</option>
      <?php foreach ($agentList as $ag):
          $aCode = isset($agentCodes[(string) $ag['id']]) ? 'SHG-' . str_pad((string) (int) $agentCodes[(string) $ag['id']], 4, '0', STR_PAD_LEFT) . ' ' : '';
      ?>
        <option value="<?= (int) $ag['id'] ?>" <?= $fAgent === (int) $ag['id'] ? 'selected' : '' ?>><?= Security::e($aCode . ($ag['full_name'] ?: $ag['username'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    Payment Method
    <select name="method">
      <option value="">All methods</option>
      <option value="upi"    <?= $fMethod === 'upi' ? 'selected' : '' ?>>UPI</option>
      <option value="esewa"  <?= $fMethod === 'esewa' ? 'selected' : '' ?>>eSewa</option>
      <option value="cash"   <?= $fMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
      <option value="bank"   <?= $fMethod === 'bank' ? 'selected' : '' ?>>Bank</option>
      <option value="cod"    <?= $fMethod === 'cod' ? 'selected' : '' ?>>COD</option>
      <option value="wallet" <?= $fMethod === 'wallet' ? 'selected' : '' ?>>Wallet</option>
    </select>
  </label>
  <label>
    Source
    <select name="src">
      <option value="">All sources</option>
      <option value="web"     <?= $fSource === 'web' ? 'selected' : '' ?>>Online</option>
      <option value="agent"   <?= $fSource === 'agent' ? 'selected' : '' ?>>Agent</option>
      <option value="counter" <?= $fSource === 'counter' ? 'selected' : '' ?>>Counter</option>
      <option value="admin"   <?= $fSource === 'admin' ? 'selected' : '' ?>>Admin</option>
    </select>
  </label>
  <div style="display:flex;gap:8px;align-items:end;padding-bottom:1px">
    <button class="btn" type="submit">Apply</button>
    <?php if ($hasFilters): ?><a class="btn ghost" href="?tab=<?= Security::e($tab) ?>">Clear</a><?php endif; ?>
  </div>
</form>

<!-- Results Table -->
<div class="panel">
  <h2><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 200 ? ' (showing latest 200)' : '' ?></h2>
  <div class="tbl-wrap">
  <table>
    <thead><tr>
      <th>PNR</th>
      <th>Route / Date</th>
      <th>Seats</th>
      <th>Amount</th>
      <th>Payment</th>
      <th>Proof</th>
      <th>Source</th>
      <th>Agent</th>
      <th>Status</th>
      <th style="min-width:240px">Actions</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="10" class="muted" style="padding:28px;text-align:center">
        <?php if ($tab === 'pending'): ?>
          All caught up — nothing pending.
        <?php else: ?>
          No bookings match these filters.
        <?php endif; ?>
      </td></tr>
    <?php else: foreach ($rows as $q):
        $bId       = (int) $q['id'];
        $bPnr      = (string) $q['pnr'];
        $bStatus   = (string) $q['status'];
        $payStatus = (string) ($q['pay_status'] ?? '');
        $isCod     = (int) $q['is_cod'] === 1;
        $soldBy    = (int) ($q['sold_by_admin_id'] ?? 0);
        $sellerName = (string) ($q['seller_name'] ?? '');
        $sellerCode = '';
        if ($soldBy > 0 && isset($agentCodes[(string) $soldBy])) {
            $sellerCode = 'SHG-' . str_pad((string) (int) $agentCodes[(string) $soldBy], 4, '0', STR_PAD_LEFT);
        }

        /* Decide which actions are available */
        $canApprove = ($bStatus === 'pending' && Auth::can('payments.verify'));
        $canReject  = ($bStatus === 'pending' && Auth::can('payments.reject'));
        $canSettle  = ($isCod && $payStatus === 'cod_pending'
                       && !in_array($bStatus, ['cancelled', 'rejected'], true)
                       && Auth::can('payments.verify'));
    ?>
      <tr data-pnr="<?= Security::e($bPnr) ?>" data-id="<?= $bId ?>">
        <!-- PNR -->
        <td class="mono">
          <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>"><?= Security::e($bPnr) ?></a>
          <div class="muted"><?= Security::e(timeAgo((string) $q['created_at'])) ?></div>
        </td>
        <!-- Route / Date / Bus -->
        <td>
          <?= Security::e(($q['from_city'] ?? '—') . ' → ' . ($q['to_city'] ?? '—')) ?>
          <div class="muted">
            <?= Security::e(formatDate($q['travel_date'] ?? null)) ?>
            <?php if (!empty($q['bus_name'])): ?> · <?= Security::e($q['bus_name']) ?><?php endif; ?>
          </div>
        </td>
        <!-- Seats + mode -->
        <td>
          <?= (int) $q['seat_count'] ?>
          <div class="muted" style="font-size:11px"><?= Security::e(ucfirst((string) ($q['booking_mode'] ?: 'seat'))) ?></div>
        </td>
        <!-- Amount -->
        <td>
          <strong><?= Security::e(inr((float) $q['total_amount'])) ?></strong>
          <?php if ($isCod): ?><div><span class="pill" style="color:#7a4a00;background:#ffe6c7;font-size:10px">COD</span></div><?php endif; ?>
        </td>
        <!-- Payment: method + UTR/payer -->
        <td>
          <?= method_badge((string) ($q['method'] ?? '')) ?>
          <?php if (!empty($q['utr_number'])): ?>
            <div class="mono" style="font-size:11px;margin-top:3px"><?= Security::e($q['utr_number']) ?></div>
          <?php endif; ?>
          <?php if (!empty($q['payer_name'])): ?>
            <div class="muted" style="font-size:11px"><?= Security::e($q['payer_name']) ?></div>
          <?php endif; ?>
        </td>
        <!-- Proof -->
        <td>
          <?php if (!empty($q['shot'])): ?>
            <a href="<?= $base ?>/admin/screenshot.php?id=<?= $bId ?>" target="_blank" style="font-size:12px">View</a>
          <?php else: ?><span class="muted" style="font-size:12px">none</span><?php endif; ?>
        </td>
        <!-- Source -->
        <td><?= source_badge((string) ($q['source'] ?? 'web')) ?></td>
        <!-- Agent -->
        <td>
          <?php if ($soldBy > 0 && ($sellerCode !== '' || $sellerName !== '')): ?>
            <span class="agent-tag">
              <?php if ($sellerCode !== ''): ?><strong><?= Security::e($sellerCode) ?></strong><br><?php endif; ?>
              <?= Security::e($sellerName ?: '—') ?>
            </span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <!-- Status -->
        <td>
          <?php
          $pillStatus = $payStatus;
          if ($bStatus === 'confirmed') { $pillStatus = 'confirmed'; }
          elseif ($bStatus === 'rejected') { $pillStatus = 'rejected'; }
          elseif ($bStatus === 'expired') { $pillStatus = 'expired'; }
          elseif ($payStatus === 'cod_pending') { $pillStatus = 'cod_pending'; }
          elseif ($bStatus === 'pending') { $pillStatus = 'pending'; }
          ?>
          <span class="row-pill"><?= admin_pill($pillStatus) ?></span>
          <?php if (!empty($q['reject_reason'])): ?>
            <div class="muted" style="font-size:11px;margin-top:3px" title="<?= Security::e($q['reject_reason']) ?>"><?= Security::e(mb_strimwidth($q['reject_reason'], 0, 40, '...')) ?></div>
          <?php endif; ?>
          <?php if (!empty($q['verified_at'])): ?>
            <div class="muted" style="font-size:10px"><?= Security::e(timeAgo((string) $q['verified_at'])) ?></div>
          <?php endif; ?>
        </td>
        <!-- Actions -->
        <td>
          <?php if ($canApprove || $canReject): ?>
            <form method="post" class="note-inline pay-action-form" data-booking-id="<?= $bId ?>">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="booking_id" value="<?= $bId ?>">
              <input type="text" name="note" placeholder="Note (optional)" class="action-note">
              <?php if ($canApprove): ?>
                <button class="btn ok btn-pay-action" type="submit" name="action" value="approve"
                        data-confirm="Verify this payment and issue the ticket?">Approve</button>
              <?php endif; ?>
              <?php if ($canReject): ?>
                <button class="btn bad btn-pay-action" type="submit" name="action" value="reject"
                        data-confirm="Reject this booking and release its seats?">Reject</button>
              <?php endif; ?>
            </form>
          <?php elseif ($canSettle): ?>
            <form method="post" class="note-inline pay-action-form" data-booking-id="<?= $bId ?>">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="booking_id" value="<?= $bId ?>">
              <input type="text" name="note" placeholder="Receipt / who collected" class="action-note">
              <button class="btn ok btn-pay-action" type="submit" name="action" value="cod_settle"
                      data-confirm="Record <?= Security::e(inr((float) $q['total_amount'])) ?> cash as collected for <?= Security::e($bPnr) ?>?">Cash Collected</button>
            </form>
          <?php else: ?>
            <a class="btn ghost" style="font-size:12px;padding:6px 12px" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>">View</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ---- Highlight row if ?pnr= is in the URL ---------------------- */
  var params = new URLSearchParams(location.search);
  var pnr = params.get('pnr');
  if (pnr) {
    var row = document.querySelector('tr[data-pnr="' + CSS.escape(pnr) + '"]');
    if (row) { row.classList.add('row-highlight'); row.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }

  /* ---- AJAX action handling --------------------------------------- */
  function showFlash(msg, type) {
    var existing = document.querySelector('.flash');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'flash ' + type;
    el.textContent = msg;
    var h1 = document.querySelector('.wrap h1');
    if (h1) h1.insertAdjacentElement('afterend', el);
    setTimeout(function () { el.remove(); }, 6000);
  }

  document.querySelectorAll('.pay-action-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var btn = e.submitter;
      if (!btn) return; // fallback to regular form

      var action  = btn.value;
      var noteEl  = form.querySelector('.action-note');
      var note    = noteEl ? noteEl.value.trim() : '';
      var confirmMsg = btn.getAttribute('data-confirm') || 'Are you sure?';

      /* Reject requires a reason */
      if (action === 'reject' && note === '') {
        e.preventDefault();
        var reason = prompt('Reason for rejection (required):');
        if (!reason || reason.trim() === '') return;
        noteEl.value = reason.trim();
        note = reason.trim();
      }

      if (!confirm(confirmMsg)) {
        e.preventDefault();
        return;
      }

      e.preventDefault();

      var row = form.closest('tr');
      if (row) row.classList.add('row-processing');
      btn.disabled = true;

      var fd = new FormData(form);
      fd.set('action', action);

      fetch(location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (row) row.classList.remove('row-processing');

        if (j.ok) {
          showFlash(j.message, 'ok');
          if (row) {
            row.classList.add('row-done');

            /* Update the status pill */
            var pillEl = row.querySelector('.row-pill');
            if (pillEl) {
              if (action === 'approve') {
                pillEl.innerHTML = '<span class="pill" style="color:#0a6b3b;background:#d7f4e3">Confirmed</span>';
              } else if (action === 'reject') {
                pillEl.innerHTML = '<span class="pill" style="color:#8a1f1f;background:#f7dcdc">Rejected</span>';
              } else if (action === 'cod_settle') {
                pillEl.innerHTML = '<span class="pill" style="color:#0a6b3b;background:#d7f4e3">Verified</span>';
              }
            }

            /* Replace actions with a View link */
            var actionTd = row.querySelector('td:last-child');
            if (actionTd) {
              var pnrLink = row.querySelector('td:first-child a');
              var viewHref = pnrLink ? pnrLink.href : '#';
              actionTd.innerHTML = '<a class="btn ghost" style="font-size:12px;padding:6px 12px" href="' + viewHref + '">View</a>';
            }
          }

          /* Update summary card counts */
          updateSummaryAfterAction(action);
        } else {
          showFlash(j.message || 'Something went wrong.', 'bad');
          btn.disabled = false;
        }
      })
      .catch(function () {
        if (row) row.classList.remove('row-processing');
        btn.disabled = false;
        showFlash('Network error — please try again.', 'bad');
      });
    });
  });

  /* ---- Update summary card numbers after an AJAX action ---------- */
  function updateSummaryAfterAction(action) {
    var pending  = document.querySelector('[data-stat="pending"]');
    var approved = document.querySelector('[data-stat="approved"]');
    var rejected = document.querySelector('[data-stat="rejected"]');
    var cod      = document.querySelector('[data-stat="cod"]');

    if (action === 'approve' && pending && approved) {
      var pVal = Math.max(0, parseInt(pending.textContent, 10) - 1);
      pending.textContent = pVal;
      approved.textContent = parseInt(approved.textContent, 10) + 1;
    } else if (action === 'reject' && pending && rejected) {
      var pVal2 = Math.max(0, parseInt(pending.textContent, 10) - 1);
      pending.textContent = pVal2;
      rejected.textContent = parseInt(rejected.textContent, 10) + 1;
    } else if (action === 'cod_settle' && cod) {
      cod.textContent = Math.max(0, parseInt(cod.textContent, 10) - 1);
    }

    /* Update tab counts too */
    var tabLinks = document.querySelectorAll('.pay-tabs a');
    tabLinks.forEach(function (a) {
      var countEl = a.querySelector('.tab-count');
      if (!countEl) return;
      var href = a.getAttribute('href') || '';
      if (action === 'approve') {
        if (href.indexOf('tab=pending') !== -1) countEl.textContent = Math.max(0, parseInt(countEl.textContent, 10) - 1);
        if (href.indexOf('tab=approved') !== -1) countEl.textContent = parseInt(countEl.textContent, 10) + 1;
      } else if (action === 'reject') {
        if (href.indexOf('tab=pending') !== -1) countEl.textContent = Math.max(0, parseInt(countEl.textContent, 10) - 1);
        if (href.indexOf('tab=rejected') !== -1) countEl.textContent = parseInt(countEl.textContent, 10) + 1;
      } else if (action === 'cod_settle') {
        if (href.indexOf('tab=cod') !== -1) countEl.textContent = Math.max(0, parseInt(countEl.textContent, 10) - 1);
      }
    });
  }
})();
</script>
<?php
admin_footer();
