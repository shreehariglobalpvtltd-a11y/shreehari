<?php
/**
 * admin/wa-queue.php — WhatsApp tickets by "hi" (4 Oct 2026).
 *
 * Every ticket the system tried to send on the AiSensy number, per number:
 * waiting for that number's "hi", sent, delivered / read, or failed - with a
 * one-tap "send now" for a waiting row and a wa.me link the desk can share.
 *
 * The setup box (webhook URL for the AiSensy dashboard) is for a superadmin
 * only: the URL carries the webhook token.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waqueue.php';

$e       = static fn($v): string => Security::e((string) $v);
$flash   = null;
$isSuper = Auth::isSuperadmin();
$scope   = Auth::bookingScopeAdminId();   // an agent sees only their own sales
$ready   = WaQueue::ready();

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Auth::can('bookings.edit')) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $row = Database::fetch('SELECT q.*, b.sold_by_admin_id FROM wa_ticket_queue q JOIN bookings b ON b.id = q.booking_id WHERE q.id = :i', ['i' => $id]);
        if ($row === null || ($scope !== null && (int) $row['sold_by_admin_id'] !== $scope)) {
            $flash = ['bad', 'Not found.'];
        } elseif (!WaQueue::windowOpen((string) $row['phone'])) {
            $flash = ['bad', 'This number has not written to us in the last 24 h — WhatsApp would refuse it. Ask them to send "hi" (the link below), and it goes by itself.'];
        } else {
            $ok = WaQueue::tryNow($id, true);
            Logger::audit('wa_queue.send', 'booking', (string) $row['pnr'], null, ['phone' => $row['phone'], 'ok' => $ok], 'manual send from WhatsApp queue');
            $flash = $ok ? ['ok', 'Sent to +' . $row['phone'] . '.'] : ['bad', 'AiSensy refused it — see the error on the row.'];
        }
    }
}

$status = Security::clean((string) ($_GET['status'] ?? ''), 12);
$rows = [];
$counts = [];
if ($ready) {
    $where = ['q.created_at >= NOW() - INTERVAL 14 DAY'];
    $params = [];
    if (in_array($status, ['pending', 'sent', 'delivered', 'read', 'failed', 'expired'], true)) {
        $where[] = 'q.status = :st';
        $params['st'] = $status;
    }
    if ($scope !== null) {
        $where[] = 'b.sold_by_admin_id = :sc';
        $params['sc'] = $scope;
    }
    $rows = Database::fetchAll(
        'SELECT q.*, b.contact_phone, b.status AS bstatus,
                (SELECT w.last_in_at FROM wa_windows w WHERE w.phone = q.phone) AS last_in
           FROM wa_ticket_queue q JOIN bookings b ON b.id = q.booking_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY q.id DESC LIMIT 200',
        $params
    );
    foreach (Database::fetchAll(
        "SELECT q.status, COUNT(*) n FROM wa_ticket_queue q JOIN bookings b ON b.id = q.booking_id
          WHERE q.created_at >= NOW() - INTERVAL 14 DAY" . ($scope !== null ? ' AND b.sold_by_admin_id = :sc' : '') . '
          GROUP BY q.status', $scope !== null ? ['sc' => $scope] : []) as $c) {
        $counts[(string) $c['status']] = (int) $c['n'];
    }
}

$hiNo = WaQueue::hiNumber();
admin_header('WhatsApp tickets', 'wa-queue');
?>
<style>
.wq-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.wq-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800}
.wq-pending{background:#FFF4DE;color:#8A5300}.wq-sent{background:#EEF4FC;color:#003C90}.wq-delivered,.wq-read{background:#E4F6EC;color:#11703F}
.wq-failed{background:#FDE7EB;color:#A50E2D}.wq-expired{background:#EEF0F4;color:#5C6B85}
:root[data-theme="dark"] .wq-pending{background:#3B2610;color:#FFC870}:root[data-theme="dark"] .wq-sent{background:#152645;color:#8FBFFF}
:root[data-theme="dark"] .wq-delivered,:root[data-theme="dark"] .wq-read{background:#12321F;color:#5FD39A}
:root[data-theme="dark"] .wq-failed{background:#3D1219;color:#FF8FA1}:root[data-theme="dark"] .wq-expired{background:#1B2638;color:#93A4BE}
.wq-how{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.wq-how code{word-break:break-all}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="wq-how">
  <b>How it works · कसरी चल्छ</b><br>
  WhatsApp delivers a ticket free only within 24 h after that person writes to <b>+<?= $e($hiNo) ?></b>.
  So each ticket waits here until the customer (or their second number, or the counter that sold it) sends <b>"hi"</b> — then it goes by itself.
  Give the customer the link or QR on the ticket screen: <a href="<?= $e(WaQueue::hiLink()) ?>" target="_blank" rel="noopener"><?= $e(WaQueue::hiLink()) ?></a><br>
  यात्रु, दोस्रो नम्बर वा टिकट काट्ने काउन्टरले +<?= $e($hiNo) ?> मा एक पटक "hi" पठाएपछि २४ घण्टासम्म टिकट निःशुल्क आफैं जान्छ।
</div>

<?php if ($isSuper): ?>
<?php $tok = AiSensyAPI::config('AISENSY_WEBHOOK_TOKEN', 'aisensy_webhook_token'); ?>
<details class="wq-how">
  <summary style="cursor:pointer;font-weight:800">⚙️ One-time setup in the AiSensy dashboard (superadmin)</summary>
  <p style="margin:8px 0 4px">Without this, "hi" never reaches us. AiSensy → Manage → Webhooks → add:</p>
  <?php if ($tok !== ''): ?>
    <code><?= $e(rtrim(APP_URL, '/') . '/whatsapp/aisensy-webhook.php?token=' . $tok) ?></code>
  <?php else: ?>
    <b>No webhook token configured</b> (AISENSY_WEBHOOK_TOKEN in .env).
  <?php endif; ?>
  <p style="margin:6px 0 0">Topic: <b>message received / sender user</b>. Keep this URL private — it carries the token.</p>
  <p style="margin:6px 0 0">Last message received from anyone:
    <b><?= $e($ready ? ((string) Database::scalar('SELECT MAX(last_in_at) FROM wa_windows', [], '') ?: 'never yet') : '—') ?></b></p>
</details>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash bad">The WhatsApp queue tables are not on this database yet, or it is switched off (wa_hi_queue_on).</div>
<?php else: ?>
<div class="wq-cards">
  <?php foreach (['pending' => '⏳ Waiting for "hi"', 'sent' => '📤 Sent', 'delivered' => '✅ Delivered', 'read' => '👀 Read', 'failed' => '❌ Failed', 'expired' => '⌛ Expired'] as $k => $l): ?>
    <a class="card" href="?status=<?= $e($k) ?>" style="text-decoration:none"><div class="k"><?= $e($l) ?></div><div class="v"><?= (int) ($counts[$k] ?? 0) ?></div></a>
  <?php endforeach; ?>
</div>
<div class="panel">
  <h2>Last 14 days <?= $status !== '' ? '· ' . $e($status) : '' ?> <?php if ($status !== ''): ?><a class="btn ghost sm" href="?">All</a><?php endif; ?></h2>
  <table>
    <thead><tr><th>PNR</th><th>To</th><th>Who</th><th>Status</th><th>Window</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="muted">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $open = $r['last_in'] !== null && strtotime((string) $r['last_in']) >= time() - WaQueue::WINDOW_MINUTES * 60; ?>
      <tr>
        <td class="mono"><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a></td>
        <td class="mono">+<?= $e($r['phone']) ?></td>
        <td><?= $e(['customer' => 'Customer', 'alt' => '2nd number', 'seller' => 'Counter / agent'][$r['role']] ?? $r['role']) ?></td>
        <td><span class="wq-pill wq-<?= $e($r['status']) ?>"><?= $e($r['status']) ?></span><?= $r['via'] ? ' <small class="muted">' . $e($r['via']) . '</small>' : '' ?>
          <?php if ((string) ($r['last_error'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e(mb_substr((string) $r['last_error'], 0, 90)) ?></div><?php endif; ?></td>
        <td><?= $open ? '🟢 open' : '<span class="muted">closed</span>' ?></td>
        <td style="white-space:nowrap"><?= $e(substr((string) ($r['sent_at'] ?: $r['created_at']), 5, 11)) ?></td>
        <td style="white-space:nowrap">
          <?php if (in_array($r['status'], ['pending', 'failed'], true) && $open && Auth::can('bookings.edit')): ?>
            <form method="post" data-no-guard style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm" type="submit">Send now</button></form>
          <?php endif; ?>
          <a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= $e($r['phone']) ?>?text=<?= rawurlencode('नमस्ते! तपाईंको टिकट (' . $r['pnr'] . ') WhatsApp मा पाउन +' . $hiNo . ' मा "hi" पठाउनुहोस्: ' . WaQueue::hiLink((string) $r['pnr'])) ?>">Ask for "hi"</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php admin_footer();
