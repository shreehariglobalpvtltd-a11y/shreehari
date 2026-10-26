<?php
/**
 * admin/customer-view.php — Customer 360 (rebuilt 3 Oct 2026 from the
 * 5 Sep booking-history page).
 *
 * One customer = one MOBILE NUMBER plus the numbers the office linked to it
 * (CustomerBook::linkedPhones). Everything on this page — totals, journeys,
 * passengers, agents, payments, cancellations, refunds — is read live from
 * the bookings through CustomerBook, the same builder the Customer Ledger
 * uses, so the two can never disagree.
 *
 * Edits change the CUSTOMER record only (user_profiles, and the sign-in
 * name on users). A ticket already issued keeps the name and the number it
 * was issued with: changing the customer's number links the old number to
 * the new one instead of rewriting bookings, and every change is written to
 * audit_logs (shown at the bottom).
 *
 * Gated on customers.view; counter agents are refused (they see their own
 * passengers on agent-passengers.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/customerbook.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('Customer', 'customers');
    echo '<div class="flash bad">Customer profiles are an office page — your own passengers are under My Passengers.</div>';
    admin_footer();
    exit;
}
$base = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)

$asked = normalisePhone((string) ($_GET['phone'] ?? ''));
if ($asked === '' || strlen($asked) < 6) {
    Response::redirect('admin/customers.php');
}
$phone     = CustomerBook::canonical($asked);
$canManage = Auth::isSuperadmin() || Auth::can('bookings.edit');
$flash     = null;

/* ---------- actions (customer record only — never a booking row) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a manager or super-admin can change customer records.'];
    } elseif ($act === 'save_profile') {
        $err = CustomerBook::saveProfile($phone, $_POST);
        $flash = $err === '' ? ['ok', 'Customer details saved. Tickets already issued keep the name and number printed on them.'] : ['bad', $err];
    } elseif ($act === 'change_phone') {
        $new = normalisePhone((string) ($_POST['new_phone'] ?? ''));
        $err = CustomerBook::changePhone($phone, $new);
        if ($err === '') {
            $_SESSION['flash_customer'] = 'Number changed to +' . $new . '. The old number +' . $phone . ' stays linked, so its bookings remain in this history.';
            Response::redirect('admin/customer-view.php?phone=' . urlencode($new));
        }
        $flash = ['bad', $err];
    } elseif ($act === 'link_phone') {
        $other = normalisePhone((string) ($_POST['other_phone'] ?? ''));
        $err = CustomerBook::merge($other, $phone);
        $flash = $err === '' ? ['ok', '+' . $other . ' is now linked to this customer — its bookings show in this history.'] : ['bad', $err];
    } elseif ($act === 'unlink_phone') {
        $err = CustomerBook::unmerge((string) ($_POST['linked'] ?? ''));
        $flash = $err === '' ? ['ok', 'Number separated — it is its own customer again.'] : ['bad', $err];
    }
}
if (!empty($_SESSION['flash_customer'])) {
    $flash = ['ok', (string) $_SESSION['flash_customer']];
    unset($_SESSION['flash_customer']);
}

$c       = CustomerBook::profile360($phone);
$rows    = $c['rows'];
$user    = $c['user'];
$profile = $c['profile'] ?? [];
$sum     = $c['sum'];
$name    = $c['name'];
$cc      = (string) (($profile['country_code'] ?? '') ?: ($user['country_code'] ?? '91'));
$e       = static fn($v): string => Security::e((string) ($v ?? ''));
$csrf    = Security::e(Security::csrfToken());
$k       = CSRF_TOKEN_NAME;
$canResend = Auth::can('payments.verify');

/* Payments + change history across every booking of this customer. */
$payRows = []; $auditRows = [];
if ($rows !== []) {
    $ph = []; $pp = [];
    foreach ($rows as $i => $r) { $ph[] = ':b' . $i; $pp['b' . $i] = (int) $r['id']; }
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
}
$ap = []; $arms = [];
if ($user !== null) { $arms[] = "(entity_type = 'user' AND entity_id = :uid)"; $ap['uid'] = (string) $user['id']; }
foreach ($c['phones'] as $i => $num) { $arms[] = "(entity_type = 'customer' AND entity_id = :cp$i)"; $ap['cp' . $i] = $num; }
foreach (array_slice($rows, 0, 300) as $i => $r) { $arms[] = "(entity_type = 'booking' AND entity_id = :pn$i)"; $ap['pn' . $i] = (string) $r['pnr']; }
if ($arms !== []) {
    $auditRows = Database::fetchAll(
        'SELECT action, actor_name, actor_type, entity_type, entity_id, detail, old_value, new_value, created_at
           FROM audit_logs WHERE ' . implode(' OR ', $arms) . ' ORDER BY id DESC LIMIT 60',
        $ap
    );
}

require_once INCLUDE_PATH . '/passengerdocs.php';
$docCount = 0;
foreach ($c['phones'] as $num) { $docCount += PassengerDocs::countForPhone($num, Auth::bookingScopeAdminId()); }

$cancelRows = array_values(array_filter($rows, static fn(array $r): bool => (string) $r['status'] === 'cancelled' || (string) $r['refund_status'] !== 'none'));
$lastRoute  = null;
foreach ($rows as $r) { if (!empty($r['route_id'])) { $lastRoute = $r; break; } }
$newBookingUrl = $base . '/admin/new-booking.php?' . http_build_query(array_filter([
    'route'  => $lastRoute['route_id'] ?? null,
    'cphone' => CustomerBook::isRealPhone($phone) ? $phone : null,
    'cname'  => $name !== '' ? $name : null,
]));
$waNum = (string) (($profile['whatsapp_phone'] ?? '') ?: $phone);
$waNum = strlen($waNum) === 10 ? $cc . $waNum : $waNum;

admin_header('Customer · ' . ($name !== '' ? $name : '+' . $cc . ' ' . $phone), 'customers');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
?>
<style>
.c360-head{display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;padding:14px 18px}
.c360-head h2{margin:0;font-size:22px;border:0;padding:0}
.c360-id{display:inline-block;font:700 12px/1 var(--f-mono,monospace);background:var(--head);border:1px solid var(--line);border-radius:6px;padding:4px 7px;margin-left:6px;vertical-align:middle}
.c360-acts{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
.c360-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px 18px;padding:14px 18px}
.c360-grid .kv label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);margin-bottom:2px}
.c360-grid .kv b{font-size:14.5px;font-weight:600;color:var(--ink);word-break:break-word}
.c360-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px 16px;padding:14px 18px}
.c360-form label{display:flex;flex-direction:column;gap:4px;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--mut)}
.c360-form input,.c360-form textarea{font-size:14px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0;font-family:inherit}
.c360-chips{display:flex;gap:6px;flex-wrap:wrap;padding:0 18px 14px}
.c360-chips span{background:var(--head);border:1px solid var(--line);border-radius:999px;padding:5px 11px;font-size:12.5px}
.c360-sub{font-size:11.5px;color:var(--mut)}
.c360-acts form{display:inline}
details.c360-more>summary{cursor:pointer;padding:12px 18px;font-weight:600;list-style:none}
details.c360-more>summary::-webkit-details-marker{display:none}
details.c360-more>summary::before{content:'▸ ';color:var(--mut)}
details.c360-more[open]>summary::before{content:'▾ '}
@media(pointer:coarse){.c360-form input,.c360-form textarea{min-height:44px}}
</style>

<p style="margin:-6px 0 14px"><a href="<?= $base ?>/admin/customers.php">← All customers</a> · <a href="<?= $base ?>/admin/customer-ledger.php">Customer Ledger</a></p>

<?php if ($asked !== $phone): ?>
  <div class="flash" style="background:var(--head)">You opened +<?= $e($asked) ?> — it is linked to this customer's main number +<?= $e($phone) ?>, so the full history is shown here.</div>
<?php endif; ?>

<?php if ($user === null && $rows === [] && empty($profile)): ?>
  <div class="panel"><h2>No customer found</h2>
    <div style="padding:16px 20px" class="muted">Nothing is recorded for <span class="mono">+<?= $e($cc) ?> <?= $e($phone) ?></span> — no account and no booking.</div>
  </div>
<?php else: ?>

<?php if ($c['non_customer'] !== ''): ?>
  <div class="flash bad">⚠️ +<?= $e($phone) ?> is not a customer's own number — <b><?= $e($c['non_customer']) ?></b>. The bookings below belong to many different passengers; open each ticket to reach the traveller.</div>
<?php endif; ?>

<div class="panel">
  <div class="c360-head">
    <div>
      <h2>👤 <?= $e($name !== '' ? $name : 'Customer') ?><?php if ($c['customer_id'] !== ''): ?><span class="c360-id"><?= $e($c['customer_id']) ?></span><?php endif; ?></h2>
      <div class="mono" style="margin-top:4px">+<?= $e($cc) ?> <?= $e($phone) ?>
        <?php if (count($c['phones']) > 1): ?><span class="c360-sub"> · also <?= $e(implode(', ', array_slice($c['phones'], 1))) ?></span><?php endif; ?></div>
      <?php if (!empty($user['is_blocked'])): ?><div style="color:#8a1f1f;margin-top:4px">⛔ Blocked<?= !empty($user['blocked_reason']) ? ' — ' . $e($user['blocked_reason']) : '' ?></div><?php endif; ?>
    </div>
    <div class="c360-acts">
      <a class="btn" href="<?= $e($newBookingUrl) ?>" title="Opens the counter seat map<?= $lastRoute ? ' on their last route' : '' ?> with this customer's name and mobile filled in">➕ New booking</a>
      <a class="btn ghost" href="https://wa.me/<?= $e($waNum) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
      <a class="btn ghost" href="<?= $base ?>/admin/customer-ledger.php?phone=<?= urlencode($phone) ?>&amp;date_mode=booking&amp;export=xlsx">⬇ History (Excel)</a>
      <a class="btn ghost" href="<?= $base ?>/admin/customer-ledger.php?phone=<?= urlencode($phone) ?>&amp;date_mode=booking&amp;export=csv">⬇ CSV</a>
      <a class="btn ghost" href="<?= $base ?>/admin/export.php?format=pdf&amp;customer=<?= urlencode($phone) ?>" target="_blank" rel="noopener">📄 PDF</a>
      <a class="btn ghost" href="<?= $base ?>/admin/customer-ledger.php?phone=<?= urlencode($phone) ?>&amp;date_mode=booking">📒 Ledger</a>
      <a class="btn ghost" href="<?= $base ?>/admin/passengers.php?phone=<?= urlencode($phone) ?>" title="Every traveller booked from this number — name, age, ID, photo / document">🧍 Documents<?= $docCount > 0 ? ' · 📎 ' . (int) $docCount : '' ?></a>
      <?php if ($canManage && $user !== null): ?><a class="btn ghost" href="<?= $base ?>/admin/customers.php?q=<?= urlencode($phone) ?>">⛔ Block / account</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="dash-hero">
  <div class="hcard hc-blue"><span class="hicon">🎫</span><div class="hk">Bookings</div><div class="hv"><?= (int) $sum['bookings'] ?></div><div class="hsub"><?= (int) $sum['sold'] ?> travelled / confirmed · <?= (int) $sum['cancelled'] ?> cancelled</div></div>
  <div class="hcard hc-teal"><span class="hicon">🧍</span><div class="hk">Passengers carried</div><div class="hv"><?= (int) $sum['pax'] ?></div><div class="hsub"><?= (int) $sum['seats'] ?> seat<?= $sum['seats'] === 1 ? '' : 's' ?> · <?= count($c['passengers']) ?> different traveller<?= count($c['passengers']) === 1 ? '' : 's' ?></div></div>
  <div class="hcard hc-green"><span class="hicon">💰</span><div class="hk">Total travel amount</div><div class="hv"><?= $e(inr($sum['spend'])) ?></div><div class="hsub">paid <?= $e(inr($sum['paid'])) ?><?= $sum['refunds'] > 0 ? ' · refunded ' . $e(inr($sum['refunds'])) : '' ?></div></div>
  <div class="hcard hc-navy"><span class="hicon">📅</span><div class="hk">Last journey</div><div class="hv" style="font-size:20px"><?= $c['last_journey'] ? $e(formatDate((string) $c['last_journey'])) : '—' ?></div><div class="hsub"><?= $c['next_journey'] ? 'next trip ' . $e(formatDate((string) $c['next_journey'])) : 'no upcoming trip' ?></div></div>
  <div class="hcard hc-orange"><span class="hicon">🛣️</span><div class="hk">Frequent route</div><div class="hv" style="font-size:16px;line-height:1.25"><?= $c['routes'] !== [] ? $e(array_key_first($c['routes'])) : '—' ?></div><div class="hsub"><?= $c['routes'] !== [] ? (int) reset($c['routes']) . ' trip(s)' : 'no confirmed trip yet' ?></div></div>
</div>

<div class="panel">
  <h2>📇 Customer record</h2>
  <div class="c360-grid">
    <div class="kv"><label>Customer ID</label><b class="mono"><?= $e($c['customer_id'] ?: '—') ?></b></div>
    <div class="kv"><label>Name</label><b><?= $e($name ?: '—') ?><?= !empty($profile['name_locked']) ? ' 🔒' : '' ?></b></div>
    <div class="kv"><label>Primary mobile</label><b class="mono">+<?= $e($cc) ?> <?= $e($phone) ?></b></div>
    <div class="kv"><label>Alternate mobile</label><b class="mono"><?php if (!empty($profile['alt_phone'])): ?><a href="?phone=<?= urlencode((string) $profile['alt_phone']) ?>"><?= $e($profile['alt_phone']) ?></a><?php else: ?>—<?php endif; ?></b></div>
    <div class="kv"><label>WhatsApp number</label><b class="mono"><?= $e(($profile['whatsapp_phone'] ?? '') ?: 'same as mobile') ?></b></div>
    <div class="kv"><label>Email</label><b><?= $e(($profile['email'] ?? '') ?: ($user['email'] ?? '') ?: '—') ?></b></div>
    <div class="kv"><label>Total bookings · passengers</label><b><?= (int) $sum['bookings'] ?> · <?= (int) $sum['pax'] ?></b></div>
    <div class="kv"><label>Total travel amount</label><b><?= $e(inr($sum['spend'])) ?></b></div>
    <div class="kv"><label>First booking</label><b><?= $c['first'] ? $e(formatDate(substr((string) $c['first'], 0, 10), 'd-m-Y')) : '—' ?></b></div>
    <div class="kv"><label>Last booking</label><b><?= $c['last'] ? $e(formatDate(substr((string) $c['last'], 0, 10), 'd-m-Y')) : '—' ?></b></div>
    <div class="kv"><label>Last journey</label><b><?= $c['last_journey'] ? $e(formatDate((string) $c['last_journey'], 'd-m-Y')) : '—' ?></b></div>
    <div class="kv"><label>Preferred pickup</label><b><?= $e($c['pickup'] ?: '—') ?></b></div>
    <div class="kv"><label>Preferred drop</label><b><?= $e($c['drop'] ?: '—') ?></b></div>
    <div class="kv"><label>Booking source</label><b><?= $c['sources'] !== [] ? $e(implode(' · ', array_map(static fn($s, $n) => $s . ' ' . $n, array_keys($c['sources']), $c['sources']))) : '—' ?></b></div>
    <div class="kv"><label>App account</label><b><?= $user !== null ? 'Yes · ' . (int) ($user['loyalty_points'] ?? 0) . ' points' . (!empty($user['last_login_at']) ? ' · last sign-in ' . $e(timeAgo((string) $user['last_login_at'])) : '') : 'No (booking only)' ?></b></div>
    <div class="kv" style="grid-column:1/-1"><label>Notes</label><b style="white-space:pre-wrap;font-weight:500"><?= $e(($profile['notes'] ?? '') ?: '—') ?></b></div>
  </div>
  <?php if ($c['routes'] !== []): ?>
    <div class="c360-chips"><span class="muted" style="background:none;border:0;padding:5px 0">Recent routes:</span><?php foreach (array_slice($c['routes'], 0, 6, true) as $rk => $n): ?><span><?= $e($rk) ?> · <?= (int) $n ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($canManage && $c['non_customer'] === ''): ?>
  <details class="c360-more">
    <summary>✏️ Edit name / contact / notes</summary>
    <form method="post" class="c360-form">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_profile">
      <label>Name (customer record)<input name="full_name" value="<?= $e($profile['full_name'] ?? $name) ?>" maxlength="120"></label>
      <label>Alternate mobile<input name="alt_phone" inputmode="numeric" value="<?= $e($profile['alt_phone'] ?? '') ?>" maxlength="15"></label>
      <label>WhatsApp number (if different)<input name="whatsapp_phone" inputmode="numeric" value="<?= $e($profile['whatsapp_phone'] ?? '') ?>" maxlength="15"></label>
      <label>Email<input name="email" type="email" value="<?= $e($profile['email'] ?? '') ?>" maxlength="191"></label>
      <label>Preferred drop (blank = learnt)<input name="preferred_drop" value="<?= $e($profile['preferred_drop'] ?? '') ?>" maxlength="120"></label>
      <label style="grid-column:1/-1">Notes<textarea name="notes" rows="3" maxlength="2000"><?= $e($profile['notes'] ?? '') ?></textarea></label>
      <div style="grid-column:1/-1;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn" type="submit">💾 Save</button>
        <span class="muted" style="font-size:12px">Changes the customer record only. Tickets already issued keep the name printed on them; a name you set here stays even if the desk types it differently next time. Every change is logged below.</span>
      </div>
    </form>
  </details>
  <details class="c360-more">
    <summary>📱 Change mobile number · link another number</summary>
    <div class="c360-form">
      <form method="post" onsubmit="return confirm('Make the new number this customer\'s main number? The old number stays linked and its tickets are not changed.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="change_phone">
        <label>New main mobile<input name="new_phone" inputmode="numeric" maxlength="15" required placeholder="10 digits"></label>
        <button class="btn" type="submit" style="margin-top:8px">Change number</button>
        <div class="muted" style="font-size:12px;margin-top:6px">The customer has a new SIM. Old tickets keep the old number; the history stays together.</div>
      </form>
      <form method="post" onsubmit="return confirm('Link this number to the same customer? Its bookings will show in this history. You can separate it again later.')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="link_phone">
        <label>Same person's other number<input name="other_phone" inputmode="numeric" maxlength="15" required placeholder="10 digits"></label>
        <button class="btn ghost" type="submit" style="margin-top:8px">🔗 Link number</button>
        <div class="muted" style="font-size:12px;margin-top:6px">For a mistyped number or a second phone. See also <a href="<?= $base ?>/admin/customer-duplicates.php">Duplicate customers</a>.</div>
      </form>
      <?php if (count($c['phones']) > 1): ?>
      <div>
        <div class="muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px">Linked numbers</div>
        <?php foreach (array_slice($c['phones'], 1) as $lp): ?>
          <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:6px" onsubmit="return confirm('Separate +<?= $e($lp) ?> from this customer?')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="unlink_phone"><input type="hidden" name="linked" value="<?= $e($lp) ?>">
            <span class="mono">+<?= $e($lp) ?></span><button class="btn ghost" type="submit" style="padding:4px 9px;font-size:12px">Separate</button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </details>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>🧾 Booking &amp; journey history <span class="muted" style="font-weight:400;font-size:13px">· <?= count($rows) ?> booking<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) >= 1000 ? ' (latest 1000 — download for all)' : '' ?></span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>Journey</th><th>Ticket / PNR</th><th>From → To</th><th>Seats</th><th>Passengers</th><th>Agent / source</th><th>Fare</th><th>Payment</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="10" class="muted" style="padding:22px;text-align:center">No bookings yet.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td data-label="Journey"><b><?= $r['travel_date'] ? $e(formatDate((string) $r['travel_date'], 'd-m-Y')) : '—' ?></b><div class="c360-sub">booked <?= $e(formatDate(substr((string) $r['created_at'], 0, 10), 'd-m-Y')) ?></div></td>
        <td class="mono" data-label="PNR"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a><?php if ($r['contact_phone'] !== $phone): ?><div class="c360-sub">on +<?= $e($r['contact_phone']) ?></div><?php endif; ?></td>
        <td data-label="Route"><?= $e(($r['from_label'] ?: '—') . ' → ' . ($r['to_label'] ?: '—')) ?><?php if (!empty($r['bus_number'])): ?><div class="c360-sub">🚌 <?= $e($r['bus_number']) ?></div><?php endif; ?></td>
        <td class="mono" data-label="Seats"><?= $e($r['seat_labels'] ?: '—') ?></td>
        <td data-label="Passengers"><?= (int) $r['pax'] ?><div class="c360-sub"><?= $e($r['lead_name'] ?? '') ?></div></td>
        <td data-label="Agent / source"><?= $e($r['seller_label']) ?></td>
        <td data-label="Fare"><?= $e(inr((float) $r['total_amount'])) ?><?php if ((float) $r['refund_amount'] > 0): ?><div class="c360-sub">refund <?= $e(inr((float) $r['refund_amount'])) ?></div><?php endif; ?></td>
        <td data-label="Payment"><?= $e($r['pay_label']) ?></td>
        <td data-label="Status"><?= admin_pill((string) $r['status']) ?><?php if ((string) $r['refund_status'] !== 'none'): ?><div class="c360-sub">refund <?= $e($r['refund_status']) ?></div><?php endif; ?></td>
        <td data-label="Actions"><span class="dt-acts">
          <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>">View</a>
          <?php if (in_array((string) $r['status'], CustomerBook::SOLD, true)): ?><a class="btn ghost" href="<?= $base ?>/download-ticket.php?pnr=<?= urlencode((string) $r['pnr']) ?>" target="_blank" rel="noopener">🎫 Ticket</a><?php endif; ?>
          <?php if ($canResend && (string) $r['status'] === 'confirmed'): ?>
            <form method="post" action="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>" style="display:inline" onsubmit="return confirm('Re-send this ticket on WhatsApp to +<?= $e($r['contact_phone']) ?>?')">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><button class="btn ghost" name="action" value="resend_wa">📲 Resend</button>
            </form>
          <?php endif; ?>
        </span></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="panel">
  <h2>🧍 Passenger history <span class="muted" style="font-weight:400;font-size:13px">· everyone who travelled on this customer's bookings</span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>Passenger</th><th>Trips</th><th>Cancelled</th><th>Last travel</th><th>Age / gender</th><th>Spellings used</th></tr></thead>
    <tbody>
    <?php if ($c['passengers'] === []): ?>
      <tr><td colspan="6" class="muted" style="padding:18px;text-align:center">No passengers recorded.</td></tr>
    <?php else: foreach (array_slice($c['passengers'], 0, 200) as $p): ?>
      <tr>
        <td data-label="Passenger"><b><?= $e($p['name']) ?></b></td>
        <td data-label="Trips"><?= (int) $p['trips'] ?></td>
        <td data-label="Cancelled"><?= (int) $p['cancelled'] ?></td>
        <td data-label="Last travel"><?= $p['last_travel'] ? $e(formatDate((string) $p['last_travel'], 'd-m-Y')) : '—' ?> <span class="c360-sub mono"><?= $e($p['last_pnr']) ?></span></td>
        <td data-label="Age / gender"><?= $e(trim(($p['age'] ? $p['age'] . ' · ' : '') . ($p['gender'] ?? ''), ' ·')) ?: '—' ?></td>
        <td data-label="Spellings" style="white-space:normal"><?= count($p['spellings']) > 1 ? $e(implode(' · ', $p['spellings'])) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($c['sellers'] !== []): ?>
<div class="panel">
  <h2>🧑‍💼 Agent-linked bookings <span class="muted" style="font-weight:400;font-size:13px">· who sold this customer's tickets (confirmed)</span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>Agent / source</th><th>Bookings</th><th>Passengers</th><th>Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($c['sellers'] as $s): ?>
      <tr>
        <td data-label="Agent / source"><b><?= $e($s['label']) ?></b></td>
        <td data-label="Bookings"><?= (int) $s['bookings'] ?></td>
        <td data-label="Passengers"><?= (int) $s['pax'] ?></td>
        <td data-label="Amount"><?= $e(inr((float) $s['amount'])) ?></td>
        <td><?php if ((int) $s['agent_id'] > 0): ?><a class="btn ghost" href="<?= $base ?>/admin/customer-ledger.php?phone=<?= urlencode($phone) ?>&amp;agent=<?= (int) $s['agent_id'] ?>&amp;date_mode=booking">Ledger</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($cancelRows !== []): ?>
<div class="panel">
  <h2>↩️ Cancellations &amp; refunds <span class="muted" style="font-weight:400;font-size:13px">· <?= count($cancelRows) ?></span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>PNR</th><th>Journey</th><th>Fare</th><th>Status</th><th>Refund</th><th>Cancelled</th><th>Reason</th></tr></thead>
    <tbody>
    <?php foreach ($cancelRows as $r): ?>
      <tr>
        <td class="mono" data-label="PNR"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a></td>
        <td data-label="Journey"><?= $r['travel_date'] ? $e(formatDate((string) $r['travel_date'], 'd-m-Y')) : '—' ?></td>
        <td data-label="Fare"><?= $e(inr((float) $r['total_amount'])) ?></td>
        <td data-label="Status"><?= admin_pill((string) $r['status']) ?></td>
        <td data-label="Refund"><?= (string) $r['refund_status'] !== 'none' ? $e(inr((float) $r['refund_amount']) . ' · ' . $r['refund_status']) : '—' ?></td>
        <td data-label="Cancelled"><?= !empty($r['cancelled_at']) ? $e(formatDate(substr((string) $r['cancelled_at'], 0, 10), 'd-m-Y')) : '—' ?></td>
        <td data-label="Reason" style="white-space:normal"><?= $e($r['cancel_reason'] ?? '') ?: '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>💳 Payments <span class="muted" style="font-weight:400;font-size:13px">· <?= count($payRows) ?> record<?= count($payRows) === 1 ? '' : 's' ?></span></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><th>When</th><th>PNR</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th>Verified</th></tr></thead>
    <tbody>
    <?php if ($payRows === []): ?>
      <tr><td colspan="7" class="muted" style="padding:18px;text-align:center">No payment records.</td></tr>
    <?php else: foreach ($payRows as $p): ?>
      <tr>
        <td data-label="When" class="muted"><?= $e(formatDate((string) $p['created_at'], 'j M Y g:i A')) ?></td>
        <td data-label="PNR" class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>"><?= $e($p['pnr']) ?></a></td>
        <td data-label="Method"><?= $e(strtoupper((string) $p['method'])) ?></td>
        <td data-label="Reference" class="mono muted"><?= $e($p['utr_number'] ?: $p['payment_ref']) ?></td>
        <td data-label="Amount"><?= $e(($p['currency'] === 'NPR' ? 'रू ' : '') . inr((float) $p['amount'])) ?></td>
        <td data-label="Status"><?= admin_pill((string) $p['status']) ?></td>
        <td data-label="Verified" class="muted"><?= !empty($p['verified_at']) ? $e(formatDate((string) $p['verified_at'], 'j M Y') . (!empty($p['verifier']) ? ' · ' . $p['verifier'] : '')) : '—' ?></td>
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
        <td data-label="When" class="muted"><?= $e(formatDate((string) $a['created_at'], 'j M Y g:i A')) ?></td>
        <td data-label="Who"><?= $e($a['actor_name'] ?: strtoupper((string) $a['actor_type'])) ?></td>
        <td data-label="What" class="mono"><?= $e($a['action']) ?></td>
        <td data-label="On" class="mono"><?= $e($a['entity_type'] . ' ' . $a['entity_id']) ?></td>
        <td data-label="Detail" style="white-space:normal;max-width:420px"><?= $e($a['detail'] ?? '') ?>
          <?php if (!empty($a['old_value']) || !empty($a['new_value'])): ?>
            <div class="muted" style="font-size:11px;margin-top:3px"><?= !empty($a['old_value']) ? 'old: ' . $e(mb_substr((string) $a['old_value'], 0, 140)) : '' ?><?= !empty($a['new_value']) ? ' → new: ' . $e(mb_substr((string) $a['new_value'], 0, 140)) : '' ?></div>
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
