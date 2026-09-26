<?php
/**
 * admin/counters.php — every ticket window, and what each one took.
 *
 * Owner ask (26 Sep 2026): "sabai ko lagi alag alag add garna milos jati
 * pani — sabko hisab kitab admin le herna milos … har ek thau ma change
 * huna paryo." So this page does two jobs on one screen:
 *
 *   1. THE DESKS. Add as many as you like, each with its town, its country,
 *      the money it collects and — for a Nepal desk — its own exchange rate
 *      when the border rate differs from the office peg. A desk is never
 *      deleted, only closed: tickets, shifts and last year's books all point
 *      at the code, and a deleted place would read as "nowhere".
 *
 *   2. THE BOOK, PER PLACE. For any date range: tickets, seats, the rupees
 *      the company accounts in, and — at a Nepal window — the NPR actually
 *      handed across the counter. The company total is the same total it
 *      always was; this only splits it by town.
 *
 * Sales made before database/upgrade-2026-09-counter-desks.sql are not
 * stamped, so they fall back to the SELLER'S CURRENT desk and are marked
 * "from profile". That fallback moves if the clerk moves — which is exactly
 * why new sales freeze the code on the booking row.
 *
 * Who may open it: the office (dashboard.view). A counter clerk must not see
 * another town's money, so this page is deliberately not on their menu.
 * Editing a desk is superadmin only.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base    = '';
$flash   = null;
$canEdit = Auth::isSuperadmin();
$ready   = CounterDesk::hasTable();

/* Which halves of the migration are in place. The page must render, and be
   honest about what it cannot show, on a database where the SQL has not
   been applied yet. */
$hasStamp = false;
$hasFx    = false;
$hasLocal = false;
try {
    $hasStamp = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'counter_code'") !== null;
    $hasFx    = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'fx_total'") !== null;
    $hasLocal = Database::fetch("SHOW COLUMNS FROM payments LIKE 'local_amount'") !== null;
} catch (Throwable $e) {
    // leave them false — the report degrades to profile-only attribution
}

/* ============================================================
   POST — add / edit a desk, close or reopen one.
   ============================================================ */
if ($canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$ready) {
        $flash = ['bad', 'Run database/upgrade-2026-09-counter-desks.sql first — the desk table is not there yet.'];
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'save') {
                $code = CounterDesk::save([
                    'code'       => $_POST['code'] ?? '',
                    'name'       => $_POST['name'] ?? '',
                    'country'    => $_POST['country'] ?? 'IN',
                    'currency'   => $_POST['currency'] ?? '',
                    'fx_rate'    => $_POST['fx_rate'] ?? '',
                    'allowed_methods' => $_POST['methods'] ?? null,
                    'phone'      => $_POST['phone'] ?? '',
                    'address'    => $_POST['address'] ?? '',
                    'note'       => $_POST['note'] ?? '',
                    'sort_order' => $_POST['sort_order'] ?? 0,
                    'is_active'  => isset($_POST['is_active']) ? 1 : 0,
                ]);
                Logger::audit('counter.save', 'counter_location', $code, null,
                    ['name' => $_POST['name'] ?? '', 'currency' => $_POST['currency'] ?? ''],
                    'desk saved by admin #' . (int) $admin['id']);
                $flash = ['ok', 'Desk ' . $code . ' saved.'];
            } elseif ($action === 'close') {
                $code = mb_strtoupper(Security::clean((string) ($_POST['code'] ?? ''), 16));
                CounterDesk::deactivate($code);
                Logger::audit('counter.close', 'counter_location', $code, null, [], 'desk closed by admin #' . (int) $admin['id']);
                $flash = ['ok', 'Desk ' . $code . ' closed. Its old tickets keep their place.'];
            } elseif ($action === 'isolation') {
                $on = !empty($_POST['on']);
                Settings::set('counter_desk_isolation', $on ? '1' : '0', 'bool', 'agent');
                Logger::audit('counter.isolation', 'setting', 'counter_desk_isolation', null, ['on' => $on],
                    'desk isolation ' . ($on ? 'ON' : 'OFF') . ' by admin #' . (int) $admin['id']);
                $flash = ['ok', $on
                    ? 'Desk isolation is ON — a counter login now sees only its own desk\'s register, payments and passengers.'
                    : 'Desk isolation is OFF — every counter login sees the whole company register again.'];
            } elseif ($action === 'reopen') {
                $code = mb_strtoupper(Security::clean((string) ($_POST['code'] ?? ''), 16));
                $row  = CounterDesk::get($code);
                if ($row !== null) {
                    CounterDesk::save($row + ['is_active' => 1]);
                    Logger::audit('counter.reopen', 'counter_location', $code, null, [], 'desk reopened by admin #' . (int) $admin['id']);
                    $flash = ['ok', 'Desk ' . $code . ' is open again.'];
                }
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ============================================================
   The window for the book. Default: today.
   ============================================================ */
$from = Security::clean($_GET['from'] ?? '', 10);
$to   = Security::clean($_GET['to']   ?? '', 10);
if (!Security::isValidDate($from)) { $from = todayISO(); }
if (!Security::isValidDate($to))   { $to   = todayISO(); }
if ($from > $to) { [$from, $to] = [$to, $from]; }
$t1 = addDaysISO($to, 1);

/* The desk a SALE belongs to: the code frozen on the sale, else the seller's
   desk today (marked in the UI as a fallback), else blank = online. */
$codeExpr = $hasStamp
    ? "COALESCE(NULLIF(b.counter_code,''), ap.counter_code, '')"
    : "COALESCE(ap.counter_code, '')";

/* The desk a COLLECTION belongs to is a different question, and the one a
   cashier actually asks: whose drawer did this money go into? A ticket sold
   at Nepalgunj and paid on boarding, or settled later at the Surat window,
   is Surat's cash — crediting it to Nepalgunj would leave both drawers
   wrong. So the money follows the person who verified it, and only falls
   back to the selling desk when that person has no desk of their own. */
$takenExpr = "COALESCE(NULLIF(apv.counter_code,''), " . ($hasStamp ? "NULLIF(b.counter_code,''), " : '') . "ap.counter_code, '')";

$sold    = [];   // code => ['tickets','seats','inr','npr','stamped']
$taken   = [];   // code => ['cash'=>inr, ...] verified money
$takenNp = [];   // code => NPR physically taken
$refund  = [];   // code => rupees refunded
$pending = [];   // code => COD still to collect

try {
    $rows = Database::fetchAll(
        "SELECT $codeExpr AS code,
                COUNT(*) AS tickets,
                COALESCE(SUM((SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id)), 0) AS seats,
                COALESCE(SUM(b.total_amount), 0) AS inr" .
                ($hasFx ? ", COALESCE(SUM(b.fx_total), 0) AS npr" : ", 0 AS npr") .
                ($hasStamp ? ", SUM(CASE WHEN COALESCE(b.counter_code,'') <> '' THEN 1 ELSE 0 END) AS stamped" : ", 0 AS stamped") . "
           FROM bookings b
           LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
          WHERE b.status IN ('confirmed','completed')
            AND b.confirmed_at >= :f AND b.confirmed_at < :t1
          GROUP BY code",
        ['f' => $from, 't1' => $t1]
    );
    foreach ($rows as $r) {
        $sold[(string) $r['code']] = [
            'tickets' => (int) $r['tickets'],
            'seats'   => (int) $r['seats'],
            'inr'     => (float) $r['inr'],
            'npr'     => (float) $r['npr'],
            'stamped' => (int) $r['stamped'],
        ];
    }

    $rows = Database::fetchAll(
        "SELECT $takenExpr AS code, p.method,
                COALESCE(SUM(p.amount), 0) AS inr" .
                ($hasLocal ? ", COALESCE(SUM(CASE WHEN p.local_currency = 'NPR' THEN p.local_amount ELSE 0 END), 0) AS npr" : ", 0 AS npr") . "
           FROM payments p
           JOIN bookings b ON b.id = p.booking_id
           LEFT JOIN admin_profiles ap  ON ap.admin_id  = b.sold_by_admin_id
           LEFT JOIN admin_profiles apv ON apv.admin_id = p.verified_by
          WHERE p.status = 'verified'
            AND p.verified_at >= :f AND p.verified_at < :t1
          GROUP BY code, p.method",
        ['f' => $from, 't1' => $t1]
    );
    foreach ($rows as $r) {
        $c = (string) $r['code'];
        $m = strtolower((string) $r['method']) === 'cod' ? 'cash' : strtolower((string) $r['method']);
        $taken[$c][$m] = ($taken[$c][$m] ?? 0.0) + (float) $r['inr'];
        $takenNp[$c]   = ($takenNp[$c] ?? 0.0) + (float) $r['npr'];
    }

    $rows = Database::fetchAll(
        "SELECT $codeExpr AS code, COALESCE(SUM(b.refund_amount), 0) AS amt
           FROM bookings b
           LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
          WHERE b.refund_status = 'processed'
            AND b.cancelled_at >= :f AND b.cancelled_at < :t1
          GROUP BY code",
        ['f' => $from, 't1' => $t1]
    );
    foreach ($rows as $r) { $refund[(string) $r['code']] = (float) $r['amt']; }

    $rows = Database::fetchAll(
        "SELECT $codeExpr AS code, COALESCE(SUM(b.total_amount), 0) AS amt
           FROM bookings b
           LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
          WHERE b.status IN ('confirmed','completed')
            AND b.confirmed_at >= :f AND b.confirmed_at < :t1
            AND (SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) = 'cod_pending'
          GROUP BY code",
        ['f' => $from, 't1' => $t1]
    );
    foreach ($rows as $r) { $pending[(string) $r['code']] = (float) $r['amt']; }
} catch (Throwable $e) {
    $flash = $flash ?? ['bad', 'The book could not be read: ' . $e->getMessage()];
}

/* Staff sitting at each desk today. */
$staffAt = [];
try {
    foreach (Database::fetchAll(
        "SELECT COALESCE(ap.counter_code,'') code, COUNT(*) n
           FROM admin_profiles ap JOIN admins a ON a.id = ap.admin_id
          WHERE COALESCE(ap.counter_code,'') <> '' AND a.is_active = 1
          GROUP BY code") as $r) {
        $staffAt[(string) $r['code']] = (int) $r['n'];
    }
} catch (Throwable $e) { /* not fatal */ }

$desks   = CounterDesk::all();
$editing = null;
$editId  = mb_strtoupper(Security::clean($_GET['edit'] ?? '', 16));
if ($editId !== '') { $editing = CounterDesk::get($editId); }

/* Every code that appears anywhere in the window, desks first. */
$allCodes = array_keys($desks);
foreach ([$sold, $taken, $refund, $pending] as $set) {
    foreach (array_keys($set) as $c) {
        if (!in_array((string) $c, $allCodes, true)) { $allCodes[] = (string) $c; }
    }
}

$totTickets = 0; $totSeats = 0; $totInr = 0.0; $totTaken = 0.0; $totRefund = 0.0; $totPending = 0.0;
foreach ($allCodes as $c) {
    $totTickets += $sold[$c]['tickets'] ?? 0;
    $totSeats   += $sold[$c]['seats'] ?? 0;
    $totInr     += $sold[$c]['inr'] ?? 0.0;
    $totTaken   += array_sum($taken[$c] ?? []);
    $totRefund  += $refund[$c] ?? 0.0;
    $totPending += $pending[$c] ?? 0.0;
}

admin_header('Counters & collection', 'counters');
$e = static fn($v): string => Security::e((string) $v);
?>
<style>
.ctr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.ctr-form{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 20px;margin-bottom:20px}
.ctr-form h3{margin:0 0 12px;font-size:15px}
.ctr-form .row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:10px}
.ctr-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.ctr-form input,.ctr-form select,.ctr-form textarea{padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-weight:400;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0}
.ctr-form .chk{flex-direction:row;align-items:center;gap:8px;text-transform:none}
.flagpill{display:inline-flex;align-items:center;gap:6px;font-weight:800}
.cur-npr{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800}
.cur-inr{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800}
:root[data-theme="dark"] .cur-npr{background:#4c1d1d;color:#fca5a5}
:root[data-theme="dark"] .cur-inr{background:#0d2948;color:#93c5fd}
.npr-line{font-size:11.5px;color:#b45309;font-weight:800}
:root[data-theme="dark"] .npr-line{color:#fcd34d}
.ctr-note{font-size:11.5px;color:var(--mut)}
.tot-row td{font-weight:800;border-top:2px solid var(--line)}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash bad">
    The desk table is not on this database yet. Run
    <code>php tests/apply-sql.php database/upgrade-2026-09-counter-desks.sql</code>
    — until then the list below is read from the old Settings box and nothing can be edited here.
  </div>
<?php elseif (!$hasStamp): ?>
  <div class="flash warn">
    Sales are not yet stamped with their desk, so the book below attributes every ticket to the
    seller's <em>current</em> desk. Apply the migration to freeze the place on each new sale.
  </div>
<?php endif; ?>

<div class="ctr-cards">
  <div class="card"><div class="k">Desks open</div><div class="v"><?= count(CounterDesk::all(true)) ?></div></div>
  <div class="card"><div class="k">Nepal desks</div><div class="v"><?= count(array_filter($desks, static fn($d) => $d['country'] === 'NP')) ?></div></div>
  <div class="card"><div class="k">Tickets in window</div><div class="v"><?= (int) $totTickets ?></div></div>
  <div class="card"><div class="k">Collected</div><div class="v"><?= $e(inr($totTaken)) ?></div></div>
  <div class="card"><div class="k">Still to collect</div><div class="v"><?= $e(inr($totPending)) ?></div></div>
</div>

<?php if ($canEdit && $ready): ?>
<div class="ctr-form">
  <h3><?= $editing ? '✏️ Edit desk ' . $e($editing['code']) : '➕ Add a counter' ?></h3>
  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="row">
      <label>Code
        <input type="text" name="code" maxlength="16" required placeholder="NPJ"
               value="<?= $e($editing['code'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?>>
      </label>
      <label>Place / town
        <input type="text" name="name" maxlength="120" required placeholder="Nepalgunj — Bus Park"
               value="<?= $e($editing['name'] ?? '') ?>">
      </label>
      <label>Country
        <select name="country">
          <option value="IN" <?= ($editing['country'] ?? 'IN') === 'IN' ? 'selected' : '' ?>>🇮🇳 India</option>
          <option value="NP" <?= ($editing['country'] ?? '') === 'NP' ? 'selected' : '' ?>>🇳🇵 Nepal</option>
        </select>
      </label>
      <label>Money it collects
        <select name="currency">
          <option value="INR" <?= ($editing['currency'] ?? 'INR') === 'INR' ? 'selected' : '' ?>>₹ INR</option>
          <option value="NPR" <?= ($editing['currency'] ?? '') === 'NPR' ? 'selected' : '' ?>>रू NPR</option>
        </select>
      </label>
      <label>Its own rate (NPR per ₹1)
        <input type="number" step="0.0001" min="0" name="fx_rate" placeholder="blank = company rate <?= $e(Settings::getFloat('npr_per_inr', 1.6)) ?>"
               value="<?= $e($editing['fx_rate'] ?? '') ?>">
      </label>
    </div>
    <div class="row">
      <label style="grid-column:span 2">Money it may take
        <?php $mAllowed = $editing !== null ? (CounterDesk::allowedMethods((string) $editing['code']) ?? CounterDesk::METHODS) : CounterDesk::METHODS; ?>
        <span style="display:flex;gap:12px;flex-wrap:wrap;padding-top:6px;text-transform:none;font-weight:600">
          <?php foreach (CounterDesk::METHODS as $mth): ?>
            <label class="chk" style="gap:5px"><input type="checkbox" name="methods[]" value="<?= $e($mth) ?>" <?= in_array($mth, $mAllowed, true) ? 'checked' : '' ?>> <?= $e(strtoupper($mth)) ?></label>
          <?php endforeach; ?>
        </span>
      </label>
      <label>Phone <input type="text" name="phone" maxlength="40" value="<?= $e($editing['phone'] ?? '') ?>"></label>
      <label>Address <input type="text" name="address" maxlength="190" value="<?= $e($editing['address'] ?? '') ?>"></label>
      <label>Order <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>"></label>
      <label class="chk"><input type="checkbox" name="is_active" value="1" <?= ($editing === null || (int) $editing['is_active'] === 1) ? 'checked' : '' ?>> Open for new sales</label>
    </div>
    <div class="row">
      <label>Note <input type="text" name="note" maxlength="255" value="<?= $e($editing['note'] ?? '') ?>"></label>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <?php if ($editing): ?><a class="btn ghost" href="<?= $base ?>/admin/counters.php">Cancel</a><?php endif; ?>
      <button class="btn ok" type="submit"><?= $editing ? '💾 Save desk' : '➕ Add desk' ?></button>
    </div>
    <p class="ctr-note" style="margin:10px 0 0">
      A Nepal desk quotes and collects NPR; the company's books stay in rupees and both numbers are
      kept on every ticket. A desk with only <b>CASH</b> ticked refuses a UPI or bank sale at the
      register itself, not just in the browser — that is the Nepalgunj rule, since the company has
      no Nepali account. Staff are put on a desk in
      <a href="<?= $base ?>/admin/staff.php">Staff &amp; Approvals</a>.
    </p>
  </form>
</div>
<?php endif; ?>

<?php if ($canEdit): $iso = Settings::getBool('counter_desk_isolation', false); ?>
<div class="ctr-form" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <div style="flex:1;min-width:240px">
    <b><?= $iso ? '🔒' : '🔓' ?> Desk isolation — <?= $iso ? 'ON' : 'OFF' ?></b>
    <div class="ctr-note">
      ON: a <em>counter</em> login sees only its own desk's ticket register, payments and passenger
      list. The bus manifest, seat map and trips board stay shared — the bus is shared, and the
      clerk boarding it must see everyone on board. The office is never scoped.
    </div>
  </div>
  <form method="post" style="margin:0">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="isolation">
    <input type="hidden" name="on" value="<?= $iso ? '' : '1' ?>">
    <button class="btn <?= $iso ? 'ghost' : 'ok' ?>" type="submit"><?= $iso ? 'Switch off' : 'Switch on' ?></button>
  </form>
</div>
<?php endif; ?>

<form method="get" class="toolbar">
  <label style="font-size:12px;font-weight:700">From <input type="date" name="from" value="<?= $e($from) ?>"></label>
  <label style="font-size:12px;font-weight:700">To <input type="date" name="to" value="<?= $e($to) ?>"></label>
  <button class="btn ghost" type="submit">Show</button>
  <a class="btn ghost" href="<?= $base ?>/admin/counters.php">Today</a>
</form>

<div class="panel">
  <h2>Counter-wise book · <?= $e(formatDate($from)) ?><?= $from === $to ? '' : ' → ' . $e(formatDate($to)) ?></h2>
  <table>
    <thead><tr>
      <th>Counter</th><th>Money</th><th>Tickets</th><th>Seats</th>
      <th>Sold (₹)</th><th>Cash in</th><th>Online / UPI</th><th>To collect</th><th>Refunded</th><th>Staff</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($allCodes as $code):
        $d      = $desks[$code] ?? null;
        $s      = $sold[$code]  ?? ['tickets' => 0, 'seats' => 0, 'inr' => 0.0, 'npr' => 0.0, 'stamped' => 0];
        $t      = $taken[$code] ?? [];
        $cash   = ($t['cash'] ?? 0.0);
        $online = array_sum($t) - $cash;
        $isNp   = $d !== null && $d['currency'] === 'NPR';
        if ($s['tickets'] === 0 && $t === [] && ($refund[$code] ?? 0) == 0 && $d === null) { continue; }
    ?>
      <tr<?= $d !== null && (int) $d['is_active'] === 0 ? ' style="opacity:.6"' : '' ?>>
        <td>
          <span class="flagpill"><?= $code === '' ? '🌐' : CounterDesk::flag($code) ?>
            <?= $e($code === '' ? 'Online / no desk' : ($d['name'] ?? $code)) ?></span>
          <?php if ($code !== ''): ?><div class="ctr-note"><?= $e($code) ?><?php
            if ($d !== null && (int) $d['is_active'] === 0) { echo ' · closed'; }
            if ($s['tickets'] > 0 && $s['stamped'] < $s['tickets']) {
                echo ' · ' . (int) ($s['tickets'] - $s['stamped']) . ' from profile';
            }
          ?></div><?php endif; ?>
        </td>
        <td><?php if ($code === ''): ?><span class="cur-inr">₹ INR</span>
            <?php else: ?><span class="<?= $isNp ? 'cur-npr' : 'cur-inr' ?>"><?= $isNp ? 'रू NPR' : '₹ INR' ?></span>
              <?php if ($isNp): ?><div class="ctr-note">1 ₹ = <?= $e(CounterDesk::rate($code)) ?></div><?php endif; ?>
              <?php $onlyM = CounterDesk::allowedMethods($code);
                    if ($onlyM !== null): ?><div class="ctr-note">💵 <?= $e(implode(' / ', $onlyM)) ?> only</div><?php endif; ?>
            <?php endif; ?></td>
        <td><?= (int) $s['tickets'] ?></td>
        <td><?= (int) $s['seats'] ?></td>
        <td><?= $e(inr($s['inr'])) ?>
          <?php if ($isNp && $s['npr'] > 0): ?><div class="npr-line">= <?= $e(CounterDesk::format($s['npr'], 'NPR')) ?> quoted</div><?php endif; ?></td>
        <td><?= $e(inr($cash)) ?>
          <?php if ($isNp && ($takenNp[$code] ?? 0) > 0): ?><div class="npr-line"><?= $e(CounterDesk::format((float) $takenNp[$code], 'NPR')) ?> in the drawer</div><?php endif; ?></td>
        <td><?= $e(inr($online)) ?></td>
        <td><?= ($pending[$code] ?? 0) > 0 ? '<strong>' . $e(inr((float) $pending[$code])) . '</strong>' : '<span class="muted">—</span>' ?></td>
        <td><?= ($refund[$code] ?? 0) > 0 ? $e(inr((float) $refund[$code])) : '<span class="muted">—</span>' ?></td>
        <td><?= ($staffAt[$code] ?? 0) > 0 ? (int) $staffAt[$code] : '<span class="muted">—</span>' ?></td>
        <td style="white-space:nowrap">
          <?php if ($code !== ''): ?>
            <a class="btn ghost sm" href="<?= $base ?>/admin/bookings.php?counter=<?= urlencode($code) ?>&from=<?= $e($from) ?>&to=<?= $e($to) ?>">Tickets</a>
            <?php if ($canEdit && $d !== null): ?>
              <a class="btn ghost sm" href="<?= $base ?>/admin/counters.php?edit=<?= urlencode($code) ?>">Edit</a>
              <form method="post" style="display:inline" onsubmit="return confirm('<?= (int) $d['is_active'] === 1 ? 'Close' : 'Reopen' ?> this counter?')">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="<?= (int) $d['is_active'] === 1 ? 'close' : 'reopen' ?>">
                <input type="hidden" name="code" value="<?= $e($code) ?>">
                <button class="btn ghost sm" type="submit"><?= (int) $d['is_active'] === 1 ? 'Close' : 'Reopen' ?></button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
      <tr class="tot-row">
        <td>Company total</td><td></td>
        <td><?= (int) $totTickets ?></td><td><?= (int) $totSeats ?></td>
        <td><?= $e(inr($totInr)) ?></td>
        <td colspan="2"><?= $e(inr($totTaken)) ?> collected</td>
        <td><?= $e(inr($totPending)) ?></td>
        <td><?= $e(inr($totRefund)) ?></td>
        <td colspan="2"></td>
      </tr>
    </tbody>
  </table>
  <p class="ctr-note" style="margin:12px 0 0">
    Every figure is the rupee the company accounts in. A Nepal desk's line also shows the NPR it
    quoted and the NPR actually in its drawer, at the rate frozen on each ticket — so the two books
    agree without anyone converting anything by hand.
    <br><b>Sold</b> is the desk that cut the ticket; <b>cash in</b> is the desk whose drawer the money
    went into. They differ on purpose: a ticket sold here and paid on boarding, or settled at another
    window, is that window's cash.
  </p>
</div>
<?php admin_footer();
