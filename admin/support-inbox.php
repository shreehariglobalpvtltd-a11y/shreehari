<?php
/**
 * admin/support-inbox.php — the human side of the WhatsApp handoff
 * (24 Sep 2026).
 *
 * Every request the assistant could not or must not settle itself lands
 * in support_tickets as SUP-yymmdd-xxxxx (includes/aihandoff.php): a
 * complaint, a payment dispute, a refund outside the slabs, an identity
 * doubt, a confidential document request, a safety issue, anything that
 * needs approval. This screen is where staff pick it up: filter, take it,
 * write a reply (optionally sent to the person's WhatsApp), mark it
 * resolved, open the evidence the person attached.
 *
 * support.view to read, support.reply to act (manager, support, counter,
 * official — see Auth::ROLE_PERMISSIONS). A counter agent sees only the
 * requests raised about bookings they sold, or with no booking at all.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin   = admin_boot('support.view');
$mayAct  = Auth::can('support.reply');
$scopeId = Auth::bookingScopeAdminId();
$flash   = null;

require_once INCLUDE_PATH . '/aihandoff.php';
$migrated = AiHandoff::available(true);

/* ---- evidence: stream an inbound WhatsApp attachment kept for the desk ---- */
if ($migrated && isset($_GET['evidence'])) {
    $t = Database::fetch('SELECT t.id, t.evidence_path, t.booking_id, b.sold_by_admin_id FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.id = :id', ['id' => (int) $_GET['evidence']]);
    if ($t === null || (string) ($t['evidence_path'] ?? '') === ''
        || ($scopeId !== null && $t['booking_id'] !== null && (int) ($t['sold_by_admin_id'] ?? 0) !== $scopeId)) {
        http_response_code(404);
        exit('No such file.');
    }
    require_once INCLUDE_PATH . '/wamedia.php';
    try {
        $bytes = WaMedia::openStash((string) $t['evidence_path']);
    } catch (Throwable $e) {
        http_response_code(404);
        exit('The file is no longer available.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->buffer($bytes);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        $mime = 'application/octet-stream';
    }
    Logger::audit('support.evidence_view', 'support_ticket', (string) $t['id'], null, null, 'opened by admin #' . $admin['id']);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="evidence-' . (int) $t['id'] . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/* ---- actions ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayAct) {
        $flash = ['bad', 'Your role may read requests but not act on them.'];
    } else {
        $id  = (int) ($_POST['id'] ?? 0);
        $act = (string) ($_POST['action'] ?? '');
        $t   = $id > 0 ? Database::fetch('SELECT t.*, b.sold_by_admin_id FROM support_tickets t LEFT JOIN bookings b ON b.id = t.booking_id WHERE t.id = :id', ['id' => $id]) : null;
        if ($t !== null && $scopeId !== null && $t['booking_id'] !== null && (int) ($t['sold_by_admin_id'] ?? 0) !== $scopeId) {
            $t = null;
        }
        try {
            if ($t === null) {
                throw new RuntimeException('Request not found.');
            }
            if ($act === 'status') {
                $st = (string) ($_POST['status'] ?? '');
                if (!in_array($st, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                    throw new RuntimeException('Unknown status.');
                }
                Database::update('support_tickets', [
                    'status'      => $st,
                    'resolved_at' => in_array($st, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null,
                    'assigned_to' => $t['assigned_to'] ?: (int) $admin['id'],
                ], 'id = :id', ['id' => $id]);
                Logger::audit('support.status', 'support_ticket', (string) $t['ticket_ref'], ['status' => $t['status']], ['status' => $st], 'by admin #' . $admin['id']);
                $flash = ['ok', $t['ticket_ref'] . ' marked ' . $st . '.'];
            } elseif ($act === 'take') {
                Database::update('support_tickets', ['assigned_to' => (int) $admin['id'], 'status' => $t['status'] === 'open' ? 'in_progress' : $t['status']], 'id = :id', ['id' => $id]);
                Logger::audit('support.assign', 'support_ticket', (string) $t['ticket_ref'], null, ['to' => (int) $admin['id']], 'taken by admin #' . $admin['id']);
                $flash = ['ok', 'You have taken ' . $t['ticket_ref'] . '.'];
            } elseif ($act === 'reply') {
                $note = trim(Security::clean((string) ($_POST['note'] ?? ''), 2000));
                if (mb_strlen($note) < 2) {
                    throw new RuntimeException('Write the reply first.');
                }
                Database::insert('support_messages', [
                    'ticket_id' => $id, 'sender_type' => 'admin',
                    'sender_name' => mb_substr((string) ($admin['full_name'] ?? $admin['username'] ?? 'Office'), 0, 120),
                    'message' => $note,
                ]);
                if ($t['status'] === 'open') {
                    Database::update('support_tickets', ['status' => 'in_progress', 'assigned_to' => $t['assigned_to'] ?: (int) $admin['id']], 'id = :id', ['id' => $id]);
                }
                $sent = null;
                if (($_POST['send_wa'] ?? '') === '1') {
                    require_once INCLUDE_PATH . '/notify.php';
                    $text = Settings::getString('company_name', APP_NAME) . " — " . $t['ticket_ref'] . "\n" . $note;
                    $r    = Notify::whatsapp((string) $t['phone'], $text, null, null, [], $t['booking_id'] ? (int) $t['booking_id'] : null,
                        ['purpose' => 'support_reply', 'admin_id' => (int) $admin['id']]);
                    $sent = $r === true;
                }
                Logger::audit('support.reply', 'support_ticket', (string) $t['ticket_ref'], null, ['wa' => $sent], 'by admin #' . $admin['id']);
                $flash = ['ok', 'Reply saved.' . ($sent === null ? '' : ($sent ? ' Sent on WhatsApp.' : ' WhatsApp did NOT accept it — the note is saved, send it by hand.'))];
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- list ---- */
$status = Security::clean($_GET['status'] ?? 'live', 20);
$source = Security::clean($_GET['source'] ?? '', 20);
$where  = [];
$params = [];
if ($status === 'live') {
    $where[] = "t.status IN ('open','in_progress')";
} elseif (in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
    $where[]      = 't.status = :st';
    $params['st'] = $status;
}
if ($migrated && in_array($source, ['whatsapp_ai', 'web', 'admin'], true)) {
    $where[]       = 't.source = :src';
    $params['src'] = $source;
}
if ($scopeId !== null) {
    $where[]         = '(t.booking_id IS NULL OR b.sold_by_admin_id = :scope)';
    $params['scope'] = $scopeId;
}
$rows = [];
try {
    $rows = Database::fetchAll(
        'SELECT t.*, b.pnr, a.full_name AS assignee
           FROM support_tickets t
           LEFT JOIN bookings b ON b.id = t.booking_id
           LEFT JOIN admins a ON a.id = t.assigned_to'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.id DESC LIMIT 300",
        $params
    );
} catch (Throwable $e) {
    $rows = [];
}
$counts = [];
try {
    foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM support_tickets GROUP BY status') as $c) {
        $counts[(string) $c['status']] = (int) $c['n'];
    }
} catch (Throwable $ignored) {
}
$csrf = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';
$open = isset($_GET['open']) ? (int) $_GET['open'] : 0;
$thread = [];
if ($open > 0) {
    try {
        $thread = Database::fetchAll('SELECT sender_type, sender_name, message, created_at FROM support_messages WHERE ticket_id = :t ORDER BY id', ['t' => $open]);
    } catch (Throwable $ignored) {
    }
}

$prio = static function (string $p): string {
    $map = ['urgent' => 'var(--bad)', 'high' => 'var(--warn)', 'normal' => 'var(--ink)', 'low' => 'var(--mut)'];
    return '<b style="color:' . ($map[$p] ?? 'var(--ink)') . '">' . Security::e($p) . '</b>';
};

admin_header('Support Inbox', 'support-inbox');
?>
<style>
  .si-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .si-filters{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
  .si-filters a{padding:6px 11px;border:1px solid var(--line);border-radius:999px;font-size:13px;text-decoration:none;color:var(--ink);background:var(--card)}
  .si-filters a.on{background:var(--ink);color:var(--card);border-color:var(--ink)}
  .si-inline{display:inline} .si-inline .btn{padding:5px 9px;font-size:12px;min-height:32px}
  .si-msg{white-space:pre-wrap;max-width:460px;font-size:13.5px;line-height:1.45}
  .si-thread{border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin:8px 0}
  .si-thread .adm{color:var(--ok)} .si-thread .cus{color:var(--ink)}
  .si-reply textarea{width:100%;min-height:70px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<p class="si-note">Requests the WhatsApp assistant handed to people, plus anything filed from the website. Take one, reply (optionally straight to the person's
  WhatsApp), and mark it resolved — the assistant reads the status back when the person asks for their SUP number.
  <?php if (!$migrated): ?><b style="color:var(--bad)">The handoff columns are not installed yet — apply database/upgrade-2026-09-24-wa-ops-manager.sql.</b><?php endif; ?></p>

<div class="kpis">
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-alert"/></svg></span><div class="kt"><div class="kk">Open</div><div class="kv"><?= $counts['open'] ?? 0 ?></div><div class="ks">waiting for staff</div></div></div>
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-msg"/></svg></span><div class="kt"><div class="kk">In progress</div><div class="kv"><?= $counts['in_progress'] ?? 0 ?></div><div class="ks">taken</div></div></div>
  <div class="kpi tone-green"><span class="ki"><svg class="a-ic"><use href="#a-check-circle"/></svg></span><div class="kt"><div class="kk">Resolved</div><div class="kv"><?= ($counts['resolved'] ?? 0) + ($counts['closed'] ?? 0) ?></div><div class="ks">all time</div></div></div>
</div>

<div class="si-filters">
  <?php foreach (['live' => 'Live', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'] as $k => $lbl): ?>
    <a class="<?= $status === $k ? 'on' : '' ?>" href="?status=<?= $k ?><?= $source !== '' ? '&source=' . urlencode($source) : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
  <?php if ($migrated): ?>
    <a class="<?= $source === 'whatsapp_ai' ? 'on' : '' ?>" href="?status=<?= urlencode($status) ?>&source=whatsapp_ai">From the assistant</a>
    <a class="<?= $source === 'web' ? 'on' : '' ?>" href="?status=<?= urlencode($status) ?>&source=web">From the website</a>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Requests (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
    <p class="si-note">Nothing here.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Ref</th><th>When</th><th>Priority</th><th>Category</th><th>Who</th><th>Request</th><th>Status</th><th>Taken by</th><th data-nosort>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $rid = (int) $r['id']; ?>
        <tr>
          <td data-label="Ref"><b><?= Security::e((string) $r['ticket_ref']) ?></b><?php if ((string) ($r['pnr'] ?? '') !== ''): ?><br><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a><?php endif; ?></td>
          <td data-label="When" data-sort="<?= Security::e((string) $r['created_at']) ?>"><?= Security::e(substr((string) $r['created_at'], 0, 16)) ?><br><small><?= Security::e((string) ($r['source'] ?? 'web')) ?><?= (string) ($r['language'] ?? '') !== '' ? ' · ' . Security::e((string) $r['language']) : '' ?></small></td>
          <td data-label="Priority"><?= $prio((string) $r['priority']) ?></td>
          <td data-label="Category"><?= Security::e(AiHandoff::label((string) ($r['category'] ?? 'other'))) ?><?= (string) ($r['sender_role'] ?? '') !== '' ? '<br><small>' . Security::e((string) $r['sender_role']) . '</small>' : '' ?></td>
          <td data-label="Who"><?= Security::e((string) $r['name']) ?><br><a href="https://wa.me/<?= Security::e(preg_replace('/\D/', '', strlen((string) $r['phone']) === 10 ? '91' . $r['phone'] : (string) $r['phone']) ?? '') ?>" target="_blank" rel="noopener"><?= Security::e((string) $r['phone']) ?></a></td>
          <td data-label="Request" class="wrap"><div class="si-msg"><?= Security::e((string) $r['message']) ?></div>
            <?php if ((string) ($r['evidence_path'] ?? '') !== ''): ?><a href="?evidence=<?= $rid ?>" target="_blank" rel="noopener">📎 attachment</a><?php endif; ?>
            <?php if (empty($r['office_notified_at']) && (string) ($r['source'] ?? '') === 'whatsapp_ai'): ?><br><small style="color:var(--warn)">office was not alerted automatically</small><?php endif; ?>
          </td>
          <td data-label="Status"><?= Security::e((string) $r['status']) ?></td>
          <td data-label="Taken by"><?= Security::e((string) ($r['assignee'] ?? '—')) ?></td>
          <td data-label="Actions">
            <div class="dt-acts">
              <a class="btn ghost" href="?status=<?= urlencode($status) ?>&open=<?= $rid ?>#t<?= $rid ?>">Thread</a>
              <?php if ($mayAct): ?>
                <?php if (empty($r['assigned_to'])): ?><form method="post" class="si-inline"><?= $csrf ?><input type="hidden" name="action" value="take"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn" type="submit">Take</button></form><?php endif; ?>
                <?php if (!in_array((string) $r['status'], ['resolved', 'closed'], true)): ?>
                  <form method="post" class="si-inline"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="status" value="resolved"><button class="btn ghost" type="submit">Resolve</button></form>
                <?php else: ?>
                  <form method="post" class="si-inline"><?= $csrf ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="status" value="open"><button class="btn ghost" type="submit">Reopen</button></form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($open === $rid): ?>
        <tr id="t<?= $rid ?>"><td colspan="9">
          <?php foreach ($thread as $m): ?>
            <div class="si-thread"><b class="<?= $m['sender_type'] === 'admin' ? 'adm' : 'cus' ?>"><?= Security::e((string) ($m['sender_name'] ?? $m['sender_type'])) ?></b>
              <small style="color:var(--mut)"> · <?= Security::e(substr((string) $m['created_at'], 0, 16)) ?></small>
              <div class="si-msg"><?= Security::e((string) $m['message']) ?></div></div>
          <?php endforeach; ?>
          <?php if ($mayAct): ?>
          <form method="post" class="si-reply"><?= $csrf ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= $rid ?>">
            <textarea name="note" required placeholder="Reply / internal note…"></textarea>
            <label style="display:inline-flex;gap:6px;align-items:center;margin:8px 0"><input type="checkbox" name="send_wa" value="1"> also send this to the person's WhatsApp</label>
            <div><button class="btn" type="submit">Save reply</button></div>
          </form>
          <?php endif; ?>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
