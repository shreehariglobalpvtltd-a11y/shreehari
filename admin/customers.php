<?php
/**
 * admin/customers.php — Customers register (Excel-style, 5 Sep 2026).
 *
 * One scrolling table of EVERY customer the company knows: accounts in the
 * `users` table (name + mobile sign-in) UNION every distinct contact number
 * that ever booked (counter sales and bookings made before sign-in existed
 * have no users row). Each line carries the details a booking must collect
 * — name, mobile, document — plus what the office needs at a glance: how
 * many bookings, seats, money, last trip, status.
 *
 * Search / filter / sort happen in the browser over the full list (no
 * paging — the owner wants one page to scroll). Edit / block / delete post
 * back here; View expands the row; Export streams CSV or .xlsx of exactly
 * the same columns. Phone is never edited: it is the login identity and
 * the key on every booking.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('Customers', 'customers');
    echo '<div class="flash bad">The customers register is an office page.</div>';
    admin_footer();
    exit;
}

$canManage = Auth::isSuperadmin() || Auth::can('bookings.edit');
$flash     = null;

/* ---------- actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a manager or super-admin can change customer records.'];
    } else {
        $act   = (string) ($_POST['action'] ?? '');
        $phone = normalisePhone(Security::clean($_POST['phone'] ?? '', 20));
        $u     = $phone !== '' ? Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]) : null;
        $bkCount = $phone !== '' ? (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $phone], 0) : 0;

        // A number that only exists on bookings gets its account row the
        // first time the office edits or blocks it, so the change has a home.
        $ensureUser = static function () use (&$u, $phone): ?array {
            if ($u !== null || !Security::isValidPhone($phone)) { return $u; }
            $lead = Database::fetch(
                'SELECT bp.full_name, bp.id_type, bp.id_number FROM booking_passengers bp
                   JOIN bookings b ON b.id = bp.booking_id
                  WHERE b.contact_phone = :p AND bp.is_primary = 1 ORDER BY b.id DESC LIMIT 1',
                ['p' => $phone]
            );
            $id = Database::insert('users', [
                'phone' => $phone, 'full_name' => $lead['full_name'] ?? null, 'role' => 'customer',
                'country_code' => '91', 'phone_verified' => 1,
                'id_type' => $lead['id_type'] ?? null, 'id_number' => $lead['id_number'] ?? null,
            ]);
            return $u = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => $id]);
        };

        if ($phone === '' || !Security::isValidPhone($phone)) {
            $flash = ['bad', 'Customer not found.'];
        } elseif ($act === 'edit') {
            $name  = trim(Security::clean($_POST['full_name'] ?? '', 120));
            $email = Security::email($_POST['email'] ?? '');
            $idT   = trim(Security::clean($_POST['id_type'] ?? '', 60));
            $idN   = trim(Security::clean($_POST['id_number'] ?? '', 60));
            $cc    = in_array((string) ($_POST['country_code'] ?? ''), ['91', '977'], true) ? (string) $_POST['country_code'] : null;
            if (mb_strlen($name) < 2) {
                $flash = ['bad', 'Enter the customer\'s name.'];
            } else {
                $u = $ensureUser();
                $before = ['full_name' => $u['full_name'], 'email' => $u['email'], 'id_type' => $u['id_type'], 'id_number' => $u['id_number']];
                $after  = ['full_name' => $name, 'email' => $email !== '' ? $email : null, 'id_type' => $idT ?: null, 'id_number' => $idN ?: null];
                if ($cc !== null) { $after['country_code'] = $cc; }
                Database::update('users', $after, 'id = :id', ['id' => (int) $u['id']]);
                Logger::audit('customer.edit', 'user', (string) $u['id'], $before, $after, 'customers register edit');
                $flash = ['ok', 'Customer updated.'];
            }
        } elseif ($act === 'block' || $act === 'unblock') {
            $u = $ensureUser();
            if ($act === 'block') {
                $reason = trim(Security::clean($_POST['reason'] ?? '', 255));
                Database::update('users', ['is_blocked' => 1, 'blocked_reason' => $reason ?: 'Blocked by admin'], 'id = :id', ['id' => (int) $u['id']]);
                Logger::audit('customer.block', 'user', (string) $u['id'], null, ['reason' => $reason], 'admin blocked customer');
                $flash = ['ok', 'Customer blocked — they can no longer book online.'];
            } else {
                Database::update('users', ['is_blocked' => 0, 'blocked_reason' => null], 'id = :id', ['id' => (int) $u['id']]);
                Logger::audit('customer.unblock', 'user', (string) $u['id'], null, null, 'admin unblocked customer');
                $flash = ['ok', 'Customer unblocked.'];
            }
        } elseif ($act === 'delete') {
            // Only a record with NO bookings can go — a booking's contact
            // number is part of the money trail. Anyone with history is
            // blocked instead (kept for the audit trail).
            if ($bkCount > 0) {
                $flash = ['bad', 'This customer has ' . $bkCount . ' booking(s) — the record stays for the audit trail. Use Block instead.'];
            } elseif ($u === null) {
                $flash = ['bad', 'Nothing to delete.'];
            } elseif (!Auth::isSuperadmin()) {
                $flash = ['bad', 'Only a super-admin can delete a customer.'];
            } else {
                Database::delete('users', 'id = :id AND role = \'customer\'', ['id' => (int) $u['id']]);
                /* The vault is keyed by phone, not by users.id, so deleting
                   the account alone would leave the profile behind and the
                   "deleted" customer would still be recognised — and still
                   be counted — on their next visit. Two stores, one delete.
                   (A customer with bookings never reaches this branch, so no
                   travel history is lost here.) */
                try {
                    require_once INCLUDE_PATH . '/gemvault.php';
                    Database::delete('user_profiles', 'phone = :p', ['p' => normalisePhone((string) $u['phone'])]);
                    GemVault::forget((string) $u['phone']);
                } catch (Throwable $e) {
                    Logger::warning('vault row survived a customer delete', [
                        'error' => $e->getMessage(),
                    ], 'gemvault');
                }
                Logger::audit('customer.delete', 'user', (string) $u['id'], $u, null, 'customer deleted (no bookings)');
                $flash = ['ok', 'Customer deleted.'];
            }
        }
    }
}

/* ---------- the register ----------
   5 Sep 2026: server-side search (?q=) + pagination (50 per page). The
   register used to compute five correlated sub-queries for EVERY phone on
   every page view; now only the page's rows are computed. Exports still
   stream the whole (searched) set. */
$q       = trim(Security::clean($_GET['q'] ?? '', 60));
$page    = max(1, (int) ($_GET['p'] ?? 1));
$PER     = 50;
$export  = (string) ($_GET['export'] ?? '');
$phoneSet = "(SELECT phone FROM users WHERE role = 'customer'
             UNION
             SELECT contact_phone FROM bookings WHERE contact_phone <> '' AND contact_phone <> '0000000000') p
       LEFT JOIN users u ON u.phone = p.phone";
$searchSql = '';
$searchParams = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $searchSql = " WHERE (p.phone LIKE :q1 OR u.full_name LIKE :q2 OR u.email LIKE :q3
                   OR EXISTS (SELECT 1 FROM bookings bq LEFT JOIN booking_passengers bpq ON bpq.booking_id = bq.id
                               WHERE bq.contact_phone = p.phone AND (bq.pnr LIKE :q4 OR bpq.full_name LIKE :q5 OR bq.id_number LIKE :q6)))";
    $searchParams = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like];
}
$total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $phoneSet . $searchSql, $searchParams, 0);
$pages = max(1, (int) ceil($total / $PER));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $PER;
$limitSql = ($export === 'csv' || $export === 'xlsx') ? '' : ' LIMIT ' . (int) $PER . ' OFFSET ' . (int) $offset;

$rows = Database::fetchAll(
    "SELECT p.phone,
            COALESCE(u.id, 0)      AS uid,
            u.full_name            AS uname,
            u.email, u.country_code, u.is_blocked, u.blocked_reason, u.loyalty_points,
            u.id_type AS u_id_type, u.id_number AS u_id_number,
            u.last_login_at, u.created_at AS ucreated,
            COALESCE(b.cnt, 0)     AS cnt,
            COALESCE(b.pax, 0)     AS pax,
            COALESCE(b.spent, 0)   AS spent,
            b.first_at, b.last_at, b.last_travel,
            (SELECT bp.full_name FROM booking_passengers bp JOIN bookings bb ON bb.id = bp.booking_id
              WHERE bb.contact_phone = p.phone AND bp.is_primary = 1 ORDER BY bb.id DESC LIMIT 1) AS lead_name,
            (SELECT bb.pnr FROM bookings bb WHERE bb.contact_phone = p.phone ORDER BY bb.id DESC LIMIT 1) AS last_pnr,
            (SELECT bb.status FROM bookings bb WHERE bb.contact_phone = p.phone ORDER BY bb.id DESC LIMIT 1) AS last_status,
            (SELECT bb.id_type FROM bookings bb WHERE bb.contact_phone = p.phone AND bb.id_type <> '' ORDER BY bb.id DESC LIMIT 1) AS b_id_type,
            (SELECT bb.id_number FROM bookings bb WHERE bb.contact_phone = p.phone AND bb.id_number <> '' ORDER BY bb.id DESC LIMIT 1) AS b_id_number
       FROM " . $phoneSet . "
       LEFT JOIN (SELECT bk.contact_phone,
                         COUNT(*) AS cnt,
                         SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = bk.id)) AS pax,
                         SUM(CASE WHEN bk.status IN ('confirmed','completed') THEN bk.total_amount ELSE 0 END) AS spent,
                         MIN(bk.created_at) AS first_at,
                         MAX(bk.created_at) AS last_at,
                         MAX((SELECT MAX(bl.travel_date) FROM booking_legs bl WHERE bl.booking_id = bk.id)) AS last_travel
                    FROM bookings bk GROUP BY bk.contact_phone) b ON b.contact_phone = p.phone"
      . $searchSql . "
      ORDER BY COALESCE(b.last_at, u.created_at) DESC" . $limitSql,
    $searchParams
);

$header = ['Name', 'Mobile', 'Country', 'Email', 'Document', 'Bookings', 'Seats', 'Spent (Rs)', 'Last PNR', 'Last status', 'Last travel', 'First booking', 'Last booking', 'Points', 'Status', 'Account since'];
$flat   = static function (array $r): array {
    return [
        $r['uname'] ?: ($r['lead_name'] ?: ''),
        (string) $r['phone'],
        '+' . ($r['country_code'] ?: '91'),
        (string) ($r['email'] ?? ''),
        trim((string) (($r['u_id_type'] ?: $r['b_id_type']) ?? '') . ' ' . (string) (($r['u_id_number'] ?: $r['b_id_number']) ?? '')),
        (int) $r['cnt'], (int) $r['pax'], (float) $r['spent'],
        (string) ($r['last_pnr'] ?? ''), (string) ($r['last_status'] ?? ''), (string) ($r['last_travel'] ?? ''),
        (string) ($r['first_at'] ?? ''), (string) ($r['last_at'] ?? ''),
        (int) ($r['loyalty_points'] ?? 0),
        (int) ($r['is_blocked'] ?? 0) === 1 ? 'Blocked' : ((int) $r['uid'] > 0 ? 'Active' : 'Booking only'),
        (string) ($r['ucreated'] ?? ''),
    ];
};
if ($export === 'csv' || $export === 'xlsx') {
    Logger::audit('customer.export', 'user', $export, null, ['rows' => count($rows), 'q' => $q], 'customers register exported');
    $data = array_map($flat, $rows);
    if ($export === 'xlsx') { shg_export_xlsx('SHG-customers-' . date('Y-m-d') . '.xlsx', 'Customers', $header, $data, [5, 6, 7, 13]); }
    shg_export_csv('SHG-customers-' . date('Y-m-d') . '.csv', $header, $data);
}

// Cards count the WHOLE register (cheap index-served aggregates), not the page.
$withAcc = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'customer'", [], 0);
$blocked = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE role = 'customer' AND is_blocked = 1", [], 0);
$repeat  = (int) Database::scalar("SELECT COUNT(*) FROM (SELECT contact_phone FROM bookings WHERE contact_phone <> '' GROUP BY contact_phone HAVING COUNT(*) >= 2) x", [], 0);
$pageUrl = static fn(int $p): string => 'customers.php?' . http_build_query(array_filter(['q' => $q, 'p' => $p]));
$csrf    = Security::e(Security::csrfToken());
$k       = CSRF_TOKEN_NAME;
$e       = static fn($v): string => Security::e((string) ($v ?? ''));

admin_header('Customers', 'customers');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
?>
<div class="cards">
  <div class="card"><div class="k">Customers</div><div class="v"><?= $total ?></div></div>
  <div class="card"><div class="k">With account</div><div class="v"><?= $withAcc ?></div></div>
  <div class="card"><div class="k">Repeat travellers</div><div class="v"><?= $repeat ?></div></div>
  <div class="card"><div class="k">Blocked</div><div class="v"><?= $blocked ?></div></div>
</div>

<form method="get" class="dt-bar" id="custCtl" style="margin-bottom:10px">
  <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search name / mobile / email / PNR / document — press Enter" aria-label="Search customers" style="flex:1 1 260px;min-width:0">
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn ghost" href="customers.php">Clear</a><?php endif; ?>
</form>
<div class="dt-bar" id="custCtl2">
  <select data-dt-filter="status" aria-label="Status">
    <option value="">All statuses</option><option value="active">Active</option><option value="blocked">Blocked</option><option value="guest">Booking only (no account)</option>
  </select>
  <select data-dt-filter="trips" aria-label="Bookings">
    <option value="">Any bookings</option><option value="0">No booking yet</option><option value="1">1 booking</option><option value="2">Repeat (2+)</option>
  </select>
  <input type="month" data-dt-filter="lastym" data-dt-mode="min" aria-label="Last booking from" title="Last booking from (month)">
  <a class="btn ghost" href="customers.php?export=csv<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">⬇ CSV</a>
  <a class="btn ghost" href="customers.php?export=xlsx<?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>">⬇ Excel</a>
  <span class="dt-count"></span>
  <span class="muted" style="font-size:12px">· <?= number_format($total) ?> customer<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' matching' : '' ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></span>
</div>

<div class="dt-wrap">
<table class="dt no-card" data-controls="custCtl2">
  <thead><tr>
    <th>Name</th><th>Mobile</th><th>Document</th><th data-type="num">Bookings</th><th data-type="num">Seats</th>
    <th data-type="num">Spent ₹</th><th>Last trip</th><th>Last booking</th><th>Status</th><th data-nosort>Actions</th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="10" class="muted" style="padding:22px;white-space:normal">No customers yet — the first booking or sign-in creates one.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r):
    $name   = $r['uname'] ?: ($r['lead_name'] ?: '—');
    $status = (int) ($r['is_blocked'] ?? 0) === 1 ? 'blocked' : ((int) $r['uid'] > 0 ? 'active' : 'guest');
    $doc    = trim((string) (($r['u_id_type'] ?: $r['b_id_type']) ?? '') . ' ' . (string) (($r['u_id_number'] ?: $r['b_id_number']) ?? ''));
    $trips  = (int) $r['cnt'] >= 2 ? '2' : (string) (int) $r['cnt'];
    $rid    = 'c' . $i;
    $search = strtolower(implode(' ', [$name, $r['phone'], $r['email'] ?? '', $r['last_pnr'] ?? '', $doc]));
  ?>
    <tr data-search="<?= $e($search) ?>" data-status="<?= $status ?>" data-trips="<?= $trips ?>" data-lastym="<?= $e(substr((string) ($r['last_at'] ?? ''), 0, 7)) ?>"<?= $status === 'blocked' ? ' style="background:#fbeaea"' : '' ?>>
      <td><b><?= $e($name) ?></b><?php if (!empty($r['email'])): ?><div class="muted" style="font-size:11.5px"><?= $e($r['email']) ?></div><?php endif; ?></td>
      <td class="mono" data-sort="<?= $e($r['phone']) ?>">+<?= $e($r['country_code'] ?: '91') ?> <?= $e($r['phone']) ?></td>
      <td><?= $doc !== '' ? $e($doc) : '<span class="muted">—</span>' ?></td>
      <td class="num"><?= (int) $r['cnt'] ?></td>
      <td class="num"><?= (int) $r['pax'] ?></td>
      <td class="num" data-sort="<?= (float) $r['spent'] ?>"><?= number_format((float) $r['spent']) ?></td>
      <td data-sort="<?= $e($r['last_travel']) ?>"><?= $r['last_travel'] ? $e($r['last_travel']) : '<span class="muted">—</span>' ?></td>
      <td data-sort="<?= $e($r['last_at']) ?>"><?php if ($r['last_pnr']): ?><a href="booking-view.php?pnr=<?= urlencode((string) $r['last_pnr']) ?>" class="mono"><?= $e($r['last_pnr']) ?></a> <?= admin_pill((string) $r['last_status']) ?><div class="muted" style="font-size:11px"><?= $e(substr((string) $r['last_at'], 0, 16)) ?></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td data-sort="<?= $status ?>"><?php if ($status === 'blocked'): ?><span class="pill" style="background:#f7dcdc;color:#8a1f1f">Blocked</span><?php elseif ($status === 'active'): ?><span class="pill" style="background:#d7f4e3;color:#0a6b3b">Active</span><?php else: ?><span class="pill" style="background:#eef2fa;color:#33507f">Booking only</span><?php endif; ?></td>
      <td><span class="dt-acts">
        <button type="button" class="btn ghost" data-dt-toggle="<?= $rid ?>v">👁 View</button>
        <?php if ($canManage): ?><button type="button" class="btn ghost" data-dt-toggle="<?= $rid ?>e">✏️ Edit</button><?php endif; ?>
        <a class="btn ghost" href="customer-view.php?phone=<?= urlencode((string) $r['phone']) ?>">📋 History</a>
        <a class="btn ghost" href="passengers.php?phone=<?= urlencode((string) $r['phone']) ?>" title="Every traveller booked from this number — name, age, ID, photo / document (17 Sep 2026)">🧍 Passengers</a>
      </span></td>
    </tr>
    <tr class="dt-x" id="<?= $rid ?>v" hidden><td colspan="10">
      <div class="dt-grid">
        <div class="kv"><label>Full name</label><b><?= $e($name) ?></b></div>
        <div class="kv"><label>Mobile</label><b>+<?= $e($r['country_code'] ?: '91') ?> <?= $e($r['phone']) ?></b></div>
        <div class="kv"><label>Email</label><b><?= $e($r['email'] ?: '—') ?></b></div>
        <div class="kv"><label>Document</label><b><?= $doc !== '' ? $e($doc) : '—' ?></b></div>
        <div class="kv"><label>Bookings · seats · spent</label><b><?= (int) $r['cnt'] ?> · <?= (int) $r['pax'] ?> · ₹<?= number_format((float) $r['spent']) ?></b></div>
        <div class="kv"><label>First / last booking</label><b><?= $e($r['first_at'] ?: '—') ?><br><?= $e($r['last_at'] ?: '—') ?></b></div>
        <div class="kv"><label>Loyalty points</label><b><?= (int) ($r['loyalty_points'] ?? 0) ?></b></div>
        <div class="kv"><label>Account since / last login</label><b><?= $e($r['ucreated'] ?: 'no account') ?><br><?= $e($r['last_login_at'] ?: '—') ?></b></div>
        <?php if ($status === 'blocked'): ?><div class="kv"><label>Blocked reason</label><b><?= $e($r['blocked_reason'] ?: '—') ?></b></div><?php endif; ?>
      </div>
      <div class="dt-acts" style="padding:4px 2px">
        <a class="btn ghost" href="customer-view.php?phone=<?= urlencode((string) $r['phone']) ?>">📋 Booking history</a>
        <a class="btn ghost" href="passengers.php?phone=<?= urlencode((string) $r['phone']) ?>">🧍 Passengers</a>
        <a class="btn ghost" href="export.php?format=pdf&amp;customer=<?= urlencode((string) $r['phone']) ?>" target="_blank" rel="noopener">PDF</a>
        <?php if ($canManage): ?>
          <?php if ($status === 'blocked'): ?>
            <form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="phone" value="<?= $e($r['phone']) ?>"><button class="btn ok" name="action" value="unblock">Unblock</button></form>
          <?php else: ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Block this customer from booking online?')"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="phone" value="<?= $e($r['phone']) ?>"><input type="text" name="reason" placeholder="Reason" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px"> <button class="btn danger" name="action" value="block">⛔ Block</button></form>
          <?php endif; ?>
          <?php if ((int) $r['cnt'] === 0 && (int) $r['uid'] > 0 && Auth::isSuperadmin()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this customer record? This cannot be undone.')"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="phone" value="<?= $e($r['phone']) ?>"><button class="btn danger" name="action" value="delete">🗑 Delete</button></form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </td></tr>
    <?php if ($canManage): ?>
    <tr class="dt-x" id="<?= $rid ?>e" hidden><td colspan="10">
      <form method="post">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="phone" value="<?= $e($r['phone']) ?>">
        <div class="dt-grid">
          <label>Full name<input name="full_name" value="<?= $e($name === '—' ? '' : $name) ?>" required maxlength="120"></label>
          <label>Mobile (login — not editable)<input value="+<?= $e($r['country_code'] ?: '91') ?> <?= $e($r['phone']) ?>" disabled></label>
          <label>Country<select name="country_code"><option value="91"<?= ($r['country_code'] ?: '91') === '91' ? ' selected' : '' ?>>🇮🇳 +91 India</option><option value="977"<?= ($r['country_code'] ?? '') === '977' ? ' selected' : '' ?>>🇳🇵 +977 Nepal</option></select></label>
          <label>Email<input name="email" type="email" value="<?= $e($r['email']) ?>" maxlength="191"></label>
          <label>Document type<input name="id_type" value="<?= $e($r['u_id_type'] ?: $r['b_id_type']) ?>" maxlength="60" placeholder="Passport / Aadhaar / Citizenship"></label>
          <label>Document number<input name="id_number" value="<?= $e($r['u_id_number'] ?: $r['b_id_number']) ?>" maxlength="60"></label>
        </div>
        <div class="dt-acts" style="padding:4px 2px"><button class="btn" type="submit">💾 Save</button><button class="btn ghost" type="button" data-dt-toggle="<?= $rid ?>e">Cancel</button></div>
      </form>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 2px;font-size:13px">
  <span class="muted">Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $PER, $total)) ?> of <?= number_format($total) ?></span>
  <span style="margin-left:auto;display:inline-flex;gap:6px;align-items:center">
    <?php if ($page > 1): ?><a class="btn ghost" href="<?= $e($pageUrl(1)) ?>">« First</a><a class="btn ghost" href="<?= $e($pageUrl($page - 1)) ?>">‹ Prev</a><?php endif; ?>
    <span class="mono">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn ghost" href="<?= $e($pageUrl($page + 1)) ?>">Next ›</a><a class="btn ghost" href="<?= $e($pageUrl($pages)) ?>">Last »</a><?php endif; ?>
  </span>
</div>
<?php endif; ?>
<p class="muted" style="font-size:12px;margin-top:8px">Every booking collects the traveller's name and mobile (sign-in) and the boarding details; document type / number are optional at booking and can be completed here. Mobile numbers are never edited — they are the login identity and the key on every ticket.</p>
<?php admin_footer(); ?>
