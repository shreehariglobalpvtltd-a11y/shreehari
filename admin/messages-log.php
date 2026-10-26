<?php
/**
 * admin/messages-log.php — the outbound message log (SMS / WhatsApp / email).
 * Every automatic send is recorded in message_logs with recipient, body,
 * provider message id and any error, so staff can confirm delivery and debug
 * failures. Read-only. Gated to roles that can see the dashboard.
 *
 * 17 Sep 2026: the office's one-click WhatsApp sends (statements, reminders,
 * receipts, ticket re-sends — admin/api/wa-send.php) land here too, carrying
 * a `purpose`, the staff member who pressed send and the agent addressed.
 * The page filters on those, links each row to its booking / agent, and a
 * counter agent only ever sees rows about themselves or their own sales.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/watemplates.php';
$admin = admin_boot('dashboard.view');

$channel = strtolower(Security::clean($_GET['channel'] ?? '', 12));
$status  = strtolower(Security::clean($_GET['status'] ?? '', 12));
$purpose = strtolower(Security::clean($_GET['purpose'] ?? '', 40));
$agentQ  = (int) ($_GET['agent'] ?? 0);
$q       = Security::clean($_GET['q'] ?? '', 60);
$channel = in_array($channel, ['sms', 'whatsapp', 'email'], true) ? $channel : '';
$status  = in_array($status, ['sent', 'failed', 'skipped', 'queued'], true) ? $status : '';
$purposes = ['ticket' => 'Ticket (automatic)'] + array_map(static fn(array $r): string => (string) $r['label'], WaTemplates::REGISTRY);
$purpose = isset($purposes[$purpose]) ? $purpose : '';

/* Which of the new columns exist (upgrade-2026-09-wa-templates.sql). */
$hasPurpose = false;
try {
    $hasPurpose = (int) Database::scalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs' AND COLUMN_NAME = 'purpose'", [], 0) > 0;
} catch (Throwable $e) {
}

$scopeId = Auth::bookingScopeAdminId();
$where   = [];
$params  = [];
if ($channel !== '') { $where[] = 'm.channel = :c'; $params['c'] = $channel; }
if ($status !== '')  { $where[] = 'm.status = :s';  $params['s'] = $status; }
if ($hasPurpose && $purpose !== '') {
    if ($purpose === 'ticket') { $where[] = "(m.purpose IS NULL OR m.purpose = 'ticket')"; }
    else { $where[] = 'm.purpose = :p'; $params['p'] = $purpose; }
}
if ($hasPurpose && $agentQ > 0) { $where[] = 'm.agent_admin_id = :ag'; $params['ag'] = $agentQ; }
if ($q !== '') {
    $where[] = '(m.to_number LIKE :q1 OR m.body LIKE :q2 OR b.pnr LIKE :q3)';
    $params['q1'] = '%' . $q . '%'; $params['q2'] = '%' . $q . '%'; $params['q3'] = '%' . $q . '%';
}
if ($scopeId !== null) {
    // A counter agent: their own messages, or messages about their sales.
    $where[] = $hasPurpose
        ? '(m.agent_admin_id = :sc1 OR b.sold_by_admin_id = :sc2)'
        : 'b.sold_by_admin_id = :sc2';
    if ($hasPurpose) { $params['sc1'] = $scopeId; }
    $params['sc2'] = $scopeId;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$tableExists = true;
$rows        = [];
$counts      = ['total' => 0, 'sent' => 0, 'failed' => 0, 'today' => 0];
try {
    $cols = 'm.id, m.booking_id, m.channel, m.provider, m.to_number, m.body, m.status, m.provider_ref, m.error, m.created_at,
             b.pnr, b.sold_by_admin_id'
          . ($hasPurpose ? ', m.purpose, m.admin_id, m.agent_admin_id, m.media_url, a.full_name AS admin_name, g.full_name AS agent_name' : '');
    $rows = Database::fetchAll(
        'SELECT ' . $cols . '
           FROM message_logs m
           LEFT JOIN bookings b ON b.id = m.booking_id'
        . ($hasPurpose ? ' LEFT JOIN admins a ON a.id = m.admin_id LEFT JOIN admins g ON g.id = m.agent_admin_id' : '')
        . $whereSql . '
          ORDER BY m.id DESC
          LIMIT 300',
        $params
    );
    $counts['total']  = (int) Database::scalar('SELECT COUNT(*) FROM message_logs', [], 0);
    $counts['sent']   = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE status = 'sent'", [], 0);
    $counts['failed'] = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE status = 'failed'", [], 0);
    $counts['today']  = (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE created_at >= :d', ['d' => todayISO() . ' 00:00:00'], 0);
} catch (Throwable $e) {
    $tableExists = false;
}

$agents = [];
if ($scopeId === null && $hasPurpose) {
    try {
        $agents = Database::fetchAll("SELECT id, full_name, username FROM admins WHERE role = 'agent' ORDER BY full_name");
    } catch (Throwable $e) {
    }
}

/** Channel / status → pill. */
function msglog_pill(string $kind): string
{
    $map = [
        'sms' => 'st-info', 'whatsapp' => 'st-wa', 'email' => 'st-violet',
        'sent' => 'st-ok', 'failed' => 'st-bad', 'skipped' => 'st-muted', 'queued' => 'st-warn',
    ];
    $label = $kind === 'sms' ? 'SMS' : ($kind === 'whatsapp' ? 'WhatsApp' : ucfirst($kind));
    return '<span class="pill ' . ($map[$kind] ?? 'st-muted') . '">' . Security::e($label) . '</span>';
}

admin_header('Message Log', 'messages-log');
admin_page_head('Every SMS, WhatsApp and email the system or the office sent — with delivery status, who sent it and what it was for.');
?>
<?php if (!$tableExists): ?>
  <div class="flash bad">
    The <span class="mono">message_logs</span> table does not exist yet. Run
    <span class="mono">database/upgrade-2026-08-sms-gateway.sql</span> once, then reload.
  </div>
<?php else: ?>

<div class="kpis">
  <?= admin_kpi('Sent today', (string) $counts['today'], 'all channels', 'send', 'blue') ?>
  <?= admin_kpi('Delivered / accepted', (string) $counts['sent'], 'lifetime', 'check-circle', 'green') ?>
  <?= admin_kpi('Failed', (string) $counts['failed'], 'lifetime · retried for tickets', 'alert', $counts['failed'] > 0 ? 'red' : 'teal') ?>
  <?= admin_kpi('All messages', (string) $counts['total'], 'lifetime', 'msg', 'navy') ?>
</div>

<?php if (!$hasPurpose): ?>
  <div class="flash info"><svg class="a-ic"><use href="#a-info"/></svg>Purpose / sender columns are not migrated yet — run <span class="mono">database/upgrade-2026-09-wa-templates.sql</span> once to filter by message type and agent.</div>
<?php endif; ?>

<form method="get" class="toolbar">
  <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="Phone · PNR · text" style="min-width:200px">
  <select name="channel">
    <option value="">All channels</option>
    <?php foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $channel === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped / on phone', 'queued' => 'Queued'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($hasPurpose): ?>
  <select name="purpose">
    <option value="">All message types</option>
    <?php foreach ($purposes as $v => $l): ?>
      <option value="<?= Security::e($v) ?>" <?= $purpose === $v ? 'selected' : '' ?>><?= Security::e($l) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($agents !== []): ?>
  <select name="agent">
    <option value="">All agents</option>
    <?php foreach ($agents as $ag): ?>
      <option value="<?= (int) $ag['id'] ?>" <?= $agentQ === (int) $ag['id'] ? 'selected' : '' ?>><?= Security::e((string) ($ag['full_name'] ?: $ag['username'])) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php endif; ?>
  <button class="btn ghost" type="submit"><svg class="a-ic"><use href="#a-filter"/></svg>Filter</button>
  <span class="muted text-xs">Showing latest <?= count($rows) ?> (max 300)</span>
</form>

<div class="panel">
  <div class="tbl-scroll">
  <table>
    <thead>
      <tr>
        <th style="width:140px">When</th>
        <th>Channel</th>
        <?php if ($hasPurpose): ?><th>Type</th><?php endif; ?>
        <th>To</th>
        <th>Message</th>
        <th>Status</th>
        <th>Ref / Error</th>
        <?php if ($hasPurpose): ?><th>By</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="<?= $hasPurpose ? 8 : 6 ?>"><?= admin_empty('No messages match', 'Try a wider filter.', '💬') ?></td></tr>
      <?php else: foreach ($rows as $r):
        $pur = $hasPurpose ? (string) ($r['purpose'] ?? '') : '';
        $purLabel = $pur === '' ? ($r['booking_id'] ? 'Ticket / booking' : '—') : ($purposes[$pur] ?? ucfirst(str_replace('_', ' ', $pur)));
        $pnr = (string) ($r['pnr'] ?? '');
      ?>
        <tr>
          <td class="mono muted text-xs"><?= Security::e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td><?= msglog_pill((string) $r['channel']) ?></td>
          <?php if ($hasPurpose): ?>
            <td class="text-sm"><?= Security::e($purLabel) ?>
              <?php if (!empty($r['agent_name'])): ?><div class="muted text-xs">→ <a href="<?= '/admin/agent-360.php?agent=' . (int) $r['agent_admin_id'] ?>"><?= Security::e((string) $r['agent_name']) ?></a></div><?php endif; ?>
            </td>
          <?php endif; ?>
          <td class="mono text-sm">+<?= Security::e(ltrim((string) $r['to_number'], '+')) ?>
            <?php if ($pnr !== ''): ?><div class="text-xs"><a href="/admin/booking-view.php?pnr=<?= urlencode($pnr) ?>"><?= Security::e($pnr) ?></a></div><?php endif; ?>
          </td>
          <td style="max-width:360px"><span class="muted text-sm" title="<?= Security::e((string) ($r['body'] ?? '')) ?>"><?= Security::e(mb_strimwidth((string) ($r['body'] ?? ''), 0, 140, '…')) ?></span>
            <?php if ($hasPurpose && !empty($r['media_url'])): ?><div><a class="text-xs" href="<?= Security::e((string) $r['media_url']) ?>" target="_blank" rel="noopener">📎 attachment</a></div><?php endif; ?>
          </td>
          <td><?= msglog_pill((string) $r['status']) ?><?php if ((string) $r['provider'] === 'manual'): ?><div class="muted text-xs">on staff phone</div><?php endif; ?></td>
          <td class="mono muted text-xs" style="max-width:220px;white-space:normal">
            <?php if (!empty($r['error'])): ?>
              <span style="color:var(--bad)"><?= Security::e((string) $r['error']) ?></span>
            <?php else: ?>
              <?= Security::e((string) $r['provider_ref']) ?: '—' ?>
            <?php endif; ?>
          </td>
          <?php if ($hasPurpose): ?><td class="text-sm"><?= !empty($r['admin_name']) ? Security::e((string) $r['admin_name']) : '<span class="muted">system</span>' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>
<?php
admin_footer();
