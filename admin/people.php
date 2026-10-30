<?php
/**
 * admin/people.php — everyone who signs in, on one screen (29 Oct 2026,
 * management upgrade 4.1 "Unified People").
 *
 *   Agents    code, wallet (commission due · cash held), sales this month,
 *             KYC — the name opens Agent 360.
 *   Counters  desk / location, shift today, tickets and cash taken today.
 *   Staff     role, desk / branch, last sign-in.
 *   All       every account, with search and a role filter.
 *
 * Row actions — activate / disable, a new password, the desk (location /
 * branch) — are posted to admin/staff.php, the one place every account
 * change already goes through (same super-admin rule, same audit rows), and
 * come back here. Nothing on this page writes by itself.
 *
 * Branch: an account's desk (admin_profiles.counter_code / counter_name) is
 * its location; the same picker serves an office account as its branch.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('staff.manage');
require_once INCLUDE_PATH . '/agenttargets.php';
require_once INCLUDE_PATH . '/commandcenter.php';

$e       = static fn($v): string => Security::e((string) $v);
$isSuper = Auth::isSuperadmin();
$tabs    = ['agents' => '🧑‍💼 Agents', 'counters' => '🏪 Counters', 'staff' => '👔 Staff', 'all' => '👥 All'];
$tab     = (string) ($_GET['tab'] ?? 'agents');
if (!isset($tabs[$tab])) { $tab = 'agents'; }
$q       = Security::clean((string) ($_GET['q'] ?? ''), 60);
$roleF   = Security::clean((string) ($_GET['role'] ?? ''), 20);
$showOff = (string) ($_GET['off'] ?? '') === '1';
$flash   = $_SESSION['shg_people_flash'] ?? null;
unset($_SESSION['shg_people_flash']);

/* ---- Everyone, once -------------------------------------------------- */
$rows = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.email, a.role, a.is_active, a.last_login_at, a.created_at,
            p.counter_code, p.counter_name, p.kyc_status
       FROM admins a
       LEFT JOIN admin_profiles p ON p.admin_id = a.id
      ORDER BY a.is_active DESC, a.full_name, a.username"
);
$byRole = ['agent' => 0, 'counter' => 0, 'staff' => 0];
foreach ($rows as $r) {
    if ((int) $r['is_active'] !== 1) { continue; }
    $byRole[in_array($r['role'], ['agent', 'counter'], true) ? $r['role'] : 'staff']++;
}

/* Money and today's desk figures — one grouped read each. */
$wallet = [];
try {
    foreach (Database::fetchAll(
        'SELECT agent_admin_id AS id, account, COALESCE(SUM(amount), 0) AS bal FROM agent_ledger GROUP BY agent_admin_id, account'
    ) as $w) {
        $wallet[(int) $w['id']][(string) $w['account']] = round((float) $w['bal'], 2);
    }
} catch (Throwable $ex) { $wallet = []; }
$mtd     = AgentTargets::salesBoard(date('Y-m'));
$deskNow = [];
foreach (CommandCenter::counterBoard(todayISO()) as $c) {
    $deskNow[(int) $c['id']] = $c;
}
$desks = CounterDesk::options(true);

/* ---- The tab's slice ------------------------------------------------- */
$list = array_values(array_filter($rows, static function (array $r) use ($tab, $q, $roleF, $showOff): bool {
    if (!$showOff && (int) $r['is_active'] !== 1 && $tab !== 'all') { return false; }
    $role = (string) $r['role'];
    if ($tab === 'agents' && $role !== 'agent') { return false; }
    if ($tab === 'counters' && $role !== 'counter') { return false; }
    if ($tab === 'staff' && in_array($role, ['agent', 'counter'], true)) { return false; }
    if ($tab === 'all' && $roleF !== '' && $role !== $roleF) { return false; }
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [$r['full_name'], $r['username'], $r['phone'], $r['email'], $r['counter_code'], $r['counter_name']]));
        if (!str_contains($hay, mb_strtolower($q))) { return false; }
    }
    return true;
}));
$roles = array_values(array_unique(array_map(static fn(array $r): string => (string) $r['role'], $rows)));
sort($roles);

$csrf = Security::csrfField();
/** The three row actions, posted to staff.php and brought back here. */
$actions = static function (array $r) use ($e, $csrf, $isSuper, $desks, $tab, $admin): string {
    if (!$isSuper) {
        return '<span class="muted" style="font-size:12px">super-admin only</span>';
    }
    $id   = (int) $r['id'];
    $ret  = '<input type="hidden" name="return" value="people"><input type="hidden" name="return_tab" value="' . $e($tab) . '"><input type="hidden" name="id" value="' . $id . '">';
    $self = $id === (int) $admin['id'];
    // 31 Oct 2026 (owner: "user ID, password matra edit huncha — purai details admin le change garna milos"):
    // name, number, code, photo, documents, form, cards, delete — all on the member page.
    $h    = '<div class="pp-acts"><a class="btn soft" href="/admin/team-member.php?id=' . $id . '">✏️ All details</a>';
    if (!$self) {
        $h .= '<form method="post" action="/admin/staff.php" onsubmit="return confirm(\'' . ((int) $r['is_active'] === 1 ? 'Disable' : 'Activate') . ' ' . $e(addslashes((string) ($r['full_name'] ?: $r['username']))) . '?\')">'
            . $csrf . $ret . '<input type="hidden" name="action" value="toggle"><button class="btn ' . ((int) $r['is_active'] === 1 ? 'ghost' : 'ok') . '" type="submit">' . ((int) $r['is_active'] === 1 ? '⏸ Disable' : '▶ Activate') . '</button></form>';
    }
    if ($r['role'] !== 'superadmin' || $self) {
        $h .= '<form method="post" action="/admin/staff.php" onsubmit="var p=prompt(\'New password for ' . $e(addslashes((string) $r['username'])) . ' (8+ characters):\',\'\');if(!p||p.length<8){if(p!==null)alert(\'At least 8 characters.\');return false;}this.password.value=p;return true;">'
            . $csrf . $ret . '<input type="hidden" name="action" value="resetpw"><input type="hidden" name="password" value=""><button class="btn ghost" type="submit">🔑 Password</button></form>';
    }
    $cur = (string) ($r['counter_code'] ?? '');
    $h  .= '<form method="post" action="/admin/staff.php" class="pp-desk">' . $csrf . $ret . '<input type="hidden" name="action" value="setcounter">'
         . '<select name="counter" onchange="this.form.submit()" aria-label="Desk / branch"><option value="">— desk / branch —</option>';
    foreach ($desks as $code => $name) {
        $h .= '<option value="' . $e($code . '|' . $name) . '"' . ($cur === (string) $code ? ' selected' : '') . '>' . $e($name . ' (' . $code . ')') . '</option>';
    }
    if ($cur !== '' && !isset($desks[$cur])) {
        $h .= '<option value="' . $e($cur . '|' . ($r['counter_name'] ?? '')) . '" selected>' . $e(($r['counter_name'] ?: $cur) . ' (' . $cur . ')') . '</option>';
    }
    return $h . '</select></form></div>';
};

admin_header('People', 'people');
?>
<style>
.pp-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.pp-tabs a{display:inline-flex;align-items:center;gap:6px;min-height:46px;padding:0 16px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-weight:700;text-decoration:none}
.pp-tabs a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.pp-tabs .n{font-size:11px;background:var(--orange);color:#fff;border-radius:99px;padding:0 7px;line-height:18px}
.pp-find{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.pp-find input,.pp-find select{min-height:44px;padding:8px 11px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:14px}
.pp-acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.pp-acts form{margin:0}
.pp-acts .btn{min-height:38px;padding:0 10px;font-size:12px}
.pp-desk select{min-height:38px;max-width:190px;padding:4px 8px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:12px}
.pp-off td{opacity:.55}
.pp-st{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800}
.pp-st.open{background:#d7f4e3;color:#0a6b3b}.pp-st.closed{background:#e1ecfb;color:#1f4f96}.pp-st.closed_diff{background:#fde2e1;color:#a12a22}.pp-st.none{background:var(--head);color:var(--mut)}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<nav class="pp-tabs" aria-label="People">
  <?php foreach ($tabs as $key => $label):
    $n = $key === 'agents' ? $byRole['agent'] : ($key === 'counters' ? $byRole['counter'] : ($key === 'staff' ? $byRole['staff'] : array_sum($byRole))); ?>
    <a href="?<?= $e(http_build_query(['tab' => $key])) ?>" class="<?= $key === $tab ? 'on' : '' ?>"><?= $e($label) ?> <span class="n"><?= (int) $n ?></span></a>
  <?php endforeach; ?>
  <a href="/admin/agent-quick.php" style="margin-left:auto">⚡ Quick agent + ID card</a>
  <a href="/admin/staff.php">＋ New login</a>
</nav>

<form method="get" class="pp-find">
  <input type="hidden" name="tab" value="<?= $e($tab) ?>">
  <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Name, login, mobile, desk…" style="flex:1 1 220px">
  <?php if ($tab === 'all'): ?>
    <select name="role"><option value="">Every role</option><?php foreach ($roles as $ro): ?><option value="<?= $e($ro) ?>" <?= $ro === $roleF ? 'selected' : '' ?>><?= $e(ucfirst($ro)) ?></option><?php endforeach; ?></select>
  <?php else: ?>
    <label style="font-size:13px;display:inline-flex;gap:6px;align-items:center;min-height:44px"><input type="checkbox" name="off" value="1" <?= $showOff ? 'checked' : '' ?> onchange="this.form.submit()"> show disabled</label>
  <?php endif; ?>
  <button class="btn" type="submit">Find</button>
</form>

<div class="panel">
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr>
      <th>Name</th>
      <?php if ($tab === 'agents'): ?><th>Code</th><th class="num">Commission due</th><th class="num">Cash held</th><th class="num">Sales this month</th><th>KYC</th>
      <?php elseif ($tab === 'counters'): ?><th>Desk / location</th><th>Shift today</th><th class="num">Tickets</th><th class="num">Cash</th><th class="num">UPI / bank</th>
      <?php else: ?><th>Role</th><th>Desk / branch</th><th>Last sign-in</th><th>Status</th><?php endif; ?>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php if ($list === []): ?>
      <tr><td colspan="7" class="muted" style="padding:20px;text-align:center">Nobody here<?= $q !== '' ? ' for “' . $e($q) . '”' : '' ?>.</td></tr>
    <?php endif; ?>
    <?php foreach ($list as $r):
      $id   = (int) $r['id'];
      $name = (string) ($r['full_name'] ?: $r['username']);
      $desk = (string) ($r['counter_code'] ?? '') !== '' ? CounterDesk::label((string) $r['counter_code'], (string) ($r['counter_name'] ?? '')) : ''; ?>
      <tr class="<?= (int) $r['is_active'] === 1 ? '' : 'pp-off' ?>">
        <td data-label="Name">
          <?php if ($r['role'] === 'agent'): ?><a href="/admin/agent-360.php?agent=<?= $id ?>"><b><?= $e($name) ?></b></a><?php else: ?><b><?= $e($name) ?></b><?php endif; ?>
          <div class="muted mono" style="font-size:11.5px">@<?= $e($r['username']) ?><?= (string) ($r['phone'] ?? '') !== '' ? ' · ' . $e($r['phone']) : '' ?></div>
        </td>
        <?php if ($tab === 'agents'):
          $comm = $wallet[$id]['commission'] ?? 0.0; $cash = $wallet[$id]['cash'] ?? 0.0; ?>
          <td data-label="Code" class="mono"><?= $e(AgentWallet::agentCodeLabel($id) ?: '—') ?></td>
          <td data-label="Commission due" class="num"><?= $e(inr($comm)) ?></td>
          <td data-label="Cash held" class="num" style="color:<?= $cash > 0.009 ? '#b02a2a' : 'inherit' ?>"><?= $e(inr(max(0.0, $cash))) ?></td>
          <td data-label="Sales this month" class="num"><?= $e(inr((float) ($mtd[$id] ?? 0))) ?></td>
          <td data-label="KYC"><?= $e(ucfirst((string) ($r['kyc_status'] ?? 'none'))) ?></td>
        <?php elseif ($tab === 'counters'):
          $dn = $deskNow[$id] ?? null; ?>
          <td data-label="Desk"><?= $desk !== '' ? $e($desk) : '<span class="muted">not assigned</span>' ?></td>
          <td data-label="Shift today"><?php if ($dn !== null): ?><span class="pp-st <?= $e($dn['state']) ?>"><?= $e(['open' => 'Open', 'closed' => 'Closed ✓', 'closed_diff' => 'Closed ≠', 'none' => 'No shift'][$dn['state']] ?? $dn['state']) ?></span> <span class="muted" style="font-size:11.5px"><?= $e($dn['since']) ?></span><?php else: ?><span class="pp-st none">—</span><?php endif; ?></td>
          <td data-label="Tickets" class="num"><?= (int) ($dn['tickets'] ?? 0) ?></td>
          <td data-label="Cash" class="num"><?= $e(inr((float) ($dn['cash'] ?? 0))) ?></td>
          <td data-label="UPI / bank" class="num"><?= $e(inr((float) ($dn['upi'] ?? 0))) ?></td>
        <?php else: ?>
          <td data-label="Role"><?= $e(ucfirst((string) $r['role'])) ?></td>
          <td data-label="Desk / branch"><?= $desk !== '' ? $e($desk) : '<span class="muted">—</span>' ?></td>
          <td data-label="Last sign-in"><?= $r['last_login_at'] ? $e(timeAgo((string) $r['last_login_at'])) : '<span class="muted">never</span>' ?></td>
          <td data-label="Status"><?= (int) $r['is_active'] === 1 ? '<span class="pp-st open">Active</span>' : '<span class="pp-st none">Disabled</span>' ?></td>
        <?php endif; ?>
        <td data-label="Actions"><?= $actions($r) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<p class="muted" style="font-size:12.5px">Every change here goes through <a href="/admin/staff.php">Staff Logins &amp; Passwords</a> — the same super-admin rule and the same activity log. The desk is printed on every ticket the person issues and is their location / branch in reports.</p>
<?php admin_footer();
