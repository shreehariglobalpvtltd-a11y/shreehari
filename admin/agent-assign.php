<?php
/**
 * admin/agent-assign.php — Assign Agents (30 Sep 2026).
 *
 * Every ticket must carry an agent (owner ask: "khali hunu vayna"). One
 * challan-style list per travel date: tick many tickets and move them to one
 * agent, or change a single row's agent in place. Each move goes through
 * AgentWallet::reassignSeller(), so commission follows the agent, cash stays
 * with whoever collected it, and every change is audit-logged. Viewing needs
 * commissions.view; changing needs a super-admin (same gate as booking-view).
 */

declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentwallet.php';
$admin = admin_boot('commissions.view');

if (Auth::isCounterAgent()) {
    admin_header('Assign Agents', 'agent-assign');
    echo '<div class="flash bad">This is an office page.</div>';
    admin_footer();
    exit;
}

$canEdit = Auth::isSuperadmin();
$today   = todayISO();
$isDate  = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

$from    = Security::clean($_GET['from'] ?? $today, 10);
$to      = Security::clean($_GET['to'] ?? $from, 10);
$from    = $isDate($from) ? $from : $today;
$to      = $isDate($to) ? $to : $from;
if ($from > $to) { [$from, $to] = [$to, $from]; }
$routeId = (int) ($_GET['route'] ?? 0);
$only    = (string) ($_GET['only'] ?? 'none');           // none = without an agent · all · agent id
$q       = Security::clean($_GET['q'] ?? '', 60);

/* Agents that may be chosen: active role=agent with an SHG code. */
$agents = [];
foreach (Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name, username") as $a) {
    $label = AgentWallet::agentCodeLabel((int) $a['id']);
    if ($label !== '') {
        $agents[(int) $a['id']] = ['code' => $label, 'name' => (string) ($a['full_name'] ?: $a['username']), 'co' => AgentWallet::isCompanyCode($label)];
    }
}
uasort($agents, static fn($x, $y) => strnatcmp($x['code'], $y['code']));
$companyId = AgentWallet::resolveAgentCodeFromString(Settings::getString('company_agent_code', 'SHG-0001'));

/* ---- Save --------------------------------------------------------------- */
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only a super-admin can change the agent on a ticket.'];
    } else {
        $changes = [];
        foreach ((array) ($_POST['row_agent'] ?? []) as $bid => $aid) {
            $changes[(int) $bid] = (int) $aid;
        }
        $bulkAgent = (int) ($_POST['bulk_agent'] ?? 0);
        if ($bulkAgent > 0) {
            foreach ((array) ($_POST['ids'] ?? []) as $bid) {
                $changes[(int) $bid] = $bulkAgent;
            }
        }
        $moved = 0; $same = 0; $comm = 0.0; $errors = [];
        foreach ($changes as $bid => $aid) {
            if ($bid <= 0 || !isset($agents[$aid])) {
                continue;
            }
            $row = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]);
            if ($row === null) {
                continue;
            }
            if ((int) ($row['sold_by_admin_id'] ?? 0) === $aid) {
                $same++;
                continue;
            }
            try {
                $res = AgentWallet::reassignSeller($row, $aid, (int) $admin['id']);
                $moved++;
                $comm += (float) $res['commission'];
            } catch (Throwable $e) {
                $errors[] = (string) $row['pnr'] . ': ' . $e->getMessage();
            }
        }
        if ($moved === 0 && $errors === []) {
            $flash = ['bad', 'Nothing changed — tick tickets and choose an agent, or change a row\'s agent.'];
        } else {
            $msg = $moved . ' ticket' . ($moved === 1 ? '' : 's') . ' moved to the chosen agent'
                . ($comm > 0 ? ' · commission re-posted ' . inr($comm) : '') . '. Cash-in-hand is not moved.';
            if ($errors !== []) {
                $msg .= ' Not moved: ' . implode('; ', array_slice($errors, 0, 5));
            }
            $flash = [$errors === [] ? 'ok' : 'bad', $msg];
        }
    }
}

/* ---- The list: one row per ticket (booking) on the outbound leg --------- */
$where  = ["bl.leg_type = 'outbound'", 'bl.travel_date BETWEEN :d0 AND :d1', "b.status IN ('confirmed','completed','pending')"];
$params = ['d0' => $from, 'd1' => $to];
if ($routeId > 0) {
    $where[] = 's.route_id = :r';
    $params['r'] = $routeId;
}
if ($only === 'none') {
    $where[] = "(b.sold_by_admin_id IS NULL OR ad.role IS NULL OR ad.role <> 'agent')";
} elseif (ctype_digit($only) && (int) $only > 0) {
    $where[] = 'b.sold_by_admin_id = :ag';
    $params['ag'] = (int) $only;
}
if ($q !== '') {
    $where[] = '(b.pnr LIKE :q OR b.contact_phone LIKE :q OR EXISTS (SELECT 1 FROM booking_passengers x WHERE x.booking_id = b.id AND x.full_name LIKE :q))';
    $params['q'] = '%' . $q . '%';
}

$rows = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.source, b.total_amount, b.contact_phone, b.sold_by_admin_id,
            ad.full_name AS agent_name, ad.username AS agent_user, ad.role AS agent_role,
            bl.travel_date, bl.boarding_stop, r.from_city, r.to_city,
            (SELECT GROUP_CONCAT(bp.seat_no ORDER BY LENGTH(bp.seat_no), bp.seat_no SEPARATOR ', ')
               FROM booking_passengers bp WHERE bp.booking_id = b.id AND bp.leg_id = bl.id) AS seats,
            (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id AND bp.leg_id = bl.id) AS pax,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id
              ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS pname
       FROM bookings b
       JOIN booking_legs bl ON bl.booking_id = b.id
       JOIN schedules s     ON s.id = bl.schedule_id
       JOIN routes r        ON r.id = s.route_id
       LEFT JOIN admins ad  ON ad.id = b.sold_by_admin_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY bl.travel_date, r.id, b.id
      LIMIT 500",
    $params
);

$routes = Database::fetchAll('SELECT id, from_city, to_city FROM routes ORDER BY id');

$who = static function (array $r): string {
    if (!empty($r['sold_by_admin_id'])) {
        $ib = Ticket::issuedBy($r);
        return trim($ib['code'] . ' · ' . ($r['agent_name'] ?: $r['agent_user']));
    }
    $src = strtolower((string) ($r['source'] ?? 'web'));
    return $src === 'admin' ? 'OFFICE' : ($src === 'counter' ? 'COUNTER' : 'ONLINE');
};

/* Per-agent totals of the listed tickets (the turnover for this window). */
$byAgent = [];
foreach ($rows as $r) {
    $key = ($r['agent_role'] ?? '') === 'agent' ? $who($r) : '— without agent —';
    $byAgent[$key] ??= ['tickets' => 0, 'seats' => 0, 'amount' => 0.0];
    $byAgent[$key]['tickets']++;
    $byAgent[$key]['seats']  += (int) $r['pax'];
    $byAgent[$key]['amount'] += $r['status'] === 'pending' ? 0.0 : (float) $r['total_amount'];
}
ksort($byAgent, SORT_NATURAL);

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$agentOptions = static function (int $selected) use ($agents, $companyId): string {
    $html = '';
    foreach ($agents as $id => $a) {
        $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>'
            . Security::e(($a['co'] ? '🏢 ' : '👤 ') . $a['code'] . ' · ' . $a['name'] . ($id === $companyId ? ' (company / Other)' : '')) . '</option>';
    }
    return $html;
};

admin_header('Assign Agents', 'agent-assign');
admin_page_head('हरेक टिकटमा एजेन्ट · Every ticket carries an agent', ['Agents' => '/admin/agents.php']);
?>
<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<form class="toolbar" method="get">
  <label>From <input type="date" name="from" value="<?= Security::e($from) ?>"></label>
  <label>To <input type="date" name="to" value="<?= Security::e($to) ?>"></label>
  <select name="route">
    <option value="0">All routes</option>
    <?php foreach ($routes as $rt): ?>
      <option value="<?= (int) $rt['id'] ?>" <?= (int) $rt['id'] === $routeId ? 'selected' : '' ?>><?= Security::e($rt['from_city'] . ' → ' . $rt['to_city']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="only">
    <option value="none" <?= $only === 'none' ? 'selected' : '' ?>>Without an agent (OFFICE / COUNTER / ONLINE)</option>
    <option value="all" <?= $only === 'all' ? 'selected' : '' ?>>All tickets</option>
    <?php foreach ($agents as $id => $a): ?>
      <option value="<?= $id ?>" <?= $only === (string) $id ? 'selected' : '' ?>><?= Security::e($a['code'] . ' · ' . $a['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="PNR / mobile / name">
  <button class="btn" type="submit">Show</button>
</form>

<?php if ($agents === []): ?>
  <div class="flash bad">No active agent has an SHG code yet — give agents their codes under Staff &amp; Approvals first.</div>
<?php endif; ?>

<form method="post" id="assignForm">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">

  <?php if ($canEdit && $rows !== [] && $agents !== []): ?>
  <div class="toolbar" style="position:sticky;top:0;z-index:5;background:var(--card,#fff)">
    <b id="selCount">0 selected</b>
    <span>→ assign to</span>
    <select name="bulk_agent" id="bulkAgent">
      <option value="0">— choose agent —</option>
      <?= $agentOptions($companyId ?? 0) ?>
    </select>
    <button class="btn btn-ok" type="submit" onclick="return confirmSave()">💾 Save changes</button>
    <span class="muted" style="font-size:12px">Commission moves to the new agent; cash stays with whoever collected it. Every change is logged.</span>
  </div>
  <?php elseif (!$canEdit): ?>
    <div class="flash">View only — a super-admin can change agents here.</div>
  <?php endif; ?>

  <div class="panel" style="overflow-x:auto">
    <table>
      <thead><tr>
        <?php if ($canEdit): ?><th style="width:34px"><input type="checkbox" id="checkAll" aria-label="Select all"></th><?php endif; ?>
        <th>#</th><th>Date</th><th>Seats</th><th>Passenger</th><th>PNR</th><th>Route · Boarding</th>
        <th style="text-align:right">Amount</th><th>Agent</th>
      </tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="9" class="muted" style="padding:22px;text-align:center">
          <?= $only === 'none' ? '✅ Every ticket in this window already has an agent.' : 'No tickets in this window.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $i => $r):
          $bid     = (int) $r['id'];
          $current = ($r['agent_role'] ?? '') === 'agent' ? (int) $r['sold_by_admin_id'] : 0; ?>
        <tr>
          <?php if ($canEdit): ?><td><input type="checkbox" name="ids[]" value="<?= $bid ?>" class="rowCheck"></td><?php endif; ?>
          <td><?= $i + 1 ?></td>
          <td style="white-space:nowrap"><?= Security::e(formatDate((string) $r['travel_date'])) ?></td>
          <td><b><?= Security::e((string) $r['seats']) ?></b></td>
          <td><?= Security::e((string) $r['pname']) ?><?= (int) $r['pax'] > 1 ? ' <span class="muted">+' . ((int) $r['pax'] - 1) . '</span>' : '' ?>
            <div class="muted" style="font-size:11px"><?= Security::e((string) $r['contact_phone']) ?></div></td>
          <td><a href="/admin/booking-view.php?pnr=<?= Security::e(rawurlencode((string) $r['pnr'])) ?>"><?= Security::e((string) $r['pnr']) ?></a>
            <?= $r['status'] === 'pending' ? '<div class="muted" style="font-size:11px">pending</div>' : '' ?></td>
          <td><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?>
            <div class="muted" style="font-size:11px"><?= Security::e((string) $r['boarding_stop']) ?></div></td>
          <td style="text-align:right;white-space:nowrap"><?= Security::e(inr((float) $r['total_amount'])) ?></td>
          <td>
            <?php if ($canEdit && $agents !== []): ?>
              <select name="row_agent[<?= $bid ?>]" class="rowAgent" data-orig="<?= $current ?>" style="min-width:180px<?= $current === 0 ? ';border-color:#e53e3e' : '' ?>">
                <?php if ($current === 0): ?><option value="0" selected><?= Security::e($who($r)) ?> — choose agent</option><?php endif; ?>
                <?= $agentOptions($current) ?>
              </select>
            <?php else: ?>
              <?= Security::e($who($r)) ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (count($rows) === 500): ?><p class="muted">Showing the first 500 tickets — narrow the dates or route.</p><?php endif; ?>
  </div>
</form>

<?php if ($byAgent !== []): ?>
<div class="panel">
  <h2>📊 Agent-wise total · एजेन्ट अनुसार हिसाब (<?= Security::e(formatDate($from)) ?><?= $to !== $from ? ' — ' . Security::e(formatDate($to)) : '' ?>)</h2>
  <table>
    <thead><tr><th>Agent</th><th style="text-align:right">Tickets</th><th style="text-align:right">Seats</th><th style="text-align:right">Amount (confirmed)</th></tr></thead>
    <tbody>
    <?php foreach ($byAgent as $name => $t): ?>
      <tr><td><?= Security::e($name) ?></td><td style="text-align:right"><?= $t['tickets'] ?></td>
          <td style="text-align:right"><?= $t['seats'] ?></td><td style="text-align:right"><?= Security::e(inr($t['amount'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="font-size:12px">Full statements, payouts and balances per agent: <a href="/admin/agents.php">Agents</a> → Agent 360.</p>
</div>
<?php endif; ?>

<script>
(function () {
  var all = document.getElementById('checkAll');
  var checks = Array.prototype.slice.call(document.querySelectorAll('.rowCheck'));
  var count = document.getElementById('selCount');
  function upd() { if (count) count.textContent = checks.filter(function (c) { return c.checked; }).length + ' selected'; }
  if (all) all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); upd(); });
  checks.forEach(function (c) { c.addEventListener('change', upd); });
  window.confirmSave = function () {
    var n = checks.filter(function (c) { return c.checked; }).length;
    var b = document.getElementById('bulkAgent');
    var edits = Array.prototype.slice.call(document.querySelectorAll('.rowAgent')).filter(function (s) {
      var c = s.closest('tr').querySelector('.rowCheck');
      return s.value !== s.getAttribute('data-orig') && !(c && c.checked);
    }).length;
    if (n > 0 && (!b || b.value === '0')) { alert('Choose the agent for the ticked tickets.'); return false; }
    if (n === 0 && edits === 0) { alert('Tick tickets or change a row’s agent first.'); return false; }
    var total = n + edits;
    return confirm('Move ' + total + ' ticket(s) to the chosen agent? Commission moves with them.');
  };
})();
</script>
<?php admin_footer(); ?>
