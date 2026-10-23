<?php
/**
 * admin/expenses.php — Daily trip-expense book + per-route profit/loss.
 *
 * The `trip_expenses` table has been reserved in the schema for a while
 * (route_id · expense_date · diesel · toll · driver_pay · misc · total)
 * but had no surface to record against and nothing to read it back. This
 * page is that surface: the office logs a day's running costs for a route
 * (diesel, toll, the crew's pay, anything else), and the P&L panel sets
 * those against the confirmed ticket revenue for the same route and month
 * so each route's profit or loss is visible at a glance.
 *
 * This is pure book-keeping — it records numbers the office types in and
 * reads revenue already in the bookings. It moves no money and touches no
 * payment, refund or booking row. Every add / delete is written to the
 * audit log with who did it.
 *
 * Gated on `payments.view` (office money scope — the same gate the
 * Accounting and Verify-Payments pages carry); the counter ticketing role
 * ('agent') does not hold `payments.view`, so it never reaches this page.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$base    = '';   // root-relative: stay on the host the staff signed in on
$flash   = null;
$adminId = (int) ($admin['id'] ?? 0);

// Same INR peg the Accounting / Analytics pages use, so this P&L reconciles
// with them (config.php defines it; fall back to the shared 1.6 default).
if (!defined('NPR_PER_INR')) {
    define('NPR_PER_INR', 1.6);
}

/* The table ships in schema.sql but may be missing on a live DB installed
   from an older base (there was never an upgrade-*.sql for it). Detect that
   once so the page shows a clear "run the migration" notice instead of a
   500, and so no write is attempted against a table that isn't there. */
$haveTable = true;
try {
    Database::scalar('SELECT 1 FROM trip_expenses LIMIT 1', [], null);
} catch (Throwable $e) {
    $haveTable = false;
}

/* ---- Add / delete an expense row ----------------------------------- */
if ($haveTable && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('payments.view');
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $routeId = (int) ($_POST['route_id'] ?? 0);
                $date    = Security::clean((string) ($_POST['expense_date'] ?? ''), 10);
                $note    = Security::clean((string) ($_POST['note'] ?? ''), 255);

                // Amounts: never negative, two decimals, computed total
                // server-side — the client total is ignored.
                $num = static fn(string $k): float => round(max(0.0, (float) ($_POST[$k] ?? 0)), 2);
                $diesel    = $num('diesel');
                $toll      = $num('toll');
                $driverPay = $num('driver_pay');
                $misc      = $num('misc');
                $total     = round($diesel + $toll + $driverPay + $misc, 2);

                if ($routeId <= 0) {
                    throw new RuntimeException('Choose the route this expense is for.');
                }
                $route = Database::fetch(
                    'SELECT id, route_code, from_city, to_city FROM routes WHERE id = :i',
                    ['i' => $routeId]
                );
                if ($route === null) {
                    throw new RuntimeException('That route no longer exists.');
                }
                if (!Security::isValidDate($date)) {
                    throw new RuntimeException('Enter a valid expense date (YYYY-MM-DD).');
                }
                if ($total <= 0) {
                    throw new RuntimeException('Enter at least one amount before saving.');
                }

                $id = Database::insert('trip_expenses', [
                    'route_id'     => $routeId,
                    'expense_date' => $date,
                    'diesel'       => $diesel,
                    'toll'         => $toll,
                    'driver_pay'   => $driverPay,
                    'misc'         => $misc,
                    'total'        => $total,
                    'note'         => $note !== '' ? $note : null,
                    'created_by'   => $adminId > 0 ? $adminId : null,
                ]);

                Logger::audit('expense.add', 'trip_expense', (string) $id, null, [
                    'route'      => (string) $route['route_code'],
                    'date'       => $date,
                    'diesel'     => $diesel,
                    'toll'       => $toll,
                    'driver_pay' => $driverPay,
                    'misc'       => $misc,
                    'total'      => $total,
                ], $note);

                $flash = ['ok', 'Expense of ' . inr($total) . ' saved for '
                    . $route['route_code'] . ' on ' . formatDate($date) . '.'];

            } elseif ($action === 'delete') {
                $id  = (int) ($_POST['id'] ?? 0);
                $row = $id > 0
                    ? Database::fetch('SELECT * FROM trip_expenses WHERE id = :i', ['i' => $id])
                    : null;
                if ($row === null) {
                    throw new RuntimeException('That expense row no longer exists.');
                }

                Database::delete('trip_expenses', 'id = :i', ['i' => $id]);

                Logger::audit('expense.delete', 'trip_expense', (string) $id, [
                    'route_id' => (int) $row['route_id'],
                    'date'     => (string) $row['expense_date'],
                    'total'    => (float) $row['total'],
                ], null, 'deleted by admin #' . $adminId);

                $flash = ['ok', 'Expense of ' . inr((float) $row['total']) . ' removed.'];

            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Period + route filters ---------------------------------------- */
$month = Security::clean((string) ($_GET['month'] ?? substr(todayISO(), 0, 7)), 7);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = substr(todayISO(), 0, 7);
}
$d1 = $month . '-01';
try {
    $d2 = (new DateTimeImmutable($d1))->format('Y-m-t');   // last day of that month
} catch (Throwable $e) {
    $month = substr(todayISO(), 0, 7);
    $d1    = $month . '-01';
    $d2    = (new DateTimeImmutable($d1))->format('Y-m-t');
}
$routeFilter = (int) ($_GET['route'] ?? 0);

$routes = Database::fetchAll(
    'SELECT id, route_code, from_city, to_city, is_active
       FROM routes ORDER BY is_active DESC, sort_order, id'
);
$routeName = [];
foreach ($routes as $r) {
    $routeName[(int) $r['id']] = (string) $r['route_code']
        . ' · ' . (string) $r['from_city'] . ' → ' . (string) $r['to_city'];
}

/* ---- Load the month's data ----------------------------------------- */
$rows       = [];
$periodTot  = ['diesel' => 0.0, 'toll' => 0.0, 'driver_pay' => 0.0, 'misc' => 0.0, 'total' => 0.0];
$pnl        = [];   // route_id => ['revenue'=>, 'expense'=>]
$revTotal   = 0.0;
$expTotal   = 0.0;

if ($haveTable) {
    $where  = 'e.expense_date BETWEEN :d1 AND :d2';
    $params = ['d1' => $d1, 'd2' => $d2];
    if ($routeFilter > 0) {
        $where            .= ' AND e.route_id = :rt';
        $params['rt']      = $routeFilter;
    }

    $rows = Database::fetchAll(
        "SELECT e.id, e.route_id, e.expense_date, e.diesel, e.toll, e.driver_pay,
                e.misc, e.total, e.note, e.created_by, e.created_at,
                r.route_code, r.from_city, r.to_city,
                a.full_name AS by_name
           FROM trip_expenses e
           JOIN routes r ON r.id = e.route_id
      LEFT JOIN admins a ON a.id = e.created_by
          WHERE $where
          ORDER BY e.expense_date DESC, e.id DESC
          LIMIT 500",
        $params
    );

    foreach ($rows as $q) {
        $periodTot['diesel']     += (float) $q['diesel'];
        $periodTot['toll']       += (float) $q['toll'];
        $periodTot['driver_pay'] += (float) $q['driver_pay'];
        $periodTot['misc']       += (float) $q['misc'];
        $periodTot['total']      += (float) $q['total'];
    }

    /* Expenses by route for the month (whole month — the P&L ignores the
       route filter so the office always sees every route's picture). */
    foreach (Database::fetchAll(
        "SELECT route_id, SUM(total) AS tot FROM trip_expenses
          WHERE expense_date BETWEEN :d1 AND :d2 GROUP BY route_id",
        ['d1' => $d1, 'd2' => $d2]
    ) as $er) {
        $rid = (int) $er['route_id'];
        $pnl[$rid]            = $pnl[$rid] ?? ['revenue' => 0.0, 'expense' => 0.0];
        $pnl[$rid]['expense'] = (float) $er['tot'];
        $expTotal            += (float) $er['tot'];
    }

    /* Confirmed/completed ticket revenue by route for travel in the month.
       The outbound-leg join yields one row per booking (every booking has a
       single outbound leg), so total_amount is counted once — the correct
       booking-level basis, not trip-dashboard.php's per-seat SUM (which
       multiplies by seat count). NPR is converted to ₹ at the same peg and
       the paid-status set matches Accounting/Analytics, so the P&L
       reconciles with those pages. */
    foreach (Database::fetchAll(
        "SELECT s.route_id,
                SUM(CASE WHEN b.currency = 'NPR' THEN b.total_amount / " . NPR_PER_INR . " ELSE b.total_amount END) AS revenue
           FROM bookings b
           JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           JOIN schedules s ON s.id = bl.schedule_id
          WHERE b.status IN ('confirmed','completed')
            AND bl.travel_date BETWEEN :d1 AND :d2
          GROUP BY s.route_id",
        ['d1' => $d1, 'd2' => $d2]
    ) as $rr) {
        $rid = (int) $rr['route_id'];
        $pnl[$rid]            = $pnl[$rid] ?? ['revenue' => 0.0, 'expense' => 0.0];
        $pnl[$rid]['revenue'] = (float) $rr['revenue'];
        $revTotal            += (float) $rr['revenue'];
    }

    /* Sort P&L rows by net ascending so the worst route is on top. */
    uksort($pnl, static function (int $a, int $b) use ($pnl): int {
        $na = $pnl[$a]['revenue'] - $pnl[$a]['expense'];
        $nb = $pnl[$b]['revenue'] - $pnl[$b]['expense'];
        return $na <=> $nb;
    });
}

$netTotal = $revTotal - $expTotal;

/* The summary cards follow the route filter (the P&L table below always
   shows every route). With a route selected the cards show just that route;
   otherwise the whole month across all routes. */
$routeCode = '';
foreach ($routes as $r) {
    if ((int) $r['id'] === $routeFilter) { $routeCode = (string) $r['route_code']; break; }
}
$scopeLabel = $routeFilter > 0 ? ($routeCode !== '' ? $routeCode : ('route #' . $routeFilter)) : 'all routes';
$cardRev    = $routeFilter > 0 ? (float) ($pnl[$routeFilter]['revenue'] ?? 0.0) : $revTotal;
$cardExp    = $routeFilter > 0 ? (float) ($pnl[$routeFilter]['expense'] ?? 0.0) : $expTotal;
$cardNet    = $cardRev - $cardExp;

$csrf     = Security::e(Security::csrfToken());
$k        = CSRF_TOKEN_NAME;
$today    = todayISO();

admin_header('Trip Expenses', 'expenses');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}

if (!$haveTable):
?>
<div class="panel">
  <h2>One-time setup needed</h2>
  <p class="muted" style="padding:6px 2px 10px">
    The <span class="mono">trip_expenses</span> table isn't in this database yet.
    Apply the migration once, then reload this page:
  </p>
  <pre class="mono" style="background:var(--head,#f3f4f6);padding:12px 14px;border-radius:10px;overflow:auto">php tests/apply-sql.php database/upgrade-2026-10-trip-expenses.sql</pre>
  <p class="muted" style="padding:8px 2px 0">Or paste <span class="mono">database/upgrade-2026-10-trip-expenses.sql</span> into phpMyAdmin → SQL. It is safe to re-run.</p>
</div>
<?php
    admin_footer();
    return;
endif;
?>

<div class="cards">
  <div class="card"><div class="k">Revenue · <?= Security::e($scopeLabel) ?></div><div class="v"><?= Security::e(inr($cardRev)) ?></div></div>
  <div class="card"><div class="k">Expenses · <?= Security::e($scopeLabel) ?></div><div class="v"><?= Security::e(inr($cardExp)) ?></div></div>
  <div class="card">
    <div class="k">Net (profit / loss) · <?= Security::e($scopeLabel) ?></div>
    <div class="v" style="color:<?= $cardNet >= 0 ? '#0a6b3b' : '#c0392b' ?>">
      <?= $cardNet < 0 ? '−' : '' ?><?= Security::e(inr(abs($cardNet))) ?>
    </div>
  </div>
  <div class="card"><div class="k">Expense rows · <?= Security::e($month) ?></div><div class="v"><?= count($rows) ?></div></div>
</div>

<form method="get" class="toolbar">
  <label>Month <input type="month" name="month" value="<?= Security::e($month) ?>" onchange="this.form.submit()"></label>
  <label>Route
    <select name="route" onchange="this.form.submit()">
      <option value="0">All routes</option>
      <?php foreach ($routes as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $routeFilter ? 'selected' : '' ?>>
          <?= Security::e($routeName[(int) $r['id']] ?? ('Route #' . (int) $r['id'])) ?><?= (int) $r['is_active'] === 1 ? '' : ' — inactive' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn ghost" type="submit">Load</button>
</form>

<div class="panel">
  <h2>Add an expense</h2>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;padding:4px 2px 2px">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="add">
    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mut)">Route
      <select name="route_id" required style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);min-width:220px">
        <option value="">— choose —</option>
        <?php foreach ($routes as $r): if ((int) $r['is_active'] !== 1) continue; ?>
          <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $routeFilter ? 'selected' : '' ?>><?= Security::e($routeName[(int) $r['id']]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mut)">Date
      <input type="date" name="expense_date" value="<?= Security::e($today) ?>" max="<?= Security::e($today) ?>" required style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
    </label>
    <?php
      $fields = ['diesel' => 'Diesel', 'toll' => 'Toll', 'driver_pay' => 'Driver pay', 'misc' => 'Misc'];
      foreach ($fields as $fk => $flabel):
    ?>
      <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mut)"><?= Security::e($flabel) ?>
        <input type="number" name="<?= $fk ?>" step="0.01" min="0" value="0" style="width:100px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;text-align:right">
      </label>
    <?php endforeach; ?>
    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mut);flex:1;min-width:160px">Note
      <input type="text" name="note" maxlength="255" placeholder="optional" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
    </label>
    <button class="btn ok" type="submit">Save expense</button>
  </form>
  <p class="muted" style="font-size:12px;margin:8px 2px 0">Total is added up for you from the four amounts. Enter costs in ₹.</p>
</div>

<div class="panel">
  <h2>Profit / loss by route · <?= Security::e($month) ?></h2>
  <table>
    <thead><tr>
      <th>Route</th><th style="text-align:right">Revenue (confirmed)</th>
      <th style="text-align:right">Expenses</th><th style="text-align:right">Net</th><th style="text-align:right">Margin</th>
    </tr></thead>
    <tbody>
    <?php if ($pnl === []): ?>
      <tr><td colspan="5" class="muted" style="padding:22px;text-align:center">No revenue or expenses recorded for this month.</td></tr>
    <?php else: foreach ($pnl as $rid => $pv):
      $rev = $pv['revenue']; $exp = $pv['expense']; $net = $rev - $exp;
      $margin = $rev > 0 ? round($net * 100 / $rev) : null;
    ?>
      <tr>
        <td><?= Security::e($routeName[$rid] ?? ('Route #' . $rid)) ?></td>
        <td style="text-align:right"><?= Security::e(inr($rev)) ?></td>
        <td style="text-align:right"><?= Security::e(inr($exp)) ?></td>
        <td style="text-align:right;font-weight:700;color:<?= $net >= 0 ? '#0a6b3b' : '#c0392b' ?>">
          <?= $net < 0 ? '−' : '' ?><?= Security::e(inr(abs($net))) ?>
        </td>
        <td style="text-align:right" class="muted"><?= $margin === null ? '—' : ($margin . '%') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($pnl !== []): ?>
    <tfoot><tr style="border-top:2px solid var(--line);font-weight:700">
      <td>All routes</td>
      <td style="text-align:right"><?= Security::e(inr($revTotal)) ?></td>
      <td style="text-align:right"><?= Security::e(inr($expTotal)) ?></td>
      <td style="text-align:right;color:<?= $netTotal >= 0 ? '#0a6b3b' : '#c0392b' ?>">
        <?= $netTotal < 0 ? '−' : '' ?><?= Security::e(inr(abs($netTotal))) ?>
      </td>
      <td style="text-align:right" class="muted"><?= $revTotal > 0 ? round($netTotal * 100 / $revTotal) . '%' : '—' ?></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
  <p class="muted" style="font-size:12px;padding:8px 2px 0">
    Revenue = confirmed bookings travelling on that route this month. Expenses = what's logged below. The P&L always shows every route, ignoring the route filter above.
  </p>
</div>

<div class="panel">
  <h2><?= $routeFilter > 0 ? Security::e($routeName[$routeFilter] ?? 'Route') . ' · ' : '' ?>Expenses · <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></h2>
  <table>
    <thead><tr>
      <th>Date</th><th>Route</th><th style="text-align:right">Diesel</th><th style="text-align:right">Toll</th>
      <th style="text-align:right">Driver</th><th style="text-align:right">Misc</th><th style="text-align:right">Total</th>
      <th>Note</th><th>By</th><th></th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="10" class="muted" style="padding:22px;text-align:center">No expenses logged for this month<?= $routeFilter > 0 ? ' on this route' : '' ?> yet.</td></tr>
    <?php else: foreach ($rows as $q): ?>
      <tr>
        <td><?= Security::e(formatDate((string) $q['expense_date'])) ?></td>
        <td><?= Security::e((string) $q['route_code']) ?><div class="muted" style="font-size:11px"><?= Security::e((string) $q['from_city'] . ' → ' . (string) $q['to_city']) ?></div></td>
        <td style="text-align:right"><?= Security::e(inr((float) $q['diesel'])) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $q['toll'])) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $q['driver_pay'])) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $q['misc'])) ?></td>
        <td style="text-align:right;font-weight:700"><?= Security::e(inr((float) $q['total'])) ?></td>
        <td class="muted"><?= Security::e(truncate((string) ($q['note'] ?? ''), 48)) ?></td>
        <td class="muted"><?= Security::e((string) ($q['by_name'] ?? '—')) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Delete this expense row?')" style="margin:0">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
            <button class="btn ghost" type="submit" style="padding:5px 9px;font-size:12px">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($rows !== []): ?>
    <tfoot><tr style="border-top:2px solid var(--line);font-weight:700">
      <td colspan="2"><?= $routeFilter > 0 ? 'This route' : 'All routes' ?> · <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></td>
      <td style="text-align:right"><?= Security::e(inr($periodTot['diesel'])) ?></td>
      <td style="text-align:right"><?= Security::e(inr($periodTot['toll'])) ?></td>
      <td style="text-align:right"><?= Security::e(inr($periodTot['driver_pay'])) ?></td>
      <td style="text-align:right"><?= Security::e(inr($periodTot['misc'])) ?></td>
      <td style="text-align:right"><?= Security::e(inr($periodTot['total'])) ?></td>
      <td colspan="3"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table>
</div>

<p class="muted">
  Expenses are book-keeping only — recording them moves no money and changes no booking, payment or refund.
  Every add and delete is written to the audit log. Costs are in ₹.
</p>
<?php
admin_footer();
