<?php
/**
 * admin/messages-log.php — the outbound message log (SMS / WhatsApp / email).
 * Every automatic send is recorded in message_logs with recipient, body,
 * provider message id and any error, so staff can confirm delivery and debug
 * failures. Read-only. Gated to roles that can see the dashboard.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$channel = strtolower(Security::clean($_GET['channel'] ?? '', 12));
$status  = strtolower(Security::clean($_GET['status'] ?? '', 12));
$channel = in_array($channel, ['sms', 'whatsapp', 'email'], true) ? $channel : '';
$status  = in_array($status, ['sent', 'failed', 'skipped', 'queued'], true) ? $status : '';

$where  = [];
$params = [];
if ($channel !== '') { $where[] = 'channel = :c'; $params['c'] = $channel; }
if ($status !== '')  { $where[] = 'status = :s';  $params['s'] = $status; }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$tableExists = true;
$rows        = [];
$counts      = ['total' => 0, 'sent' => 0, 'failed' => 0];
try {
    $rows = Database::fetchAll(
        'SELECT id, booking_id, channel, provider, to_number, body, status, provider_ref, error, created_at
           FROM message_logs' . $whereSql . '
          ORDER BY id DESC
          LIMIT 300',
        $params
    );
    $counts['total']  = (int) Database::scalar('SELECT COUNT(*) FROM message_logs', [], 0);
    $counts['sent']   = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE status = 'sent'", [], 0);
    $counts['failed'] = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE status = 'failed'", [], 0);
} catch (Throwable $e) {
    $tableExists = false;
}

/** Channel / status → coloured pill. */
function msglog_pill(string $kind): string
{
    $map = [
        'sms'      => ['SMS',      '#e2ecfb', '#1c3b72'],
        'whatsapp' => ['WhatsApp', '#e3f6ea', '#0a6b3b'],
        'email'    => ['Email',    '#efeaff', '#5a3fb0'],
        'sent'     => ['Sent',     '#d7f4e3', '#0a6b3b'],
        'failed'   => ['Failed',   '#f7dcdc', '#8a1f1f'],
        'skipped'  => ['Skipped',  '#e7e7ea', '#333'],
        'queued'   => ['Queued',   '#fff4d1', '#8a6d00'],
    ];
    [$label, $bg, $fg] = $map[$kind] ?? [ucfirst($kind), '#eee', '#333'];
    return '<span class="pill" style="background:' . $bg . ';color:' . $fg . '">' . $label . '</span>';
}

admin_header('Message Log', 'messages-log');
?>
<?php if (!$tableExists): ?>
  <div class="flash bad">
    The <span class="mono">message_logs</span> table does not exist yet. Run
    <span class="mono">database/upgrade-2026-08-sms-gateway.sql</span> once in phpMyAdmin, then reload.
  </div>
<?php else: ?>

<div class="cards">
  <div class="card"><div class="k">Total messages</div><div class="v"><?= $counts['total'] ?></div></div>
  <div class="card"><div class="k">Sent</div><div class="v"><?= $counts['sent'] ?></div></div>
  <div class="card"><div class="k">Failed</div><div class="v"><?= $counts['failed'] ?></div></div>
</div>

<form method="get" class="toolbar">
  <select name="channel">
    <option value="">All channels</option>
    <?php foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $channel === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (['sent' => 'Sent', 'failed' => 'Failed', 'skipped' => 'Skipped'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn ghost" type="submit">Filter</button>
  <span class="muted" style="font-size:12px">Showing latest <?= count($rows) ?> (max 300)</span>
</form>

<div class="panel">
  <table>
    <thead>
      <tr>
        <th style="width:150px">When</th>
        <th>Channel</th>
        <th>To</th>
        <th>Message</th>
        <th>Status</th>
        <th>Ref / Error</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="6" class="muted">No messages logged yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="mono muted"><?= Security::e((string) $r['created_at']) ?></td>
          <td><?= msglog_pill((string) $r['channel']) ?></td>
          <td class="mono"><?= Security::e((string) $r['to_number']) ?></td>
          <td style="max-width:340px"><span class="muted" style="font-size:12.5px"><?= Security::e(mb_strimwidth((string) ($r['body'] ?? ''), 0, 120, '…')) ?></span></td>
          <td><?= msglog_pill((string) $r['status']) ?></td>
          <td class="mono muted" style="max-width:220px;font-size:12px">
            <?php if (!empty($r['error'])): ?>
              <span style="color:#8a1f1f"><?= Security::e((string) $r['error']) ?></span>
            <?php else: ?>
              <?= Security::e((string) $r['provider_ref']) ?: '—' ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php
admin_footer();
