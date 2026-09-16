<?php
/**
 * admin/activity-log.php — the audit trail.
 *
 * Every privileged action already writes to `audit_logs` through
 * Logger::audit(); until now nothing read it back, so the record existed but
 * could not be inspected without a database client. This is that viewer.
 *
 * Two lenses over one table:
 *
 *   ?view=activity — everything: who changed what, and what it changed from.
 *   ?view=security — sign-ins, sign-outs, staff and permission changes.
 *                    The subset you look at after something goes wrong,
 *                    kept separate so it is not buried under booking noise.
 *
 * Read-only by construction: there is no write path here, and the audit
 * trail must not be editable from the application that produces it.
 *
 * Gated on dashboard.view — this is a company-wide record and must stay out
 * of the agent portal.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$today = todayISO();

$view = (string) ($_GET['view'] ?? 'activity');
if (!in_array($view, ['activity', 'security'], true)) {
    $view = 'activity';
}

/* Actions that belong to the security lens. Prefix-matched, so a new
   staff.* or admin.login* action is covered the day it is added rather
   than silently missing from the security view. */
const SECURITY_PREFIXES = ['admin.login', 'admin.logout', 'staff.', 'customer.block', 'customer.unblock', 'settings.update',
    // 4 Sep 2026: agents now sign in with email + username + password, so who
    // changed those credentials — and every agent sign-in — belongs in the
    // security lens beside the office ones.
    'agent.credentials', 'agent.login'];

$q      = Security::clean($_GET['q'] ?? '', 60);
$actor  = Security::clean($_GET['actor'] ?? '', 20);
$action = Security::clean($_GET['action'] ?? '', 80);
$date   = Security::clean($_GET['date'] ?? '', 10);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = '';
}

/* SHG AI BRAIN 1.6 audit report (10 Sep 2026): a date RANGE, one booking's
   whole history, and a CSV of exactly what is on screen — one row per changed
   field with old → new, who (and as which role), why, and from where. */
$from    = Security::clean($_GET['from'] ?? '', 10);
$to      = Security::clean($_GET['to'] ?? '', 10);
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) { $from = ''; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1)   { $to = ''; }
$booking = strtoupper(Security::clean($_GET['booking'] ?? '', 30));
$format  = ($_GET['format'] ?? '') === 'csv' ? 'csv' : 'html';
/* reason / actor_role arrive with upgrade-2026-09-challan-png.sql; read them
   only once the columns exist so the page keeps working on an older schema. */
$hasReason = false;
try {
    $hasReason = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'reason'", [], 0) > 0;
} catch (Throwable $e) { $hasReason = false; }
$extraCols = $hasReason ? ', a.reason, a.actor_role' : '';

$page    = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 60;

$where  = [];
$params = [];

if ($view === 'security') {
    $arms = [];
    foreach (SECURITY_PREFIXES as $i => $prefix) {
        $arms[]              = 'a.action LIKE :sec' . $i;
        $params['sec' . $i]  = $prefix . '%';
    }
    $where[] = '(' . implode(' OR ', $arms) . ')';
}

if ($q !== '') {
    $where[] = sqlSearchClause([
        'a.actor_name LIKE %s',
        'a.action LIKE %s',
        'a.entity_id LIKE %s',
        'a.detail LIKE %s',
        'a.ip_address LIKE %s',
    ], $q, $params);
}
if (in_array($actor, ['admin', 'user', 'system', 'cron'], true)) {
    $where[]         = 'a.actor_type = :at';
    $params['at']    = $actor;
}
if ($action !== '') {
    $where[]         = 'a.action = :act';
    $params['act']   = $action;
}
if ($date !== '') {
    $where[]         = 'a.created_at >= :d0 AND a.created_at < :d1';
    $params['d0']    = $date;
    $params['d1']    = addDaysISO($date, 1);
}

if ($from !== '') { $where[] = 'a.created_at >= :f0'; $params['f0'] = $from; }
if ($to !== '')   { $where[] = 'a.created_at < :t1';  $params['t1'] = addDaysISO($to, 1); }
if ($booking !== '') {
    $where[]      = "a.entity_type = 'booking' AND a.entity_id = :bk";
    $params['bk'] = $booking;
}
$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a" . $whereSql, $params, 0);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

// LIMIT/OFFSET are integers cast from the request, never bound strings —
// binding them as parameters makes MySQL quote them and the statement fail.
$rows = Database::fetchAll(
    "SELECT a.id, a.actor_type, a.actor_id, a.actor_name, a.action,
            a.entity_type, a.entity_id, a.old_value, a.new_value,
            a.detail, a.ip_address, a.created_at" . $extraCols . "
       FROM audit_logs a" . $whereSql . "
      ORDER BY a.id DESC
      LIMIT " . ($format === 'csv' ? '5000' : $perPage . ' OFFSET ' . $offset),
    $params
);

/* Distinct actions for the filter dropdown, within the current lens. */
$actionList = Database::fetchAll(
    "SELECT DISTINCT action FROM audit_logs a"
    . ($view === 'security' ? ' WHERE (' . implode(' OR ', array_map(
        static fn(int $i): string => 'a.action LIKE :sec' . $i,
        array_keys(SECURITY_PREFIXES)
      )) . ')' : '')
    . ' ORDER BY action',
    $view === 'security'
        ? array_filter($params, static fn(string $k): bool => str_starts_with($k, 'sec'), ARRAY_FILTER_USE_KEY)
        : []
);

/** action → [icon, colour]. Unknown actions still render, just unstyled. */
function audit_look(string $action): array
{
    return match (true) {
        str_starts_with($action, 'admin.login')  => ['🔑', '#2E5FA8'],
        str_starts_with($action, 'admin.logout') => ['🚪', '#6b7688'],
        str_starts_with($action, 'staff.')       => ['🧑‍💼', '#7a4a00'],
        str_starts_with($action, 'customer.')    => ['👤', '#7a4a00'],
        str_starts_with($action, 'settings.')    => ['⚙️', '#b02a2a'],
        str_starts_with($action, 'booking.')     => ['🎫', '#0a6b3b'],
        str_starts_with($action, 'payment.')     => ['💳', '#0a6b3b'],
        str_starts_with($action, 'refund.')      => ['💰', '#b02a2a'],
        str_starts_with($action, 'seat.')        => ['💺', '#2E5FA8'],
        str_starts_with($action, 'agent.')       => ['🎟️', '#7a4a00'],
        str_starts_with($action, 'ticket.')      => ['📄', '#0a6b3b'],
        str_starts_with($action, 'trip.')        => ['🚌', '#2E5FA8'],
        str_starts_with($action, 'daily.')       => ['🔁', '#2E5FA8'],
        str_starts_with($action, 'day.')         => ['📅', '#7a4a00'],
        default                                  => ['•', '#6b7688'],
    };
}

/** Compact "field: old → new" summary from the JSON snapshots. */
function audit_diff(?string $old, ?string $new): string
{
    $o = $old !== null && $old !== '' ? jsonColumn($old) : [];
    $n = $new !== null && $new !== '' ? jsonColumn($new) : [];

    if ($o === [] && $n === []) {
        return '';
    }

    $keys  = array_slice(array_unique(array_merge(array_keys($o), array_keys($n))), 0, 4);
    $parts = [];

    foreach ($keys as $k) {
        $ov = $o[$k] ?? null;
        $nv = $n[$k] ?? null;
        if ($ov === $nv) {
            continue;
        }
        $fmt = static function ($v): string {
            if ($v === null) { return '—'; }
            if (is_bool($v)) { return $v ? 'true' : 'false'; }
            if (is_scalar($v)) { return truncate((string) $v, 24); }
            return '…';
        };
        $parts[] = Security::e((string) $k) . ': <span class="muted">' . Security::e($fmt($ov))
                 . '</span> → <strong>' . Security::e($fmt($nv)) . '</strong>';
    }

    return implode('<br>', $parts);
}

$carry = static function (array $over = []) use ($view, $q, $actor, $action, $date, $from, $to, $booking): string {
    return http_build_query(array_filter(
        array_merge(['view' => $view, 'q' => $q, 'actor' => $actor, 'action' => $action, 'date' => $date,
                     'from' => $from, 'to' => $to, 'booking' => $booking], $over),
        static fn($v) => $v !== '' && $v !== null
    ));
};

/** Every changed field as [field, old, new] — the CSV's one-row-per-change shape. */
function audit_pairs(?string $old, ?string $new): array
{
    $o = $old !== null && $old !== '' ? jsonColumn($old) : [];
    $n = $new !== null && $new !== '' ? jsonColumn($new) : [];
    $fmt = static function ($v): string {
        if ($v === null) { return ''; }
        if (is_bool($v)) { return $v ? 'true' : 'false'; }
        if (is_scalar($v)) { return (string) $v; }
        return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
    };
    $out = [];
    foreach (array_unique(array_merge(array_keys($o), array_keys($n))) as $k) {
        if (($o[$k] ?? null) === ($n[$k] ?? null)) { continue; }
        $out[] = [(string) $k, $fmt($o[$k] ?? null), $fmt($n[$k] ?? null)];
    }
    return $out;
}

if ($format === 'csv') {
    /* The audit report the master prompt asks for (§1.6): Date | Booking |
       Edited by | Field | Old | New | Reason — exactly the filtered rows, one
       line per changed field, so a spreadsheet can sort by field or desk. */
    Logger::audit('audit.export', 'audit_logs', '', null,
        ['rows' => count($rows), 'view' => $view, 'q' => $q, 'action' => $action, 'booking' => $booking, 'from' => $from, 'to' => $to, 'date' => $date],
        'audit CSV by admin #' . (int) $admin['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-' . ($booking !== '' ? $booking . '-' : '') . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 (Devanagari names) correctly
    fputcsv($out, ['Date', 'Time', 'Action', 'Edited by', 'Role', 'Actor type', 'Target', 'Booking / ID', 'Field', 'Old value', 'New value', 'Reason', 'Detail', 'IP']);
    foreach ($rows as $r) {
        $pairs = audit_pairs($r['old_value'] ?? null, $r['new_value'] ?? null);
        if ($pairs === []) { $pairs = [['', '', '']]; }
        $ts = strtotime((string) $r['created_at']) ?: 0;
        foreach ($pairs as [$field, $ov, $nv]) {
            fputcsv($out, [
                date('Y-m-d', $ts), date('H:i:s', $ts), (string) $r['action'],
                (string) ($r['actor_name'] ?? ''), (string) ($r['actor_role'] ?? ''), (string) $r['actor_type'],
                (string) ($r['entity_type'] ?? ''), (string) ($r['entity_id'] ?? ''),
                $field, $ov, $nv, (string) ($r['reason'] ?? ''), (string) ($r['detail'] ?? ''), (string) ($r['ip_address'] ?? ''),
            ]);
        }
    }
    fclose($out);
    exit;
}

admin_header($view === 'security' ? 'Security Log' : 'Activity Log', 'activity-log');
?>
<div class="toolbar">
  <a class="btn<?= $view === 'activity' ? '' : ' ghost' ?>" href="<?= $base ?>/admin/activity-log.php?view=activity">📋 Activity log</a>
  <a class="btn<?= $view === 'security' ? '' : ' ghost' ?>" href="<?= $base ?>/admin/activity-log.php?view=security">🔒 Security log</a>
  <?php /* 4 Sep 2026: the two records the owner asked to be able to pull up on
           their own — every bulk cancellation, and every bus added or edited.
           Plain pre-filtered links over the same table, no new storage. */ ?>
  <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?view=activity&amp;action=booking.bulk_cancel"
     title="Every bulk cancellation: who, how many, which tickets and why">✖ Bulk cancels</a>
  <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?view=activity&amp;q=trip."
     title="Buses added, retimed, repriced, re-seated, blocked or cancelled">🚌 Bus changes</a>
  <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?view=activity&amp;q=booking.edit"
     title="Every passenger / contact / pickup edit with old → new, who, and the reason typed">✏️ Ticket edits</a>
</div>

<form class="toolbar" method="get">
  <input type="hidden" name="view" value="<?= Security::e($view) ?>">
  <input type="search" name="q" placeholder="Who, what, IP or reference" value="<?= Security::e($q) ?>" style="min-width:230px">
  <select name="actor">
    <option value="">All actors</option>
    <?php foreach (['admin' => 'Staff', 'user' => 'Customer', 'system' => 'System', 'cron' => 'Scheduled'] as $k => $lbl): ?>
      <option value="<?= $k ?>" <?= $actor === $k ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <select name="action">
    <option value="">All actions</option>
    <?php foreach ($actionList as $a): ?>
      <option value="<?= Security::e((string) $a['action']) ?>" <?= $action === (string) $a['action'] ? 'selected' : '' ?>>
        <?= Security::e((string) $a['action']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="booking" value="<?= Security::e($booking) ?>" placeholder="Booking PNR" style="width:130px" title="Every change made to one booking">
  <input type="date" name="date" value="<?= Security::e($date) ?>" title="On this date">
  <input type="date" name="from" value="<?= Security::e($from) ?>" title="From date">
  <input type="date" name="to" value="<?= Security::e($to) ?>" title="To date">
  <button class="btn" type="submit">Filter</button>
  <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?<?= Security::e($carry(['format' => 'csv'])) ?>" title="Exactly what is filtered here, one row per changed field: old → new, who, role, reason, IP">⬇️ Export CSV</a>
  <?php if ($q !== '' || $actor !== '' || $action !== '' || $date !== '' || $from !== '' || $to !== '' || $booking !== ''): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?view=<?= Security::e($view) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="panel">
  <h2><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?>
    <?php if ($pages > 1): ?>
      <span class="muted" style="font-weight:400">· page <?= $page ?> of <?= $pages ?></span>
    <?php endif; ?>
  </h2>
  <table>
    <thead><tr><th style="width:150px">When</th><th>Action</th><th>Who</th><th>Target</th><th>Change · reason · detail</th><th style="width:110px">IP</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="6" class="muted" style="padding:22px;text-align:center">
        Nothing recorded<?= $q !== '' || $date !== '' ? ' for this filter' : ' yet' ?>.
      </td></tr>
    <?php else: foreach ($rows as $r):
      [$icon, $colour] = audit_look((string) $r['action']);
      $diff = audit_diff($r['old_value'] ?? null, $r['new_value'] ?? null);
    ?>
      <tr>
        <td class="muted" style="font-size:12px">
          <?= Security::e(timeAgo((string) $r['created_at'])) ?>
          <div style="font-size:11px;opacity:.75"><?= Security::e(date('j M, H:i', strtotime((string) $r['created_at']))) ?></div>
        </td>
        <td><span style="color:<?= $colour ?>;font-weight:700"><?= $icon ?> <?= Security::e((string) $r['action']) ?></span></td>
        <td>
          <?= Security::e((string) ($r['actor_name'] ?: '—')) ?>
          <div class="muted" style="font-size:11px"><?= Security::e(ucfirst((string) $r['actor_type'])) ?><?= !empty($r['actor_role']) ? ' · ' . Security::e((string) $r['actor_role']) : '' ?></div>
        </td>
        <td class="mono" style="font-size:12px">
          <?php if (!empty($r['entity_type'])): ?>
            <?= Security::e((string) $r['entity_type']) ?>
            <?php if (!empty($r['entity_id'])): ?>
              <div class="muted"><?= Security::e(truncate((string) $r['entity_id'], 24)) ?></div>
            <?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?= $diff ?: '' ?>
          <?php if (!empty($r['reason'])): ?>
            <div style="margin-top:4px;font-size:12px"><strong>Reason:</strong> <?= Security::e(truncate((string) $r['reason'], 120)) ?></div>
          <?php endif; ?>
          <?php if (!empty($r['detail'])): ?>
            <div class="muted"<?= ($diff || !empty($r['reason'])) ? ' style="margin-top:4px"' : '' ?>><?= Security::e(truncate((string) $r['detail'], 90)) ?></div>
          <?php elseif (!$diff && empty($r['reason'])): ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="mono muted" style="font-size:11px"><?= Security::e((string) ($r['ip_address'] ?: '—')) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
<div class="toolbar">
  <?php if ($page > 1): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?<?= Security::e($carry(['p' => $page - 1])) ?>">← Newer</a>
  <?php endif; ?>
  <span class="muted" style="font-size:12px">Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/activity-log.php?<?= Security::e($carry(['p' => $page + 1])) ?>">Older →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="muted" style="font-size:12.5px">
  Written by <span class="mono">Logger::audit()</span> as actions happen. This page is read-only — the trail
  cannot be edited or cleared from the admin panel, which is what makes it worth keeping.
</p>
<?php
admin_footer();
