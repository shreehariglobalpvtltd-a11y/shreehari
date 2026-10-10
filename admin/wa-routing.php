<?php
/**
 * admin/wa-routing.php — who delivers which ticket (10 Oct 2026).
 *
 * The office's window on includes/waagentroute.php: every agent with their
 * WhatsApp availability (available / busy / offline — the agent sets it by
 * writing to the Meta number; the office can set it here), whether their
 * free 24 h window is open, and the delivery log (watching → delivered, or
 * offered → accepted / timeout → next agent → admin). Below it the ticket
 * changes made on WhatsApp (ticket_edit_audit). An agent sees only their
 * own rows.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waagentroute.php';

$e      = static fn($v): string => Security::e((string) $v);
$flash  = null;
$me     = (int) ($admin['id'] ?? 0);
$own    = in_array((string) ($admin['role'] ?? ''), ['agent', 'counter'], true);
$office = !$own && (Auth::isSuperadmin() || Auth::can('commissions.view'));
$ready  = WaAgentRoute::ready();

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $id = (int) ($_POST['agent'] ?? 0);
        $st = (string) ($_POST['status'] ?? '');
        if (($office || $id === $me) && WaAgentRoute::agentById($id) !== null && in_array($st, ['available', 'busy', 'offline'], true)) {
            WaAgentRoute::setStatus($id, $st, $st === 'busy' ? max(5, Settings::getInt('wa_route_busy_min', 60)) : 0);
            Logger::audit('wa_route.presence', 'admin', (string) $id, null, ['status' => $st], 'set from WhatsApp routing page');
            $flash = ['ok', 'Saved.'];
        } else {
            $flash = ['bad', 'Not allowed.'];
        }
    }
}

$stats = $ready ? WaAgentRoute::stats() : ['today' => [], 'agents' => [], 'open' => 0];
if ($own) {
    $stats['agents'] = array_values(array_filter($stats['agents'], static fn(array $a): bool => (int) $a['id'] === $me));
}
$log = [];
$edits = [];
if ($ready) {
    $where = 'd.created_at >= NOW() - INTERVAL 3 DAY';
    $p = [];
    if ($own) {
        $where .= ' AND (d.agent_id = :me OR b.sold_by_admin_id = :me2)';
        $p = ['me' => $me, 'me2' => $me];
    }
    $log = Database::fetchAll(
        "SELECT d.*, a.full_name AS agent_name FROM delivery_log d
           JOIN bookings b ON b.id = d.booking_id
           LEFT JOIN admins a ON a.id = d.agent_id
          WHERE $where ORDER BY d.id DESC LIMIT 200",
        $p
    );
    try {
        $edits = Database::fetchAll(
            'SELECT t.* FROM ticket_edit_audit t JOIN bookings b ON b.id = t.booking_id'
            . ($own ? ' WHERE b.sold_by_admin_id = :me' : '') . ' ORDER BY t.id DESC LIMIT 50',
            $own ? ['me' => $me] : []
        );
    } catch (Throwable $ignored) {
    }
}
$adminNo = WaAgentRoute::adminNumber();
require_once INCLUDE_PATH . '/sitefacts.php';
$metaNo  = SiteFacts::chatDigits();

admin_header('WhatsApp routing', 'wa-routing');
?>
<style>
.wr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px}
.wr-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;background:#EEF0F4;color:#5C6B85}
.wr-available,.wr-delivered,.wr-accepted{background:#E4F6EC;color:#11703F}
.wr-busy,.wr-watching,.wr-offered{background:#FFF4DE;color:#8A5300}
.wr-offline,.wr-timeout,.wr-declined,.wr-exhausted,.wr-skipped{background:#FDE7EB;color:#A50E2D}
:root[data-theme="dark"] .wr-available,:root[data-theme="dark"] .wr-delivered,:root[data-theme="dark"] .wr-accepted{background:#12321F;color:#5FD39A}
:root[data-theme="dark"] .wr-busy,:root[data-theme="dark"] .wr-watching,:root[data-theme="dark"] .wr-offered{background:#3B2610;color:#FFC870}
:root[data-theme="dark"] .wr-offline,:root[data-theme="dark"] .wr-timeout,:root[data-theme="dark"] .wr-declined,:root[data-theme="dark"] .wr-exhausted,:root[data-theme="dark"] .wr-skipped{background:#3D1219;color:#FF8FA1}
.wr-how{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.wr-wrap{overflow-x:auto}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="wr-how">
  <b>How it works · कसरी चल्छ</b><br>
  1. टिकट काटिनासाथ यात्रुलाई आफैं जान्छ (पैसा लाग्ने credit यही एउटा कामको लागि मात्र)।<br>
  2. <?= max(1, Settings::getInt('wa_route_grace_min', 3)) ?> मिनेटमा यात्रुसम्म पुगेन भने रुटको <b>available</b> एजेन्टलाई WhatsApp मा जान्छ — निःशुल्क (एजेन्टले पछिल्लो २४ घण्टामा <b>+<?= $e($metaNo) ?></b> मा लेखेको हुनुपर्छ)।<br>
  3. <?= max(1, Settings::getInt('wa_route_timeout_min', 5)) ?> मिनेटमा जवाफ नआए → एजेन्ट busy, अर्को एजेन्ट। सबै सकिए → admin<?= $adminNo !== '' ? ' (+' . $e($adminNo) . ')' : '' ?> लाई खबर + यात्रुलाई लिंक।<br>
  <small class="muted">एजेन्टले WhatsApp मा "available" / "busy" / "offline" लेखेर आफैं बदल्न सक्छन्। बिहान ६ बजे आजको बसको good-morning जान्छ। Agents keep their window open by writing to the Meta number once a day.</small>
</div>

<?php if (!$ready): ?>
  <div class="flash bad">Routing tables are not on this database yet (database/upgrade-2026-10-10-wa-agent-routing.sql).</div>
<?php else: ?>
<div class="wr-cards">
  <?php foreach (['watching' => '👀 Watching', 'delivered' => '✅ Customer got it', 'offered' => '📨 Offered to agent', 'accepted' => '🧑‍💼 Agent delivered', 'timeout' => '⏱️ Agent timeout', 'exhausted' => '📵 Nobody'] as $k => $l): ?>
    <div class="card"><div class="k"><?= $e($l) ?> <small class="muted">today</small></div><div class="v"><?= (int) ($stats['today'][$k] ?? 0) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="panel wr-wrap">
  <h2>Agents · एजेन्ट</h2>
  <table>
    <thead><tr><th>Agent</th><th>WhatsApp</th><th>Status</th><th>24 h window</th><th>Last message</th><th>7 d delivered / timeouts</th><th></th></tr></thead>
    <tbody>
    <?php if ($stats['agents'] === []): ?><tr><td colspan="7" class="muted">No active agent / counter with a WhatsApp number.</td></tr><?php endif; ?>
    <?php foreach ($stats['agents'] as $a): ?>
      <tr>
        <td><?= $e($a['name']) ?> <small class="muted"><?= $e($a['role']) ?></small></td>
        <td class="mono">+<?= $e($a['intl']) ?></td>
        <td><span class="wr-pill wr-<?= $e($a['status']) ?>"><?= $e($a['status']) ?></span></td>
        <td><?= $a['window'] ? '🟢 open' : '<span class="muted">closed</span>' ?></td>
        <td style="white-space:nowrap"><?= $e($a['last_active'] !== '' ? substr($a['last_active'], 5, 11) : '—') ?></td>
        <td><?= (int) $a['accepted7'] ?> / <?= (int) $a['timeouts7'] ?></td>
        <td style="white-space:nowrap">
          <?php if ($office || (int) $a['id'] === $me): ?>
          <form method="post" data-no-guard style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="agent" value="<?= (int) $a['id'] ?>">
            <?php foreach (['available' => '✅', 'busy' => '⏳', 'offline' => '⛔'] as $s => $ic): ?>
              <button class="btn ghost sm" name="status" value="<?= $e($s) ?>" type="submit" title="<?= $e($s) ?>"<?= $a['status'] === $s ? ' disabled' : '' ?>><?= $ic ?></button>
            <?php endforeach; ?>
          </form>
          <?php endif; ?>
          <?php if (!$a['window']): ?><a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= $e($a['intl']) ?>?text=<?= rawurlencode('नमस्ते! टिकट पाउन +' . $metaNo . ' मा "available" लेखेर पठाउनुहोस्: https://wa.me/' . $metaNo . '?text=available') ?>">Ask to open</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel wr-wrap">
  <h2>Delivery log · डेलिभरी लग (3 days)</h2>
  <table>
    <thead><tr><th>When</th><th>Ticket</th><th>Agent</th><th>Channel</th><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
    <?php if ($log === []): ?><tr><td colspan="6" class="muted">Nothing yet.</td></tr><?php endif; ?>
    <?php foreach ($log as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= $e(substr((string) $r['created_at'], 5, 11)) ?></td>
        <td class="mono"><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a></td>
        <td><?= $e($r['agent_name'] ?? '—') ?></td>
        <td><?= $e($r['channel']) ?></td>
        <td><span class="wr-pill wr-<?= $e(preg_replace('/_old$/', '', (string) $r['status'])) ?>"><?= $e($r['status']) ?></span></td>
        <td><small><?= $e(mb_substr((string) ($r['detail'] ?? ''), 0, 110)) ?></small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel wr-wrap">
  <h2>Ticket changes on WhatsApp · टिकट परिवर्तन</h2>
  <table>
    <thead><tr><th>When</th><th>Ticket</th><th>Field</th><th>Old → New</th><th>By</th><th>Code</th><th>Outcome</th></tr></thead>
    <tbody>
    <?php if ($edits === []): ?><tr><td colspan="7" class="muted">No changes yet.</td></tr><?php endif; ?>
    <?php foreach ($edits as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= $e(substr((string) $r['changed_at'], 5, 11)) ?></td>
        <td class="mono"><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a></td>
        <td><?= $e($r['field_changed']) ?></td>
        <td><small><?= $e(mb_substr((string) $r['old_value'], 0, 40)) ?> → <b><?= $e(mb_substr((string) $r['new_value'], 0, 40)) ?></b></small></td>
        <td class="mono"><small><?= $e($r['changed_by']) ?></small></td>
        <td><?= (int) $r['otp_verified'] === 1 ? '🔐 yes' : 'no' ?></td>
        <td><span class="wr-pill wr-<?= $e($r['outcome'] === 'done' ? 'delivered' : ($r['outcome'] === 'refused' ? 'declined' : 'offered')) ?>"><?= $e($r['outcome']) ?></span>
          <?php if ((string) ($r['detail'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e(mb_substr((string) $r['detail'], 0, 90)) ?></div><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php admin_footer();
