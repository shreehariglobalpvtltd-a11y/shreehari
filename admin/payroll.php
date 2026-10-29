<?php
/**
 * admin/payroll.php — Staff Payroll · तलब (2 Oct 2026).
 *
 * Monthly salary + advances for the people who work for the company
 * WITHOUT a panel login: bus helpers and conductors, office boys,
 * cleaners, supervisors and drivers. Agents and counter staff who sign in
 * keep their salary in the agent wallet (Agent Panel → Salary) — this page
 * never touches agent_ledger, and a salary paid here is NOT an Accounting
 * expense row, so nothing is counted twice.
 *
 * One month at a time (?m=YYYY-MM, default this month):
 *   • strip — salary due · paid · pending · open advances
 *   • one row per person — Pay salary (pre-filled; net worked out live and
 *     re-checked on the server), Advance, Edit, History, Deactivate
 *   • after a payment — "Send slip on WhatsApp" (wa.me, +91 / +977 picked
 *     the way every ticket send picks it)
 *   • ?m=…&export=csv — the month as a spreadsheet
 *
 * All money rules live in includes/payroll.php (Payroll). Built for a phone:
 * cards under 820 px, 48 px buttons, 16 px inputs.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/payroll.php';

// View: the same gate as Accounting (accounting.php: admin_boot('payments.view'))…
$admin = admin_boot('payments.view');
// …plus reports.view. Counter and official desks hold payments.view only to
// verify a customer's UPI — colleagues' salaries are not theirs to read.
// Superadmin, manager and accountant pass; counter, official, agent do not.
Auth::requireAdmin('reports.view');

// Writes: mirror of accounting.php:184 ($canSpend) — the expense-writing rule.
$canWrite = Auth::isSuperadmin() || Auth::can('payments.verify');
$by       = (int) ($admin['id'] ?? 0);
$e        = static fn(mixed $v): string => Security::e($v);

$mRaw   = $_POST['m'] ?? ($_GET['m'] ?? '');
$period = is_string($mRaw) ? Security::clean($mRaw, 7) : '';
if (!Payroll::isPeriod($period)) {
    $period = Payroll::currentPeriod();
}
$label = Payroll::periodLabel($period);

/* ---- Not migrated yet: a friendly notice, never a 500 ----------------- */
if (!Payroll::ready()) {
    admin_header('Staff Payroll', 'payroll');
    echo '<div class="panel" style="max-width:640px;margin:24px auto;padding:28px 24px;text-align:center">'
       . '<div style="font-size:40px;line-height:1">🧾</div>'
       . '<h2 style="border:0;background:none;justify-content:center">Staff Payroll is not set up yet</h2>'
       . '<p class="muted">The payroll tables do not exist on this database. Run <code>' . $e(Payroll::MIGRATION) . '</code> once '
       . '(phpMyAdmin → SQL, or <code>php tests/apply-sql.php ' . $e(Payroll::MIGRATION) . '</code>) and reload this page.</p>'
       . '</div>';
    admin_footer();
    exit;
}

/* ---- CSV export of the month ------------------------------------------ */
if (($_GET['export'] ?? '') === 'csv') {
    require_once __DIR__ . '/_export.php';
    [$csvHead, $csvRows] = Payroll::csv($period);
    shg_export_csv('payroll-' . $period . '.csv', $csvHead, $csvRows);   // streams + exits
}

/* ---- POST: every write, then redirect (a refresh never re-sends) ------- */
$flash = null;
$slip  = null;       // ['name' => …, 'link' => …] — the WhatsApp slip after a payment
$open  = null;       // ['staff' => id|0, 'panel' => name] — re-open the form that failed
$old   = [];         // what was typed, to refill that form
if (isset($_SESSION['payroll_flash']) && is_array($_SESSION['payroll_flash'])) {
    $flash = $_SESSION['payroll_flash']['flash'] ?? null;
    $slip  = $_SESSION['payroll_flash']['slip'] ?? null;
    unset($_SESSION['payroll_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $sid = is_scalar($_POST['staff_id'] ?? null) ? (int) $_POST['staff_id'] : 0;
    $panelOf = ['add_staff' => 'add', 'edit_staff' => 'edit', 'advance' => 'advance', 'cancel_advance' => 'advance',
                'pay' => 'pay', 'correct_pay' => 'pay', 'deactivate' => 'edit', 'activate' => 'edit'];
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again.');
        }
        if (!$canWrite) {
            throw new RuntimeException('You may not change the payroll.');
        }
        $anchor  = $sid > 0 ? 's' . $sid : '';
        $newSlip = null;
        switch ($act) {
            case 'add_staff':
                $sid    = Payroll::addStaff($_POST, $by);
                $anchor = 's' . $sid;
                $msg    = (Payroll::staff($sid)['full_name'] ?? 'The person') . ' is on the payroll now.';
                break;
            case 'edit_staff':
                Payroll::updateStaff($sid, $_POST, $by);
                $msg = 'Details saved.';
                break;
            case 'deactivate':
            case 'activate':
                Payroll::setActive($sid, $act === 'activate', $by);
                $msg = (Payroll::staff($sid)['full_name'] ?? 'The person') . ($act === 'activate' ? ' is active again.' : ' is deactivated — history kept.');
                break;
            case 'advance':
                Payroll::giveAdvance($sid, $_POST, $by);
                $st  = Payroll::staff($sid) ?? [];
                $msg = 'Advance saved. ' . ($st['full_name'] ?? '') . ' now owes ' . Payroll::money(Payroll::openBalance($sid), (string) ($st['currency'] ?? 'INR')) . '.';
                break;
            case 'cancel_advance':
                Payroll::cancelAdvance((int) ($_POST['advance_id'] ?? 0), $by);
                $msg = 'Advance cancelled.';
                break;
            case 'pay':
            case 'correct_pay':
                if ($act === 'pay') {
                    $pid = Payroll::pay($sid, $period, $_POST, $by);
                } else {
                    $pid = (int) ($_POST['payment_id'] ?? 0);
                    Payroll::correctPayment($pid, $_POST, $by);
                }
                $pay = Payroll::payment($pid);
                $st  = $pay !== null ? Payroll::staff((int) $pay['staff_id']) : null;
                if ($pay === null || $st === null) {
                    throw new RuntimeException('Saved, but the payment could not be read back — reload the page.');
                }
                $sid    = (int) $st['id'];
                $anchor = 's' . $sid;
                $msg    = ($act === 'pay' ? 'Salary paid: ' : 'Payment corrected: ') . $st['full_name'] . ' · ' . Payroll::periodLabel((string) $pay['period'])
                        . ' · net ' . Payroll::money((float) $pay['net_paid'], (string) $pay['currency']) . '.';
                $newSlip = [
                    'name' => (string) $st['full_name'],
                    'link' => Payroll::waLink($st, Payroll::slipText($st, $pay, Payroll::openBalance($sid))),
                ];
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        $_SESSION['payroll_flash'] = ['flash' => ['ok', $msg], 'slip' => $newSlip];
        Response::redirect('admin/payroll.php?m=' . $period . ($anchor !== '' ? '#' . $anchor : ''));
    } catch (PDOException $ex) {                 // never show raw SQL to the desk
        Logger::exception($ex);
        $flash = ['bad', 'That did not save — please try again.'];
    } catch (RuntimeException $ex) {
        $flash = ['bad', $ex->getMessage()];
        $open  = ['staff' => $act === 'add_staff' ? 0 : $sid, 'panel' => $panelOf[$act] ?? ''];
        $old   = $_POST;
    } catch (Throwable $ex) {
        Logger::exception($ex);
        $flash = ['bad', 'That did not save — please try again.'];
    }
}

/* ---- Read the month ---------------------------------------------------- */
$sheet    = Payroll::monthSheet($period);
$rows     = $sheet['rows'];
$tot      = $sheet['totals'];
$inactive = $canWrite ? Payroll::inactiveStaff() : [];
$history  = Payroll::history(array_map(static fn(array $r): int => (int) $r['id'], $rows));
$drivers  = $canWrite ? Payroll::driverChoices() : [];
$today    = todayISO();
$prev     = Payroll::shiftPeriod($period, -1);
$next     = Payroll::shiftPeriod($period, 1);
$isNow    = $period === Payroll::currentPeriod();

/** Value for a field: what was just typed (only on the form that failed), else the default. */
$val = static function (int $staffId, string $panel, string $key, mixed $default) use ($open, $old): string {
    if ($open !== null && $open['staff'] === $staffId && $open['panel'] === $panel && array_key_exists($key, $old) && is_scalar($old[$key])) {
        return (string) $old[$key];
    }
    return (string) ($default ?? '');
};
$isOpen = static fn(int $staffId, string $panel): bool => $open !== null && $open['staff'] === $staffId && $open['panel'] === $panel;
$num    = static fn(float $v): string => abs($v - round($v)) > 0.004 ? number_format($v, 2, '.', '') : (string) (int) round($v);

admin_header('Staff Payroll', 'payroll');
?>
<style>
  .pr{--pr-gap:12px}
  .pr .btn{min-height:48px;padding:12px 16px;font-size:15px}
  .pr input:not([type=checkbox]):not([type=radio]):not([type=hidden]),.pr select,.pr textarea{font-size:16px;min-height:48px;width:100%;padding:10px 12px;border:1px solid var(--line-2);border-radius:var(--r-sm);background:var(--card);color:var(--ink);font-family:inherit}
  .pr textarea{min-height:72px}
  .pr input:focus,.pr select:focus,.pr textarea:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-100)}
  .pr label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:var(--ink-2)}
  .pr label small{font-weight:500;color:var(--mut)}
  .pr-month{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
  .pr-month h2{margin:0;font-size:22px;font-weight:800;letter-spacing:-.01em;min-width:120px;text-align:center}
  .pr-month .pr-arrow{min-width:48px;font-size:20px;padding:8px 12px}
  .pr-month .pr-sp{flex:1}
  .pr-note{margin:0 0 16px;font-size:13px}
  .pr .kpis{margin-bottom:16px}
  .pr-slip{display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--wa-bg);border:1px solid var(--wa);border-radius:var(--r);padding:12px 16px;margin-bottom:16px}
  .pr-slip b{flex:1;min-width:180px}
  .pr-add{margin-bottom:16px}
  .pr-add>summary{list-style:none;display:inline-flex;cursor:pointer}
  .pr-add>summary::-webkit-details-marker{display:none}
  .pr-add[open]>summary{margin-bottom:12px}
  .pr-box{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:16px;box-shadow:var(--sh-1)}
  .pr-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:var(--pr-gap);align-items:end}
  .pr-form .pr-wide{grid-column:1/-1}
  .pr-form .pr-submit{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap}
  .pr-net{font-size:24px;font-weight:800;font-variant-numeric:tabular-nums;color:var(--ok);min-height:48px;display:flex;align-items:center}
  .pr-net.bad{color:var(--bad)}
  .pr-warn{color:var(--bad);font-size:13px;font-weight:600;grid-column:1/-1}
  .pr-warn:empty{display:none}
  .pr-list{display:flex;flex-direction:column;gap:12px}
  .pr .kpi .kk{white-space:normal}
  .pr-head,.pr-main{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1.6fr);gap:6px 14px;align-items:center}
  .pr-head{padding:0 18px;font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
  .pr-row{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--line-2);border-radius:var(--r);box-shadow:var(--sh-1);scroll-margin-top:calc(var(--tb-h) + 12px)}
  .pr-row.is-paid{border-left-color:var(--ok)}
  .pr-row.is-due{border-left-color:var(--orange)}
  .pr-row.is-off{opacity:.75}
  .pr-main{padding:14px 18px}
  .pr-who b{font-size:16px}
  .pr-who .muted{font-size:12.5px;margin-top:3px}
  .pr-cell{font-variant-numeric:tabular-nums;font-weight:700}
  .pr-cell small{display:block;font-weight:500;color:var(--mut);font-size:12px}
  .pr-cell .pill{font-size:13px}
  .pill.pr-ok{background:var(--ok-bg);color:var(--ok)}
  .pill.pr-due{background:var(--orange-50);color:var(--orange-600)}
  .pill.pr-na{background:var(--soft);color:var(--mut)}
  .pr-acts{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
  .pr-acts form{display:contents}
  .pr-panel{border-top:1px dashed var(--line-2);padding:16px 18px;background:var(--soft);border-radius:0 0 var(--r) var(--r)}
  .pr-panel h3{margin:0 0 12px;font-size:15px}
  .pr-panel .tbl-scroll{background:var(--card);border:1px solid var(--line);border-radius:var(--r-sm);margin-bottom:14px}
  .pr-panel table td,.pr-panel table th{padding:9px 12px}
  .pr-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .pr-adv{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;background:var(--card);border:1px solid var(--line);border-radius:var(--r-sm);margin-bottom:8px;font-size:14px}
  .pr-adv span{flex:1;min-width:160px}
  .pr-empty{padding:28px 18px;text-align:center}
  @media(max-width:820px){
    .pr-head{display:none}
    .pr-main{grid-template-columns:1fr 1fr;gap:10px 14px;padding:14px}
    .pr-who,.pr-status,.pr-acts{grid-column:1/-1}
    .pr-cell[data-k]::before{content:attr(data-k);display:block;font-size:11px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px}
    .pr-acts{justify-content:stretch}
    .pr-acts .btn{flex:1 1 calc(50% - 8px)}
    .pr-acts .pr-primary{flex-basis:100%}
    .pr-panel{padding:14px}
    .pr-form{grid-template-columns:1fr 1fr}
    .pr-month h2{font-size:19px;min-width:0;flex:1}
    .pr-month .pr-sp{display:none}
  }
  @media(max-width:420px){.pr-form{grid-template-columns:1fr}}
  @media print{.pr-acts,.pr-panel,.pr-add,.pr-month .btn,.pr-slip{display:none!important}}
</style>

<div class="pr">

<div class="pr-month">
  <a class="btn ghost pr-arrow" href="?m=<?= $e($prev) ?>" aria-label="Previous month" title="<?= $e(Payroll::periodLabel($prev)) ?>">‹</a>
  <h2><?= $e($label) ?></h2>
  <a class="btn ghost pr-arrow" href="?m=<?= $e($next) ?>" aria-label="Next month" title="<?= $e(Payroll::periodLabel($next)) ?>">›</a>
  <?php if (!$isNow): ?><a class="btn ghost" href="?m=<?= $e(Payroll::currentPeriod()) ?>">This month</a><?php endif; ?>
  <span class="pr-sp"></span>
  <a class="btn ghost" href="?m=<?= $e($period) ?>&amp;export=csv"><svg class="a-ic"><use href="#a-download"/></svg> CSV</a>
</div>
<p class="muted pr-note">Salary for helpers, conductors, drivers, office and cleaning staff — people without a panel login.
  Agents and counter staff who sign in are paid from <a href="/admin/agent.php">Agent Panel → Salary</a>.
  Payroll is kept separate: do not also enter these salaries as <a href="/admin/accounting.php#expenses">Accounting expenses</a>.</p>

<?php if ($flash !== null): ?>
  <div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div>
<?php endif; ?>
<?php if (is_array($slip) && !empty($slip['link'])): ?>
  <div class="pr-slip">
    <b>Send <?= $e($slip['name']) ?> the salary slip</b>
    <a class="btn wa" href="<?= $e($slip['link']) ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-whatsapp"/></svg> Send slip on WhatsApp</a>
  </div>
<?php endif; ?>

<div class="kpis">
  <?= admin_kpi('Salary due', inr((float) $tot['due']), $label . ' · ' . $tot['people'] . ' ' . ($tot['people'] === 1 ? 'person' : 'people'), 'rupee', 'navy') ?>
  <?= admin_kpi('Paid', inr((float) $tot['paid']), $tot['paidCount'] . ' paid' . ((float) $tot['recovered'] > 0 ? ' · ' . inr((float) $tot['recovered']) . ' taken back from advances' : ''), 'check-circle', 'green') ?>
  <?= admin_kpi('Pending', inr((float) $tot['pending']), $tot['pendingCount'] > 0 ? $tot['pendingCount'] . ' still to pay' : 'everyone is paid', 'clock', 'orange') ?>
  <?= admin_kpi('Advances owed', inr((float) $tot['advances']), 'open, to take back from salaries', 'loan', 'violet') ?>
</div>
<?php if (!empty($tot['mixed'])): ?>
  <p class="muted pr-note">Totals in ₹ — NPR salaries converted @ 1 INR = <?= $e((string) Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6)) ?> NPR.</p>
<?php endif; ?>

<?php if ($canWrite): ?>
<details class="pr-add" id="add"<?= $isOpen(0, 'add') ? ' open' : '' ?>>
  <summary class="btn"><svg class="a-ic"><use href="#a-plus"/></svg> Add staff</summary>
  <div class="pr-box">
    <form method="post" class="pr-form" action="?m=<?= $e($period) ?>#add">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="add_staff">
      <input type="hidden" name="m" value="<?= $e($period) ?>">
      <?php if ($drivers !== []): ?>
      <label class="pr-wide">Already in Drivers &amp; Crew? <small>optional — fills the name and mobile</small>
        <select name="driver_id" data-pr-driver>
          <option value="">— not a driver from the list —</option>
          <?php foreach ($drivers as $d): ?>
            <option value="<?= (int) $d['id'] ?>" data-name="<?= $e($d['full_name']) ?>" data-phone="<?= $e($d['phone']) ?>"<?= $val(0, 'add', 'driver_id', '') === (string) $d['id'] ? ' selected' : '' ?>><?= $e($d['full_name']) ?> · <?= $e($d['role']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label>Name<input type="text" name="full_name" maxlength="120" required value="<?= $e($val(0, 'add', 'full_name', '')) ?>" autocomplete="off"></label>
      <label>Mobile <small>10 digits, optional</small><input type="tel" name="phone" inputmode="numeric" maxlength="16" value="<?= $e($val(0, 'add', 'phone', '')) ?>" autocomplete="off"></label>
      <label>Mobile is in
        <select name="phone_country">
          <option value="IN"<?= $val(0, 'add', 'phone_country', 'IN') === 'IN' ? ' selected' : '' ?>>India (+91)</option>
          <option value="NP"<?= $val(0, 'add', 'phone_country', 'IN') === 'NP' ? ' selected' : '' ?>>Nepal (+977)</option>
        </select></label>
      <label>Job
        <select name="role" required>
          <?php foreach (Payroll::ROLES as $rk => $rl): ?><option value="<?= $e($rk) ?>"<?= $val(0, 'add', 'role', 'helper') === $rk ? ' selected' : '' ?>><?= $e($rl) ?></option><?php endforeach; ?>
        </select></label>
      <label>Location<input type="text" name="base_location" maxlength="80" list="pr-locs" value="<?= $e($val(0, 'add', 'base_location', '')) ?>" placeholder="Surat, Nepalgunj …"></label>
      <label>Monthly salary<input type="number" name="monthly_salary" min="0" step="0.01" inputmode="decimal" required value="<?= $e($val(0, 'add', 'monthly_salary', '')) ?>"></label>
      <label>Currency
        <select name="currency">
          <?php foreach (Payroll::CURRENCIES as $c): ?><option value="<?= $e($c) ?>"<?= $val(0, 'add', 'currency', 'INR') === $c ? ' selected' : '' ?>><?= $e($c === 'INR' ? 'INR ₹' : 'NPR रु') ?></option><?php endforeach; ?>
        </select></label>
      <label>Joined on<input type="date" name="joined_on" max="<?= $e($today) ?>" value="<?= $e($val(0, 'add', 'joined_on', '')) ?>"></label>
      <label class="pr-wide">Notes<input type="text" name="notes" maxlength="255" value="<?= $e($val(0, 'add', 'notes', '')) ?>" placeholder="bus, shift, bank details …"></label>
      <div class="pr-submit"><button class="btn ok" type="submit"><svg class="a-ic"><use href="#a-check"/></svg> Add to payroll</button></div>
    </form>
  </div>
</details>
<datalist id="pr-locs"><?php foreach (Payroll::LOCATIONS as $loc): ?><option value="<?= $e($loc) ?>"><?php endforeach; ?></datalist>
<?php endif; ?>

<?php if ($rows === []): ?>
  <div class="panel"><?= admin_empty('Nobody on the payroll yet', $canWrite ? 'Press “Add staff” to add a helper, conductor, driver or office person.' : 'Ask the office to add staff.', '🧾') ?></div>
<?php else: ?>
<div class="pr-head" aria-hidden="true"><span>Person</span><span>Salary</span><span>Advance owed</span><span><?= $e($label) ?></span></div>
<div class="pr-list">
<?php foreach ($rows as $r):
    $sid      = (int) $r['id'];
    $cur      = (string) $r['currency'];
    $m        = static fn(float $v): string => Payroll::money($v, $cur);
    $pay      = $r['payment'];
    $active   = (int) $r['is_active'] === 1;
    $hist     = $history[$sid] ?? ['payments' => [], 'advances' => []];
    $latestId = (int) ($hist['payments'][0]['id'] ?? 0);
    $slipLink = $pay !== null
        ? Payroll::waLink($r, Payroll::slipText($r, $pay, (int) $pay['id'] === $latestId ? (float) $r['balance'] : null))
        : '';
    $owedForForm = $pay !== null ? round((float) $r['balance'] + (float) $pay['advance_deduction'], 2) : (float) $r['balance'];
    $rowCls   = !$active ? 'is-off' : ($pay !== null ? 'is-paid' : ($r['due'] ? 'is-due' : ''));
?>
  <article class="pr-row <?= $rowCls ?>" id="s<?= $sid ?>">
    <div class="pr-main">
      <div class="pr-who">
        <b><?= $e($r['full_name']) ?></b>
        <span class="pill pr-na"><?= $e(Payroll::ROLES[(string) $r['role']] ?? $r['role']) ?></span>
        <?php if (!$active): ?><span class="pill" style="background:var(--bad-bg);color:var(--bad)">Inactive</span><?php endif; ?>
        <div class="muted">
          <?= !empty($r['base_location']) ? '📍 ' . $e($r['base_location']) : '' ?>
          <?php if (!empty($r['phone'])): ?> · <a href="tel:+<?= $e(Payroll::waNumber($r)) ?>">+<?= $e(Payroll::waNumber($r)) ?></a><?php endif; ?>
          <?= !empty($r['driver_name']) ? ' · 🚌 driver list: ' . $e($r['driver_name']) : '' ?>
        </div>
      </div>
      <div class="pr-cell" data-k="Salary"><?= $e($m((float) $r['monthly_salary'])) ?><small>per month</small></div>
      <div class="pr-cell" data-k="Advance owed"><?php if ((float) $r['balance'] > 0): ?><?= $e($m((float) $r['balance'])) ?><small><?= count($r['open_advances']) ?> open</small><?php else: ?><span class="muted">—</span><?php endif; ?></div>
      <div class="pr-cell pr-status" data-k="<?= $e($label) ?>">
        <?php if ($pay !== null): ?>
          <span class="pill pr-ok">✓ Paid <?= $e($m((float) $pay['net_paid'])) ?></span>
          <small>on <?= $e(formatDate((string) $pay['paid_on'], 'j M')) ?> · <?= $e(Payroll::METHODS[(string) $pay['method']] ?? $pay['method']) ?><?= (float) $pay['advance_deduction'] > 0 ? ' · ' . $e($m((float) $pay['advance_deduction'])) . ' advance taken back' : '' ?></small>
        <?php elseif ($r['joined_later']): ?>
          <span class="pill pr-na">Not due — joined <?= $e(formatDate((string) $r['joined_on'], 'j M Y')) ?></span>
        <?php elseif ($active): ?>
          <span class="pill pr-due">Due <?= $e($m((float) $r['expected_net'])) ?></span>
          <?php if ((float) $r['suggested'] > 0): ?><small>after <?= $e($m((float) $r['suggested'])) ?> advance</small><?php endif; ?>
        <?php else: ?>
          <span class="pill pr-na">Inactive</span>
        <?php endif; ?>
      </div>
      <div class="pr-acts">
        <?php if ($pay !== null): ?>
          <a class="btn wa pr-primary" href="<?= $e($slipLink) ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-whatsapp"/></svg> Send slip on WhatsApp</a>
          <a class="btn ghost" href="/admin/payslip.php?id=<?= (int) $pay['id'] ?>" title="Printable salary slip — Print saves it as a PDF">🖨 Slip / PDF</a>
          <?php if ($canWrite): ?><button class="btn ghost" type="button" data-open="pay" aria-expanded="false"><svg class="a-ic"><use href="#a-edit"/></svg> Correct payment</button><?php endif; ?>
        <?php elseif ($canWrite && $active): ?>
          <button class="btn ok pr-primary" type="button" data-open="pay" aria-expanded="false"><svg class="a-ic"><use href="#a-rupee"/></svg> Pay salary</button>
        <?php endif; ?>
        <?php if ($canWrite && $active): ?>
          <button class="btn ghost" type="button" data-open="advance" aria-expanded="false"><svg class="a-ic"><use href="#a-loan"/></svg> Advance</button>
          <button class="btn ghost" type="button" data-open="edit" aria-expanded="false"><svg class="a-ic"><use href="#a-user-cog"/></svg> Edit</button>
        <?php endif; ?>
        <button class="btn ghost" type="button" data-open="history" aria-expanded="false"><svg class="a-ic"><use href="#a-history"/></svg> History</button>
        <?php if ($canWrite): ?>
          <form method="post" action="?m=<?= $e($period) ?>#s<?= $sid ?>" onsubmit="return confirm(<?= $e(json_encode($active
                ? 'Deactivate ' . $r['full_name'] . '? Their history is kept.' . ((float) $r['balance'] > 0 ? ' They still owe ' . $m((float) $r['balance']) . ' advance.' : '')
                : 'Make ' . $r['full_name'] . ' active again?', JSON_UNESCAPED_UNICODE)) ?>)">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="<?= $active ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="staff_id" value="<?= $sid ?>">
            <input type="hidden" name="m" value="<?= $e($period) ?>">
            <button class="btn ghost<?= $active ? ' danger' : '' ?>" type="submit"><?= $active ? '<svg class="a-ic"><use href="#a-x-circle"/></svg> Deactivate' : '<svg class="a-ic"><use href="#a-refresh"/></svg> Activate' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canWrite && ($active || $pay !== null)):
        $pp = $pay !== null ? 'Correct ' : 'Pay ';
        $gross = $pay !== null ? (float) $pay['gross'] : (float) $r['monthly_salary'];
        $advD  = $pay !== null ? (float) $pay['advance_deduction'] : (float) $r['suggested'];
    ?>
    <div class="pr-panel" data-panel="pay"<?= $isOpen($sid, 'pay') ? '' : ' hidden' ?>>
      <h3><?= $e($pp . $r['full_name'] . ' · salary for ' . $label) ?></h3>
      <form method="post" class="pr-form" action="?m=<?= $e($period) ?>#s<?= $sid ?>" data-net data-owed="<?= $e($num($owedForForm)) ?>" data-cur="<?= $e($cur) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="<?= $pay !== null ? 'correct_pay' : 'pay' ?>">
        <input type="hidden" name="staff_id" value="<?= $sid ?>">
        <?php if ($pay !== null): ?><input type="hidden" name="payment_id" value="<?= (int) $pay['id'] ?>"><?php endif; ?>
        <input type="hidden" name="m" value="<?= $e($period) ?>">
        <input type="hidden" name="net" value="">
        <label>Salary (<?= $e($cur) ?>)<input type="number" name="gross" min="0" step="0.01" inputmode="decimal" required value="<?= $e($val($sid, 'pay', 'gross', $num($gross))) ?>"></label>
        <label>Advance taken back <small><?= $owedForForm > 0 ? 'owes ' . $e($m($owedForForm)) : 'no advance open' ?></small><input type="number" name="advance_deduction" min="0" step="0.01" inputmode="decimal" max="<?= $e($num($owedForForm)) ?>" value="<?= $e($val($sid, 'pay', 'advance_deduction', $num($advD))) ?>"></label>
        <label>Bonus<input type="number" name="bonus" min="0" step="0.01" inputmode="decimal" value="<?= $e($val($sid, 'pay', 'bonus', $pay !== null ? $num((float) $pay['bonus']) : '0')) ?>"></label>
        <label>Other deduction <small>leave, fine …</small><input type="number" name="other_deduction" min="0" step="0.01" inputmode="decimal" value="<?= $e($val($sid, 'pay', 'other_deduction', $pay !== null ? $num((float) $pay['other_deduction']) : '0')) ?>"></label>
        <label>Net to pay<output class="pr-net" data-net-out><?= $e($m($pay !== null ? (float) $pay['net_paid'] : (float) $r['monthly_salary'] - (float) $r['suggested'])) ?></output></label>
        <label>Paid on<input type="date" name="paid_on" max="<?= $e($today) ?>" required value="<?= $e($val($sid, 'pay', 'paid_on', $pay !== null ? (string) $pay['paid_on'] : $today)) ?>"></label>
        <label>Paid by
          <select name="method">
            <?php foreach (Payroll::METHODS as $mk => $ml): ?><option value="<?= $e($mk) ?>"<?= $val($sid, 'pay', 'method', $pay !== null ? (string) $pay['method'] : 'cash') === $mk ? ' selected' : '' ?>><?= $e($ml) ?></option><?php endforeach; ?>
          </select></label>
        <label>UPI / bank ref <small>optional</small><input type="text" name="reference" maxlength="80" value="<?= $e($val($sid, 'pay', 'reference', $pay['reference'] ?? '')) ?>"></label>
        <label class="pr-wide">Note <small>optional</small><input type="text" name="notes" maxlength="255" value="<?= $e($val($sid, 'pay', 'notes', $pay['notes'] ?? '')) ?>"></label>
        <div class="pr-warn" data-net-warn></div>
        <div class="pr-submit">
          <button class="btn ok" type="submit"><svg class="a-ic"><use href="#a-check"/></svg> <?= $pay !== null ? 'Save correction' : 'Save payment' ?></button>
          <button class="btn ghost" type="button" data-open="pay">Close</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($canWrite && $active): ?>
    <div class="pr-panel" data-panel="advance"<?= $isOpen($sid, 'advance') ? '' : ' hidden' ?>>
      <h3><?= $e('Advance for ' . $r['full_name']) ?></h3>
      <?php foreach ($r['open_advances'] as $a): $out = (float) $a['amount'] - (float) $a['recovered']; ?>
        <div class="pr-adv">
          <span><b><?= $e($m($out)) ?></b> still owed of <?= $e($m((float) $a['amount'])) ?> · given <?= $e(formatDate((string) $a['given_on'], 'j M Y')) ?>
            · <?= (float) $a['monthly_deduction'] > 0 ? $e($m((float) $a['monthly_deduction'])) . ' a month' : 'all at next salary' ?>
            <?= !empty($a['reason']) ? ' · ' . $e($a['reason']) : '' ?></span>
          <?php if ((float) $a['recovered'] <= 0.004): ?>
          <form method="post" action="?m=<?= $e($period) ?>#s<?= $sid ?>" onsubmit="return confirm('Cancel this advance? Only for an advance entered by mistake.')">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="cancel_advance">
            <input type="hidden" name="staff_id" value="<?= $sid ?>">
            <input type="hidden" name="advance_id" value="<?= (int) $a['id'] ?>">
            <input type="hidden" name="m" value="<?= $e($period) ?>">
            <button class="btn ghost danger" type="submit">Cancel</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <form method="post" class="pr-form" action="?m=<?= $e($period) ?>#s<?= $sid ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="advance">
        <input type="hidden" name="staff_id" value="<?= $sid ?>">
        <input type="hidden" name="m" value="<?= $e($period) ?>">
        <label>Amount (<?= $e($cur) ?>)<input type="number" name="amount" min="1" step="0.01" inputmode="decimal" required value="<?= $e($val($sid, 'advance', 'amount', '')) ?>"></label>
        <label>Given on<input type="date" name="given_on" max="<?= $e($today) ?>" required value="<?= $e($val($sid, 'advance', 'given_on', $today)) ?>"></label>
        <label>Take back each month <small>0 = all from the next salary</small><input type="number" name="monthly_deduction" min="0" step="0.01" inputmode="decimal" value="<?= $e($val($sid, 'advance', 'monthly_deduction', '0')) ?>"></label>
        <label>Reason <small>optional</small><input type="text" name="reason" maxlength="255" value="<?= $e($val($sid, 'advance', 'reason', '')) ?>"></label>
        <div class="pr-submit">
          <button class="btn warn" type="submit"><svg class="a-ic"><use href="#a-check"/></svg> Save advance</button>
          <button class="btn ghost" type="button" data-open="advance">Close</button>
        </div>
      </form>
    </div>

    <div class="pr-panel" data-panel="edit"<?= $isOpen($sid, 'edit') ? '' : ' hidden' ?>>
      <h3><?= $e('Edit ' . $r['full_name']) ?></h3>
      <form method="post" class="pr-form" action="?m=<?= $e($period) ?>#s<?= $sid ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="edit_staff">
        <input type="hidden" name="staff_id" value="<?= $sid ?>">
        <input type="hidden" name="m" value="<?= $e($period) ?>">
        <?php if ($drivers !== [] || !empty($r['driver_id'])): ?>
        <label class="pr-wide">Linked driver <small>optional</small>
          <select name="driver_id">
            <option value="">— none —</option>
            <?php foreach ($drivers as $d): ?><option value="<?= (int) $d['id'] ?>"<?= $val($sid, 'edit', 'driver_id', (string) ($r['driver_id'] ?? '')) === (string) $d['id'] ? ' selected' : '' ?>><?= $e($d['full_name']) ?> · <?= $e($d['role']) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>
        <label>Name<input type="text" name="full_name" maxlength="120" required value="<?= $e($val($sid, 'edit', 'full_name', $r['full_name'])) ?>"></label>
        <label>Mobile<input type="tel" name="phone" inputmode="numeric" maxlength="16" value="<?= $e($val($sid, 'edit', 'phone', $r['phone'] ?? '')) ?>"></label>
        <label>Mobile is in
          <select name="phone_country">
            <option value="IN"<?= $val($sid, 'edit', 'phone_country', (string) $r['phone_country']) === 'IN' ? ' selected' : '' ?>>India (+91)</option>
            <option value="NP"<?= $val($sid, 'edit', 'phone_country', (string) $r['phone_country']) === 'NP' ? ' selected' : '' ?>>Nepal (+977)</option>
          </select></label>
        <label>Job
          <select name="role">
            <?php foreach (Payroll::ROLES as $rk => $rl): ?><option value="<?= $e($rk) ?>"<?= $val($sid, 'edit', 'role', (string) $r['role']) === $rk ? ' selected' : '' ?>><?= $e($rl) ?></option><?php endforeach; ?>
          </select></label>
        <label>Location<input type="text" name="base_location" maxlength="80" list="pr-locs" value="<?= $e($val($sid, 'edit', 'base_location', $r['base_location'] ?? '')) ?>"></label>
        <label>Monthly salary<input type="number" name="monthly_salary" min="0" step="0.01" inputmode="decimal" required value="<?= $e($val($sid, 'edit', 'monthly_salary', $num((float) $r['monthly_salary']))) ?>"></label>
        <label>Currency
          <select name="currency">
            <?php foreach (Payroll::CURRENCIES as $c): ?><option value="<?= $e($c) ?>"<?= $val($sid, 'edit', 'currency', $cur) === $c ? ' selected' : '' ?>><?= $e($c === 'INR' ? 'INR ₹' : 'NPR रु') ?></option><?php endforeach; ?>
          </select></label>
        <label>Joined on<input type="date" name="joined_on" max="<?= $e($today) ?>" value="<?= $e($val($sid, 'edit', 'joined_on', $r['joined_on'] ?? '')) ?>"></label>
        <label class="pr-wide">Notes<input type="text" name="notes" maxlength="255" value="<?= $e($val($sid, 'edit', 'notes', $r['notes'] ?? '')) ?>"></label>
        <div class="pr-submit">
          <button class="btn" type="submit"><svg class="a-ic"><use href="#a-check"/></svg> Save details</button>
          <button class="btn ghost" type="button" data-open="edit">Close</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="pr-panel" data-panel="history" hidden>
      <h3><?= $e('History · ' . $r['full_name']) ?></h3>
      <?php if ($hist['payments'] === [] && $hist['advances'] === []): ?>
        <p class="muted" style="margin:0">No salary or advance recorded yet.</p>
      <?php endif; ?>
      <?php if ($hist['payments'] !== []): ?>
      <div class="tbl-scroll"><table>
        <thead><tr><th>Month</th><th class="pr-num">Salary</th><th class="pr-num">Advance</th><th class="pr-num">Bonus</th><th class="pr-num">Other</th><th class="pr-num">Net paid</th><th>Paid on</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($hist['payments'] as $p): $pm = static fn(float $v): string => Payroll::money($v, (string) $p['currency']); ?>
          <tr>
            <td><a href="?m=<?= $e($p['period']) ?>#s<?= $sid ?>"><?= $e(Payroll::periodLabel((string) $p['period'])) ?></a><?= !empty($p['updated_at']) ? ' <small class="muted" title="corrected ' . $e($p['updated_at']) . '">✎</small>' : '' ?></td>
            <td class="pr-num"><?= $e($pm((float) $p['gross'])) ?></td>
            <td class="pr-num"><?= (float) $p['advance_deduction'] > 0 ? '−' . $e($pm((float) $p['advance_deduction'])) : '—' ?></td>
            <td class="pr-num"><?= (float) $p['bonus'] > 0 ? '+' . $e($pm((float) $p['bonus'])) : '—' ?></td>
            <td class="pr-num"><?= (float) $p['other_deduction'] > 0 ? '−' . $e($pm((float) $p['other_deduction'])) : '—' ?></td>
            <td class="pr-num"><b><?= $e($pm((float) $p['net_paid'])) ?></b></td>
            <td><?= $e(formatDate((string) $p['paid_on'], 'j M Y')) ?></td>
            <td><?= $e(Payroll::METHODS[(string) $p['method']] ?? $p['method']) ?><?= !empty($p['reference']) ? ' · ' . $e($p['reference']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <?php if ($hist['advances'] !== []): ?>
      <div class="tbl-scroll"><table>
        <thead><tr><th>Advance given</th><th class="pr-num">Amount</th><th class="pr-num">Per month</th><th class="pr-num">Taken back</th><th>Status</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($hist['advances'] as $a): ?>
          <tr>
            <td><?= $e(formatDate((string) $a['given_on'], 'j M Y')) ?></td>
            <td class="pr-num"><?= $e($m((float) $a['amount'])) ?></td>
            <td class="pr-num"><?= (float) $a['monthly_deduction'] > 0 ? $e($m((float) $a['monthly_deduction'])) : 'all' ?></td>
            <td class="pr-num"><?= $e($m((float) $a['recovered'])) ?></td>
            <td><?= $e(['open' => 'Open', 'cleared' => '✓ Cleared', 'cancelled' => 'Cancelled'][(string) $a['status']] ?? $a['status']) ?></td>
            <td><?= $e($a['reason'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
      <button class="btn ghost" type="button" data-open="history">Close</button>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($inactive !== []): ?>
<details class="panel" style="margin-top:22px">
  <summary style="padding:14px 18px;cursor:pointer;font-weight:700">Inactive staff (<?= count($inactive) ?>)</summary>
  <?php foreach ($inactive as $s): ?>
    <div class="pr-adv" style="margin:0 18px 10px">
      <span><b><?= $e($s['full_name']) ?></b> · <?= $e(Payroll::ROLES[(string) $s['role']] ?? $s['role']) ?>
        <?= (float) $s['balance'] > 0 ? ' · <b style="color:var(--bad)">owes ' . $e(Payroll::money((float) $s['balance'], (string) $s['currency'])) . '</b>' : '' ?></span>
      <form method="post" action="?m=<?= $e($period) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="activate">
        <input type="hidden" name="staff_id" value="<?= (int) $s['id'] ?>">
        <input type="hidden" name="m" value="<?= $e($period) ?>">
        <button class="btn ghost" type="submit"><svg class="a-ic"><use href="#a-refresh"/></svg> Activate</button>
      </form>
    </div>
  <?php endforeach; ?>
</details>
<?php endif; ?>

</div>

<script>
(function () {
  /* Open / close one form per person (Pay · Advance · Edit · History). */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.pr [data-open]') : null;
    if (!b) return;
    var row = b.closest('.pr-row'), name = b.getAttribute('data-open');
    if (!row) return;
    var target = row.querySelector('.pr-panel[data-panel="' + name + '"]');
    var show = !!(target && target.hidden);
    row.querySelectorAll('.pr-panel').forEach(function (p) { p.hidden = true; });
    if (target) target.hidden = !show;
    row.querySelectorAll('.pr-acts [data-open]').forEach(function (x) {
      x.setAttribute('aria-expanded', show && x.getAttribute('data-open') === name ? 'true' : 'false');
    });
    if (show) {
      var f = target.querySelector('input:not([type=hidden]),select');
      try { target.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
      if (f && window.matchMedia('(pointer:fine)').matches) f.focus({ preventScroll: true });
    }
  });

  /* Net = salary − advance + bonus − other, worked out as they type.
     The server works it out again and refuses a mismatch. */
  function money(n, cur) {
    var s = Math.abs(n).toLocaleString('en-IN', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
    return (n < 0 ? '−' : '') + (cur === 'NPR' ? 'NPR ' : '₹') + s;
  }
  document.querySelectorAll('.pr form[data-net]').forEach(function (f) {
    var out = f.querySelector('[data-net-out]'), warn = f.querySelector('[data-net-warn]');
    var btn = f.querySelector('button[type=submit]'), cur = f.getAttribute('data-cur') || 'INR';
    var owed = parseFloat(f.getAttribute('data-owed') || '0') || 0;
    function v(n) { var x = parseFloat(String((f.elements[n] || {}).value || '').replace(/,/g, '')); return isFinite(x) ? x : 0; }
    function update() {
      var adv = v('advance_deduction');
      var net = Math.round((v('gross') - adv + v('bonus') - v('other_deduction')) * 100) / 100;
      f.elements['net'].value = net.toFixed(2);
      out.textContent = money(net, cur);
      var msg = '';
      if (adv > owed + 0.004) msg = 'Advance taken back is more than the ' + money(owed, cur) + ' owed.';
      else if (net < 0) msg = 'Deductions are more than the salary.';
      out.classList.toggle('bad', msg !== '');
      warn.textContent = msg;
      btn.disabled = msg !== '';
    }
    f.addEventListener('input', update);
    update();
  });

  /* Picking a driver from Drivers & Crew fills the empty name / mobile. */
  var dsel = document.querySelector('.pr [data-pr-driver]');
  if (dsel) dsel.addEventListener('change', function () {
    var o = dsel.options[dsel.selectedIndex], f = dsel.form;
    if (!o || !o.value) return;
    if (!f.elements['full_name'].value) f.elements['full_name'].value = o.getAttribute('data-name') || '';
    if (!f.elements['phone'].value) f.elements['phone'].value = o.getAttribute('data-phone') || '';
    f.elements['role'].value = 'driver';
  });

  /* A form that failed on the server comes back open — bring it into view. */
  var opened = document.querySelector('.pr .pr-panel:not([hidden])');
  if (opened) { try { opened.scrollIntoView({ block: 'center' }); } catch (e) {} }
})();
</script>
<?php
admin_footer();
