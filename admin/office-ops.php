<?php
/**
 * admin/office-ops.php — Office work: today's tasks, who is doing what, and
 * the queues that need hands (29 Oct 2026, management upgrade 3.5).
 *
 *   My tasks        what is on my plate, due / overdue first, one tap to move
 *                   a task to Doing or Done.
 *   Give a task     to myself — or, with staff.manage, to anyone in the office.
 *   Everyone        (staff.manage) every open task, grouped by person.
 *   Work queues     live counts that link to the page that clears them:
 *                   payments to verify, pay-at-boarding cash due, open support
 *                   tickets, refunds waiting, payout requests.
 *   Report          a day-by-day CSV (booked, confirmed ₹, cancellations,
 *                   ratings, tasks done) for any dates — daily or weekly.
 *
 * Due / overdue tasks ring the office bell once a day (AdminAlerts rule
 * "Task due", cron/alerts.php). Agents are not office staff and are refused.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/officetasks.php';

if (Auth::isCounterAgent()) {
    admin_header('Office work', 'office-ops');
    echo '<div class="flash bad">Office work is for office staff. Your own tickets are on <a href="/admin/agent.php">My Agent Panel</a>.</div>';
    admin_footer();
    exit;
}

$me        = (int) $admin['id'];
$isManager = Auth::can('staff.manage');
$e         = static fn($v): string => Security::e((string) $v);

/* ---- Report CSV ------------------------------------------------------ */
if (($_GET['export'] ?? '') === 'report') {
    $to   = Security::isValidDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : todayISO();
    $from = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : addDaysISO($to, -6);
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    if (!Auth::can('reports.view') && !Auth::can('dashboard.view')) {
        Auth::requireAdmin('reports.view');
    }
    $rows = OfficeTasks::report($from, $to);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="office-report-' . $from . '_to_' . $to . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Tickets booked', 'Confirmed ₹', 'Cancelled', 'Ratings', 'Average rating', 'Office tasks done']);
    $tot = ['booked' => 0, 'confirmed_money' => 0.0, 'cancelled' => 0, 'ratings' => 0, 'tasks_done' => 0];
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'], $r['booked'], number_format((float) $r['confirmed_money'], 2, '.', ''), $r['cancelled'], $r['ratings'], $r['rating_avg'], $r['tasks_done']]);
        foreach ($tot as $k => $_) { $tot[$k] += $r[$k]; }
    }
    fputcsv($out, ['Total', $tot['booked'], number_format($tot['confirmed_money'], 2, '.', ''), $tot['cancelled'], $tot['ratings'], '', $tot['tasks_done']]);
    fclose($out);
    exit;
}

/* ---- Actions (redirect-after-post) -------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again.');
        }
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'create') {
            $to = (int) ($_POST['assigned_to'] ?? $me) ?: $me;
            if (!OfficeTasks::mayAssign($me, $to, $isManager)) {
                throw new RuntimeException('Only a manager can give work to someone else.');
            }
            OfficeTasks::create($me, $to, (string) ($_POST['title'] ?? ''), (string) ($_POST['details'] ?? ''),
                (string) ($_POST['due_date'] ?? ''), (string) ($_POST['priority'] ?? 'normal'), (string) ($_POST['link'] ?? ''));
            $_SESSION['shg_ops_flash'] = ['ok', $to === $me ? 'Task added to your list.' : 'Task given — it is on their list now.'];
        } elseif ($act === 'status') {
            OfficeTasks::setStatus((int) ($_POST['task_id'] ?? 0), (string) ($_POST['status'] ?? ''), $me, $isManager);
            $_SESSION['shg_ops_flash'] = ['ok', (string) ($_POST['status'] ?? '') === 'done' ? 'Done ✓' : 'Task updated.'];
        } elseif ($act === 'delete') {
            OfficeTasks::delete((int) ($_POST['task_id'] ?? 0), $me, $isManager);
            $_SESSION['shg_ops_flash'] = ['ok', 'Task removed.'];
        }
    } catch (Throwable $ex) {
        $_SESSION['shg_ops_flash'] = ['bad', $ex->getMessage()];
    }
    Response::redirect('admin/office-ops.php' . (isset($_GET['all']) ? '?all=1' : ''));
}
$flash = $_SESSION['shg_ops_flash'] ?? null;
unset($_SESSION['shg_ops_flash']);

/* ---- Data ------------------------------------------------------------- */
$today   = todayISO();
$mine    = OfficeTasks::list($me, true);
$myCount = OfficeTasks::counts($me);
$people  = $isManager
    ? Database::fetchAll("SELECT id, full_name, username, role FROM admins WHERE is_active = 1 AND role <> 'agent' ORDER BY full_name")
    : [];
$everyone = $isManager ? OfficeTasks::list(null, false, 300) : [];

$queue = static function (string $sql, array $p = []): array {
    try {
        $r = Database::fetch($sql, $p);
        return [(int) ($r['n'] ?? 0), (float) ($r['amt'] ?? 0)];
    } catch (Throwable $ex) {
        return [0, 0.0];
    }
};
$queues = [];
if (Auth::can('payments.view')) {
    [$n, $a] = $queue("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM payments WHERE status = 'pending'");
    $queues[] = ['💳', 'Payments to verify', $n, $a, '/admin/payments.php?tab=pending'];
    [$n, $a] = $queue("SELECT COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS amt FROM payments p JOIN bookings b ON b.id = p.booking_id
                        WHERE b.is_cod = 1 AND p.status = 'cod_pending' AND b.status NOT IN ('cancelled','rejected')");
    $queues[] = ['💵', 'Pay-at-boarding cash to collect', $n, $a, '/admin/payments.php?tab=cod'];
}
if (Auth::can('support.view')) {
    [$n, $a] = $queue("SELECT COUNT(*) AS n, 0 AS amt FROM support_tickets WHERE status IN ('open','in_progress')");
    $queues[] = ['🎧', 'Open support tickets', $n, 0.0, '/admin/support-inbox.php'];
}
if (Auth::can('refunds.view')) {
    [$n, $a] = $queue("SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt FROM bookings WHERE refund_status = 'pending'");
    $queues[] = ['↩️', 'Refunds waiting (settled by hand)', $n, $a, '/admin/refunds.php'];
}
if (Auth::can('commissions.view')) {
    [$n, $a] = $queue("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amt FROM agent_payout_requests WHERE status = 'open'");
    $queues[] = ['🏦', 'Agent payout requests', $n, $a, '/admin/settlements.php'];
}
$csrf = Security::csrfField();

/** One task row (list item). */
$taskRow = static function (array $t, bool $showWho) use ($e, $csrf, $me, $isManager, $today): string {
    $due   = (string) ($t['due_date'] ?? '');
    $late  = $due !== '' && $due < $today && $t['status'] !== 'done';
    $isToday = $due === $today && $t['status'] !== 'done';
    $cls   = 'op-t s-' . $t['status'] . ' p-' . $t['priority'] . ($late ? ' late' : '');
    $h  = '<li class="' . $e($cls) . '">';
    $h .= '<div class="op-main"><b>' . $e($t['title']) . '</b>';
    if ((string) ($t['details'] ?? '') !== '') {
        $h .= '<div class="op-det">' . $e($t['details']) . '</div>';
    }
    $h .= '<div class="op-meta">';
    if ($t['priority'] === 'high') { $h .= '<span class="op-pill hi">High</span>'; }
    if ($due !== '') {
        $h .= '<span class="op-pill ' . ($late ? 'late' : ($isToday ? 'today' : '')) . '">' . ($late ? 'Overdue · ' : ($isToday ? 'Today · ' : 'Due ')) . $e(formatDate($due, 'j M')) . '</span>';
    }
    if ($showWho) { $h .= '<span>→ ' . $e($t['assignee_name'] ?: $t['assignee_user']) . '</span>'; }
    if ((int) ($t['created_by'] ?? 0) !== (int) $t['assigned_to'] && (string) ($t['creator_name'] ?? '') !== '') { $h .= '<span class="muted">from ' . $e($t['creator_name']) . '</span>'; }
    if ((string) ($t['link'] ?? '') !== '') { $h .= '<a href="' . $e($t['link']) . '">open →</a>'; }
    if ($t['status'] === 'done') { $h .= '<span class="muted">done ' . $e(timeAgo((string) $t['done_at'])) . '</span>'; }
    $h .= '</div></div><div class="op-acts">';
    $mayMove = $isManager || (int) $t['assigned_to'] === $me || (int) ($t['created_by'] ?? 0) === $me;
    if ($mayMove) {
        foreach (['doing' => '▶ Doing', 'done' => '✓ Done', 'open' => '↺ Reopen'] as $st => $lbl) {
            if ($st === $t['status'] || ($st === 'open' && $t['status'] !== 'done') || ($st === 'doing' && $t['status'] === 'done')) { continue; }
            $h .= '<form method="post">' . $csrf . '<input type="hidden" name="action" value="status"><input type="hidden" name="task_id" value="' . (int) $t['id'] . '"><input type="hidden" name="status" value="' . $st . '"><button class="btn ' . ($st === 'done' ? 'ok' : 'ghost') . '" type="submit">' . $lbl . '</button></form>';
        }
    }
    if ($isManager || (int) ($t['created_by'] ?? 0) === $me) {
        $h .= '<form method="post" onsubmit="return confirm(\'Remove this task?\')">' . $csrf . '<input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="' . (int) $t['id'] . '"><button class="btn ghost" type="submit" title="Remove" aria-label="Remove task">✕</button></form>';
    }
    return $h . '</div></li>';
};

admin_header('Office work', 'office-ops');
?>
<style>
.op-top{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.op-top div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px}
.op-top small{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--mut)}
.op-top b{font-size:24px;font-variant-numeric:tabular-nums}
.op-list{list-style:none;margin:0;padding:6px 14px 12px}
.op-t{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:12px;border:1px solid var(--line);border-left:5px solid var(--blue);border-radius:12px;margin-top:8px;background:var(--card)}
.op-t.p-high{border-left-color:#e67e22}.op-t.late{border-left-color:#c0392b}.op-t.s-done{opacity:.6;border-left-color:#0a8b4b}
.op-t.s-doing{background:linear-gradient(90deg,rgba(46,95,168,.06),transparent)}
.op-main{min-width:0;flex:1}
.op-main b{font-size:14.5px}
.op-det{font-size:13px;color:var(--mut);margin-top:3px;white-space:pre-line}
.op-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px;font-size:12px}
.op-pill{display:inline-block;padding:2px 9px;border-radius:99px;background:var(--head);border:1px solid var(--line);font-weight:700}
.op-pill.hi{background:#fff1de;color:#8a4b00;border-color:#f6d6a5}.op-pill.late{background:#fde2e1;color:#a12a22;border-color:#f5b5b0}.op-pill.today{background:#e1ecfb;color:#1f4f96;border-color:#bcd3f5}
.op-acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.op-acts form{margin:0}.op-acts .btn{min-height:40px;padding:0 12px;font-size:12.5px}
.op-form{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:10px;padding:6px 18px 16px}
.op-form label{font-size:12px;font-weight:700;color:var(--mut);display:flex;flex-direction:column;gap:4px}
.op-form input,.op-form select,.op-form textarea{min-height:44px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:14px;font-weight:500}
.op-form .full{grid-column:1 / -1}
.op-q{list-style:none;margin:0;padding:0}
.op-q a{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none;min-height:48px}
.op-q a:hover{background:var(--hover)}
.op-q .n{margin-left:auto;font-weight:800;font-variant-numeric:tabular-nums}
.op-q .n small{color:var(--mut);font-weight:500;margin-left:6px}
.op-q .zero{opacity:.55}
.op-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:16px}
@media(max-width:900px){.op-grid{grid-template-columns:1fr}.op-form{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.op-top{grid-template-columns:1fr 1fr 1fr}.op-top b{font-size:20px}.op-t{flex-direction:column}.op-acts{justify-content:flex-start}}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="op-top">
  <div><small>My open tasks</small><b><?= (int) $myCount['open'] ?></b></div>
  <div><small>Due today</small><b style="color:<?= $myCount['today'] > 0 ? '#1f4f96' : 'inherit' ?>"><?= (int) $myCount['today'] ?></b></div>
  <div><small>Overdue</small><b style="color:<?= $myCount['overdue'] > 0 ? '#c0392b' : 'inherit' ?>"><?= (int) $myCount['overdue'] ?></b></div>
</div>

<div class="op-grid">
  <div>
    <div class="panel">
      <h2>📝 My tasks · मेरो काम</h2>
      <?php if ($mine === []): ?>
        <p class="muted" style="padding:0 18px 16px;margin:0">Nothing on your list. Add one below.</p>
      <?php else: ?>
        <ul class="op-list"><?php foreach ($mine as $t) { echo $taskRow($t, false); } ?></ul>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>➕ <?= $isManager ? 'Give a task' : 'Add a task' ?></h2>
      <form method="post" class="op-form">
        <?= $csrf ?>
        <input type="hidden" name="action" value="create">
        <label class="full">What needs doing
          <input type="text" name="title" maxlength="191" required placeholder="e.g. Call Surat agent about cash handover"></label>
        <?php if ($isManager && $people !== []): ?>
          <label>For
            <select name="assigned_to">
              <?php foreach ($people as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $me ? 'selected' : '' ?>><?= $e(($p['full_name'] ?: $p['username']) . ((int) $p['id'] === $me ? ' (me)' : ' · ' . $p['role'])) ?></option>
              <?php endforeach; ?>
            </select></label>
        <?php else: ?>
          <input type="hidden" name="assigned_to" value="<?= $me ?>">
        <?php endif; ?>
        <label>Due
          <input type="date" name="due_date" min="<?= $e(addDaysISO($today, -30)) ?>" value="<?= $e($today) ?>"></label>
        <label>Priority
          <select name="priority"><?php foreach (OfficeTasks::PRIORITIES as $pk => $pl): ?><option value="<?= $pk ?>" <?= $pk === 'normal' ? 'selected' : '' ?>><?= $e($pl) ?></option><?php endforeach; ?></select></label>
        <label>Link (optional)
          <input type="text" name="link" maxlength="255" placeholder="/admin/booking-view.php?pnr=…"></label>
        <label class="full">Details (optional)
          <textarea name="details" maxlength="1000" rows="2" placeholder="Phone number, PNR, what to say…"></textarea></label>
        <div class="full"><button class="btn ok" type="submit" style="min-height:46px;padding:0 22px">Save task</button></div>
      </form>
    </div>

    <?php if ($isManager): ?>
    <div class="panel">
      <h2>👥 Everyone's open work · <?= count($everyone) ?></h2>
      <?php if ($everyone === []): ?>
        <p class="muted" style="padding:0 18px 16px;margin:0">No open task in the office.</p>
      <?php else: ?>
        <ul class="op-list"><?php foreach ($everyone as $t) { echo $taskRow($t, true); } ?></ul>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <h2>📥 Work queues</h2>
      <?php if ($queues === []): ?>
        <p class="muted" style="padding:0 18px 16px;margin:0">No queue for your role.</p>
      <?php else: ?>
      <ul class="op-q">
        <?php foreach ($queues as [$ic, $lbl, $n, $amt, $href]): ?>
          <li><a href="<?= $e($href) ?>" class="<?= $n === 0 ? 'zero' : '' ?>"><span><?= $ic ?> <?= $e($lbl) ?></span><span class="n"><?= (int) $n ?><?= $amt > 0 ? '<small>' . $e(inr($amt)) . '</small>' : '' ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <?php if (Auth::can('reports.view') || Auth::can('dashboard.view')): ?>
    <div class="panel">
      <h2>📊 Daily / weekly report</h2>
      <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;padding:0 18px 16px">
        <input type="hidden" name="export" value="report">
        <label class="muted" style="font-size:11px">From<input type="date" name="from" value="<?= $e(addDaysISO($today, -6)) ?>" style="display:block;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
        <label class="muted" style="font-size:11px">To<input type="date" name="to" value="<?= $e($today) ?>" style="display:block;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
        <button class="btn" type="submit">⬇ CSV</button>
      </form>
      <p class="muted" style="font-size:12px;padding:0 18px 14px;margin:0">One row a day: tickets booked, confirmed ₹, cancellations, customer ratings and office tasks done, with a total line.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer();
