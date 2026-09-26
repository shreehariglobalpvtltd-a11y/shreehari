<?php
/**
 * admin/passengers.php — Passenger register (17 Sep 2026).
 *
 * One row per TRAVELLER (booking_passengers), not per booking. The question
 * the office asks across dates — "which Ram Kumar travelled on the 12th,
 * what ID did he give, did we get his photo?" — had no home: the Tickets
 * list is per PNR, the manifest is per departure, Customers is per phone.
 * This is the per-person view: date · route · PNR · seat · name · age ·
 * gender · ID · special need · documents · booking status, filtered by
 *
 *   ?phone=   every traveller booked from one number (linked from Customers,
 *             the customer page and the ticket page)
 *   ?q=       name, ID number, PNR or phone (one bound LIKE per column via
 *             sqlSearchClause — prepares are not emulated on this connection)
 *   ?from= ?to=   travel-date window; with nothing asked for, today onwards
 *   ?route=   routes.id
 *   ?export=csv   the same set as a spreadsheet (shg_export_csv)
 *
 * Scoped exactly like the rest of the panel: a counter agent
 * (Auth::bookingScopeAdminId()) sees only passengers on bookings they sold,
 * so the page is safe behind bookings.view. The documents count degrades to
 * 0 while passenger_documents does not exist yet (PassengerDocs::available()).
 * Sort / filter in the browser over the fetched set (table.dt, capped at
 * 1,000 rows — narrow the window or search for more).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
require_once INCLUDE_PATH . '/passengerdocs.php';
$admin = admin_boot('bookings.view');

$base    = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$scopeId = Auth::bookingScopeAdminId();

/* ---- Filters ------------------------------------------------------ */
$phone    = preg_replace('/\D/', '', (string) ($_GET['phone'] ?? '')) ?? '';
$q        = Security::clean($_GET['q'] ?? '', 60);
$from     = Security::clean($_GET['from'] ?? '', 10);
$to       = Security::clean($_GET['to'] ?? '', 10);
$route    = Security::clean($_GET['route'] ?? '', 20);
$export   = (string) ($_GET['export'] ?? '');
$filtered = isset($_GET['f']);   // the filter form was submitted: blank dates mean "all dates"
if (!Security::isValidDate($from)) { $from = ''; }
if (!Security::isValidDate($to))   { $to = ''; }
if ($route !== '' && !ctype_digit($route)) { $route = ''; }

/* Default window: with nothing asked for, today onwards — the list a desk
   actually works from. A phone, a search or an explicit submit lifts it. */
if ($phone === '' && $q === '' && $from === '' && $to === '' && !$filtered) {
    $from = todayISO();
}

$routes = Database::fetchAll(
    'SELECT id, from_city, to_city, route_code FROM routes WHERE is_active = 1 ORDER BY from_city, to_city'
);

/* ---- The register ------------------------------------------------- */
$where  = [];
$params = [];
if ($scopeId !== null) {
    $where[] = 'b.sold_by_admin_id = :scope';
    $params['scope'] = $scopeId;
}
/* Desk isolation (26 Sep 2026, ships OFF): a counter window's passenger list
   is its own desk's. The bus MANIFEST is deliberately not scoped — the bus is
   shared, and the clerk boarding it must see every passenger on board. */
$deskScope = Auth::deskScopeCode();
if ($deskScope !== null && CounterDesk::stampColumn()) {
    $where[] = CounterDesk::scopeClause('b');
    $params['deskScope'] = $deskScope;
}
if ($phone !== '') {
    $where[] = 'b.contact_phone = :phone';
    $params['phone'] = $phone;
}
if ($q !== '') {
    $where[] = sqlSearchClause([
        'bp.full_name LIKE %s',
        'bp.id_number LIKE %s',
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
    ], $q, $params);
}
if ($from !== '') {
    $where[] = 'bl.travel_date >= :tfrom';
    $params['tfrom'] = $from;
}
if ($to !== '') {
    $where[] = 'bl.travel_date <= :tto';
    $params['tto'] = $to;
}
if ($route !== '') {
    $where[] = 's.route_id = :route';
    $params['route'] = (int) $route;
}
$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

// special_need arrived with upgrade-2026-09-passenger-special.sql; a database
// without it must still list its passengers.
$hasSpecial = false;
try {
    $hasSpecial = Database::fetch("SHOW COLUMNS FROM booking_passengers LIKE 'special_need'") !== null;
} catch (Throwable $e) {
    $hasSpecial = false;
}
$specialSql = $hasSpecial ? 'bp.special_need' : 'NULL';
// Documents per passenger — a correlated COUNT, only when the table exists.
$docsSql = PassengerDocs::available()
    ? '(SELECT COUNT(*) FROM passenger_documents pd WHERE pd.passenger_id = bp.id)'
    : '0';
$limit = $export === 'csv' ? 5000 : 1000;

$rows    = [];
$loadErr = '';
try {
    $rows = Database::fetchAll(
        "SELECT bp.id AS pax_id, bp.seat_no, bp.full_name, bp.age, bp.gender, bp.id_type, bp.id_number,
                bp.nationality, bp.is_primary, bp.boarded_at, {$specialSql} AS special_need,
                b.id AS booking_id, b.pnr, b.status, b.contact_phone, b.booking_mode, b.source,
                bl.travel_date, bl.boarding_stop, bl.drop_stop,
                r.from_city, r.to_city, r.route_code, r.coach_type,
                COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                {$docsSql} AS docs
           FROM booking_passengers bp
           JOIN bookings b       ON b.id = bp.booking_id
           LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           LEFT JOIN schedules s ON s.id = bl.schedule_id
           LEFT JOIN routes r    ON r.id = s.route_id
          {$whereSql}
          ORDER BY bl.travel_date DESC, dep_time ASC, b.id ASC, LENGTH(bp.seat_no), bp.seat_no
          LIMIT {$limit}",
        $params
    );
} catch (Throwable $e) {
    $loadErr = $e->getMessage();
    $rows    = [];
}

/* Presentation-only tallies over the rows fetched above. */
$total = count($rows);
$withId = 0; $withDoc = 0; $boarded = 0; $pnrs = [];
foreach ($rows as $r) {
    if (trim((string) ($r['id_number'] ?? '')) !== '') { $withId++; }
    if ((int) ($r['docs'] ?? 0) > 0) { $withDoc++; }
    if (!empty($r['boarded_at'])) { $boarded++; }
    $pnrs[(string) $r['pnr']] = true;
}

/* ---- Export ------------------------------------------------------- */
$header = ['Travel date', 'Route', 'Departure', 'PNR', 'Seat', 'Passenger', 'Age', 'Gender', 'ID type', 'ID number',
           'Nationality', 'Special need', 'Documents', 'Boarding point', 'Drop point', 'Contact phone', 'Booking status', 'Boarded'];
$flat = static fn(array $r): array => [
    (string) ($r['travel_date'] ?? ''),
    trim((string) ($r['from_city'] ?? '') . ' -> ' . (string) ($r['to_city'] ?? ''), ' ->'),
    substr((string) ($r['dep_time'] ?? ''), 0, 5),
    (string) $r['pnr'],
    Seats::displayLabel((string) $r['seat_no'], (string) ($r['coach_type'] ?: 'sleeper'), (string) ($r['booking_mode'] ?: 'sharing')),
    (string) $r['full_name'],
    $r['age'] !== null ? (int) $r['age'] : '',
    (string) ($r['gender'] ?? ''),
    (string) ($r['id_type'] ?? ''),
    (string) ($r['id_number'] ?? ''),
    (string) ($r['nationality'] ?? ''),
    (string) ($r['special_need'] ?? ''),
    (int) ($r['docs'] ?? 0),
    (string) ($r['boarding_stop'] ?? ''),
    (string) ($r['drop_stop'] ?? ''),
    (string) $r['contact_phone'],
    (string) $r['status'],
    !empty($r['boarded_at']) ? (string) $r['boarded_at'] : '',
];
if ($export === 'csv') {
    Logger::audit('passenger.export', 'booking', 'csv', null,
        ['rows' => count($rows), 'phone' => $phone, 'q' => $q, 'from' => $from, 'to' => $to, 'route' => $route],
        'passenger register exported');
    shg_export_csv('SHG-passengers-' . date('Y-m-d') . '.csv', $header, array_map($flat, $rows));
}

/* ---- Page --------------------------------------------------------- */
$rangeLabel = $from !== '' && $to !== '' ? formatDate($from, 'j M') . ' – ' . formatDate($to, 'j M Y')
    : ($from !== '' ? 'from ' . formatDate($from) : ($to !== '' ? 'up to ' . formatDate($to) : 'all dates'));
$qs = array_filter(['phone' => $phone, 'q' => $q, 'from' => $from, 'to' => $to, 'route' => $route],
    static fn($v): bool => $v !== '' && $v !== null);
$exportUrl = $base . '/admin/passengers.php?' . http_build_query($qs + ['f' => 1, 'export' => 'csv']);
$e = static fn($v): string => Security::e((string) ($v ?? ''));

admin_header('Passengers', 'passengers');

$actions = '<a class="btn ghost" href="' . Security::e($exportUrl) . '"><svg class="a-ic"><use href="#a-download"/></svg> CSV</a>';
if ($phone !== '' && Auth::can('customers.view')) {
    $actions = '<a class="btn ghost" href="' . $base . '/admin/customer-view.php?phone=' . urlencode($phone) . '"><svg class="a-ic"><use href="#a-user"/></svg> Customer history</a>' . $actions;
}
admin_page_head(
    'Every traveller on every ticket — name, age, ID and photo / document on file — one row per seat.',
    ['Tickets' => $base . '/admin/bookings.php', 'Passengers' => ''],
    $actions
);

if ($loadErr !== '') {
    echo '<div class="flash bad">The register could not be loaded: ' . Security::e($loadErr) . '</div>';
}
?>
<form method="get" class="panel" style="padding:14px 16px;margin-bottom:16px">
  <input type="hidden" name="f" value="1">
  <div class="form-grid">
    <div class="field">
      <label for="pxQ">Search</label>
      <input id="pxQ" type="search" name="q" value="<?= $e($q) ?>" placeholder="Name, ID number, PNR or phone">
    </div>
    <div class="field">
      <label for="pxPhone">Contact phone</label>
      <input id="pxPhone" type="tel" name="phone" value="<?= $e($phone) ?>" inputmode="numeric" placeholder="digits only">
    </div>
    <div class="field">
      <label for="pxFrom">Travel from</label>
      <input id="pxFrom" type="date" name="from" value="<?= $e($from) ?>">
    </div>
    <div class="field">
      <label for="pxTo">Travel to</label>
      <input id="pxTo" type="date" name="to" value="<?= $e($to) ?>">
    </div>
    <div class="field">
      <label for="pxRoute">Route</label>
      <select id="pxRoute" name="route">
        <option value="">All routes</option>
        <?php foreach ($routes as $rt): ?>
          <option value="<?= (int) $rt['id'] ?>" <?= $route !== '' && (int) $route === (int) $rt['id'] ? 'selected' : '' ?>><?= $e($rt['from_city'] . ' → ' . $rt['to_city'] . ' (' . $rt['route_code'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-actions">
    <button class="btn" type="submit"><svg class="a-ic"><use href="#a-filter"/></svg> Apply</button>
    <a class="btn ghost" href="<?= $base ?>/admin/passengers.php">Upcoming</a>
    <a class="btn ghost" href="<?= $base ?>/admin/passengers.php?f=1">All dates</a>
    <span class="muted" style="font-size:12px">Showing <?= $e($rangeLabel) ?><?= $phone !== '' ? ' · phone ' . $e($phone) : '' ?><?= $q !== '' ? ' · “' . $e($q) . '”' : '' ?><?= $total >= 1000 ? ' · first 1,000 rows — narrow the window for the rest' : '' ?></span>
  </div>
</form>

<div class="kpis">
  <?= admin_kpi('Passengers', (string) $total, count($pnrs) . ' ticket' . (count($pnrs) === 1 ? '' : 's') . ' · ' . $rangeLabel, 'users', 'blue') ?>
  <?= admin_kpi('ID on file', (string) $withId, $total > 0 ? (int) round($withId / $total * 100) . '% of the list' : 'no passengers in range', 'id-card', 'teal') ?>
  <?= admin_kpi('Photo / document', (string) $withDoc, PassengerDocs::available() ? ($total > 0 ? (int) round($withDoc / $total * 100) . '% have a file attached' : 'nothing to count yet') : 'store not set up yet', 'camera', 'violet') ?>
  <?= admin_kpi('Boarded', (string) $boarded, 'scanned in at the door', 'check', 'green') ?>
</div>

<div class="dt-bar" id="paxCtl">
  <input class="dt-q" type="search" placeholder="Filter these rows — name, PNR, seat, ID, phone" aria-label="Filter rows">
  <select data-dt-filter="status" aria-label="Booking status">
    <option value="">All statuses</option>
    <?php foreach (['confirmed', 'pending', 'completed', 'cancelled', 'rejected', 'expired'] as $st): ?>
      <option value="<?= $st ?>"><?= ucfirst($st) ?></option>
    <?php endforeach; ?>
  </select>
  <select data-dt-filter="idfile" aria-label="ID on file"><option value="">Any ID</option><option value="1">ID on file</option><option value="0">No ID</option></select>
  <select data-dt-filter="docs" aria-label="Documents"><option value="">Any documents</option><option value="1">Has photo / document</option><option value="0">No file yet</option></select>
  <select data-dt-filter="gender" aria-label="Gender"><option value="">Any gender</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
  <span class="dt-count"></span>
</div>

<div class="dt-wrap">
<table class="dt no-card" data-controls="paxCtl">
  <thead><tr>
    <th>Travel date</th><th>Route</th><th>PNR</th><th>Seat</th><th>Passenger</th><th data-type="num">Age</th><th>Gender</th>
    <th>ID</th><th>Special</th><th data-type="num">Docs</th><th>Status</th><th data-nosort>Open</th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="12" style="white-space:normal"><?= admin_empty('No passengers match', $loadErr !== '' ? 'The list could not be loaded.' : 'Widen the travel-date window, clear the search, or pick “All dates”.', '🧍') ?></td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
    $coach   = (string) ($r['coach_type'] ?: 'sleeper');
    $mode    = (string) ($r['booking_mode'] ?: 'sharing');
    $seatLbl = Seats::displayLabel((string) $r['seat_no'], $coach, $mode);
    $status  = strtolower((string) $r['status']);
    $docs    = (int) ($r['docs'] ?? 0);
    $idType  = trim((string) ($r['id_type'] ?? ''));
    $idNum   = trim((string) ($r['id_number'] ?? ''));
    $special = trim((string) ($r['special_need'] ?? ''));
    $openUrl = $base . '/admin/booking-view.php?pnr=' . urlencode((string) $r['pnr']) . '#pax';
    $docsUrl = $base . '/admin/booking-view.php?pnr=' . urlencode((string) $r['pnr']) . '#paxdocs';
    $search  =strtolower(implode(' ', [$r['full_name'], $r['pnr'], $r['contact_phone'], $idType, $idNum, $seatLbl, $r['seat_no'],
                  $r['from_city'] ?? '', $r['to_city'] ?? '', $r['route_code'] ?? '', $r['travel_date'] ?? '', $status]));
  ?>
    <tr data-search="<?= $e($search) ?>" data-status="<?= $e($status) ?>" data-idfile="<?= $idNum !== '' ? '1' : '0' ?>" data-docs="<?= $docs > 0 ? '1' : '0' ?>" data-gender="<?= $e(strtolower((string) ($r['gender'] ?? ''))) ?>"<?= in_array($status, ['cancelled', 'rejected', 'expired'], true) ? ' style="opacity:.7"' : '' ?>>
      <td data-sort="<?= $e($r['travel_date']) ?>"><?php if (!empty($r['travel_date'])): ?><b><?= $e(formatDate((string) $r['travel_date'], 'j M Y')) ?></b><div class="muted" style="font-size:11px"><?= $e(formatDate((string) $r['travel_date'], 'D')) ?><?= !empty($r['dep_time']) ? ' · ' . $e(substr((string) $r['dep_time'], 0, 5)) : '' ?></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><?= $e(($r['from_city'] ?? '—') . ' → ' . ($r['to_city'] ?? '—')) ?><?php if (!empty($r['boarding_stop'])): ?><div class="muted" style="font-size:11px">⬆ <?= $e($r['boarding_stop']) ?></div><?php endif; ?></td>
      <td class="mono"><a href="<?= $e($openUrl) ?>"><?= $e($r['pnr']) ?></a></td>
      <td class="mono"><b><?= $e($seatLbl) ?></b></td>
      <td><b><?= $e($r['full_name']) ?></b><?php if ((int) ($r['is_primary'] ?? 0) === 1): ?> <span class="pill st-info nodot" style="font-size:10px;padding:1px 6px">Primary</span><?php endif; ?><?php if (!empty($r['boarded_at'])): ?> <span class="pill st-ok nodot" style="font-size:10px;padding:1px 6px" title="<?= $e($r['boarded_at']) ?>">✓ boarded</span><?php endif; ?><div class="muted mono" style="font-size:11px"><?= $e($r['contact_phone']) ?></div></td>
      <td class="num"><?= $r['age'] !== null ? (int) $r['age'] : '<span class="muted">—</span>' ?></td>
      <td><?= $e($r['gender'] ?: '—') ?></td>
      <td><?php if ($idType !== '' || $idNum !== ''): ?><?= $e($idType) ?><?= $idType !== '' && $idNum !== '' ? ' ' : '' ?><span class="mono"><?= $e($idNum) ?></span><?php if (!empty($r['nationality'])): ?><div class="muted" style="font-size:11px"><?= $e($r['nationality']) ?></div><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><?= $special !== '' ? '<span class="pill st-ok nodot" style="font-size:10px;padding:1px 6px" title="Priority boarding">🩺 ' . $e(ucfirst($special)) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="num" data-sort="<?= $docs ?>"><?= $docs > 0 ? '<a href="' . $e($docsUrl) . '" title="Open the photo / ID documents">📎 ' . $docs . '</a>' : '<span class="muted">—</span>' ?></td>
      <td data-sort="<?= $e($status) ?>"><?= admin_pill($status) ?></td>
      <td><span class="dt-acts"><a class="btn ghost" href="<?= $e($openUrl) ?>">🎫 Ticket</a></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted" style="font-size:12px;margin-top:8px">Documents open from the ticket page (Edit booking details → Passenger photos &amp; ID documents); they are stored privately and never printed on the ticket. <?= $scopeId !== null ? 'You see the travellers on tickets you sold.' : 'Counter agents see only the travellers on their own sales.' ?></p>
<?php admin_footer(); ?>
