<?php
/**
 * admin/offline-requests.php — the offline desk's front door and its
 * "somebody must look at this" list (19 Sep 2026).
 *
 * The desk itself is /offline-desk.html (it has to open with no internet, so
 * it cannot be an admin page). This page:
 *   - tells staff what the offline desk is and opens it (and says to open it
 *     ONCE while online, so the phone has it saved for the bad day);
 *   - lists the requests that still need a person: the server could not seat
 *     them (bus full, service off…) or the cash taken does not match the
 *     ticket. Cash changed hands for every one of these, so a row only leaves
 *     the list when somebody WRITES what was done;
 *   - lets the office switch the feature on/off (offline_queue_on, ships off).
 *
 * A desk sees its own requests; the office (dashboard.view) sees everyone's.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

require_once INCLUDE_PATH . '/offlinequeue.php';

$base     = '';   // root-relative: the panel must stay on the request host
$flash    = null;
$me       = (int) $admin['id'];
$isOffice = Auth::can('dashboard.view');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            if ($act === 'enable' || $act === 'disable') {
                if (!$isOffice) {
                    throw new RuntimeException('Only the office can switch this on or off.');
                }
                Settings::set('offline_queue_on', $act === 'enable' ? '1' : '0', 'bool', 'agent');
                Logger::audit('offline.switch', 'setting', 'offline_queue_on', null, ['on' => $act === 'enable'], 'by admin #' . $me);
                $flash = ['ok', $act === 'enable' ? 'The offline desk is ON.' : 'The offline desk is OFF.'];
            } elseif ($act === 'resolve') {
                $id  = (int) ($_POST['id'] ?? 0);
                $row = Database::fetch('SELECT admin_id FROM offline_requests WHERE id = :i', ['i' => $id]);
                if ($row === null || ((int) $row['admin_id'] !== $me && !$isOffice)) {
                    throw new RuntimeException('That request is not yours.');
                }
                $note = Security::clean((string) ($_POST['note'] ?? ''), 255);
                if (OfflineQueue::resolve($id, $me, $note)) {
                    Logger::audit('offline.resolve', 'offline_request', (string) $id, null, ['note' => $note], 'by admin #' . $me);
                }
                $flash = ['ok', 'Noted. / लेखियो।'];
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$on   = OfflineQueue::enabled();
$rows = [];
$done = [];
if ($on) {
    $rows = OfflineQueue::needingAttention($isOffice ? null : $me, 200);
    $done = Database::fetchAll(
        "SELECT r.*, a.full_name AS admin_name FROM offline_requests r LEFT JOIN admins a ON a.id = r.admin_id
          WHERE r.received_at >= :s" . ($isOffice ? '' : ' AND r.admin_id = :a') . "
          ORDER BY r.received_at DESC LIMIT 60",
        ['s' => date('Y-m-d H:i:s', strtotime('-14 days'))] + ($isOffice ? [] : ['a' => $me])
    );
}

admin_header('Offline Desk', 'offline-requests');
?>

<style>
  .oq-card{border:1px solid var(--line);border-left:5px solid var(--bad);border-radius:var(--r-lg,14px);background:var(--card);padding:14px 16px;margin:0 0 12px}
  .oq-card.money{border-left-color:var(--warn)}
  .oq-card h3{margin:0 0 4px;font-size:15.5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .oq-card p{margin:0 0 8px;font-size:13.5px;line-height:1.5;overflow-wrap:anywhere}
  .oq-tag{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:3px 9px;border-radius:999px;background:var(--bad-bg);color:var(--bad)}
  .money .oq-tag{background:var(--warn-bg);color:var(--warn)}
  .oq-card form{display:flex;gap:8px;flex-wrap:wrap}
  .oq-card input[type=text]{flex:1 1 220px;min-height:44px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:14px}
  .oq-card .btn{min-height:44px}
  .oq-big{min-height:54px;padding:0 24px;font-size:16px;font-weight:800;border-radius:12px;display:inline-flex;align-items:center;gap:8px}
  .oq-pill{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:999px;white-space:nowrap}
  .oq-pill.ticketed{background:var(--ok-bg);color:var(--ok)} .oq-pill.failed{background:var(--bad-bg);color:var(--bad)} .oq-pill.received{background:var(--info-bg);color:var(--info)}
</style>

<?php if ($flash !== null): ?>
  <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<div class="panel">
  <h2>When the internet is down · इन्टरनेट नभएको बेला</h2>
  <p class="muted" style="line-height:1.6;max-width:760px">The offline desk lets a counter keep serving passengers with no internet. It writes a <b>request</b> on the phone — name, mobile, pickup, how many seats, money taken — and gives the passenger a slip. It does <b>not</b> pick a seat. When the internet is back the phone sends the requests by itself and the server gives each one a seat and a real ticket, once. If a bus was full, the request shows up below in red.</p>
  <?php if ($on): ?>
    <p><a class="btn oq-big" href="<?= $base ?>/offline-desk.html" target="_blank" rel="noopener">🧾 Open the offline desk</a></p>
    <p class="muted" style="font-size:13px">Open it once <b>now</b>, while the internet works, and add it to the phone's home screen — then it opens even with no signal. · अहिले नै एक पटक खोल्नुहोस् र फोनको होम स्क्रिनमा राख्नुहोस्।</p>
  <?php elseif ($isOffice): ?>
    <form method="post">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="enable">
      <button type="submit" class="btn oq-big" style="background:var(--ok);border-color:var(--ok);color:#fff">Switch ON · चालु गर्नुहोस्</button>
    </form>
  <?php else: ?>
    <p><b>It is switched off. Ask the office to switch it on. / अफिसलाई चालु गर्न भन्नुहोस्।</b></p>
  <?php endif; ?>
</div>

<?php if ($on): ?>
  <div class="panel">
    <h2>Needs a person <?= $rows === [] ? '' : '(' . count($rows) . ')' ?></h2>
    <?php if ($rows === []): ?>
      <p class="muted">Nothing waiting. Every offline request was seated, and the money matched. · सबै ठिक छ।</p>
    <?php endif; ?>
    <?php foreach ($rows as $r):
      $money = (string) $r['status'] === 'ticketed'; ?>
      <div class="oq-card <?= $money ? 'money' : '' ?>">
        <h3><span class="oq-tag"><?= $money ? 'Money does not match' : 'Not seated' ?></span>
          <?= Security::e((string) $r['name']) ?> · <?= (int) $r['seats'] ?> seat · <?= Security::e((string) ($r['travel_date'] ?? '')) ?></h3>
        <p>
          <?php if ($money): ?>
            Took <b><?= Security::e(inr((float) $r['cash_taken'])) ?></b>, ticket <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a> cost <b><?= Security::e(inr((float) $r['total_amount'])) ?></b>.
          <?php else: ?>
            <?= Security::e((string) ($r['fail_reason'] ?? 'The server could not issue this ticket.')) ?>
            Money taken: <b><?= Security::e(inr((float) $r['cash_taken'])) ?></b>.
          <?php endif; ?>
          <br><span class="muted" style="font-size:12.5px">Slip <?= Security::e(strtoupper(substr((string) $r['uuid'], 0, 8))) ?>
            · <?= Security::e((string) ($r['boarding'] ?? '')) ?>
            <?php if ($isOffice): ?> · desk: <?= Security::e((string) ($r['admin_name'] ?? ('#' . $r['admin_id']))) ?><?php endif; ?>
            · received <?= Security::e(substr((string) $r['received_at'], 0, 16)) ?></span>
        </p>
        <form method="post">
          <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
          <input type="hidden" name="action" value="resolve">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="text" name="note" required minlength="3" maxlength="255" placeholder="What was done? e.g. sold by hand as SHG-…, money returned · के गरियो?">
          <button type="submit" class="btn">Done · भयो</button>
          <?php if (!$money): ?><a class="btn ghost" href="<?= $base ?>/admin/quick-ticket.php">Sell by hand</a><?php endif; ?>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($done !== []): ?>
  <div class="panel">
    <h2>Last 14 days</h2>
    <div class="dt-wrap">
      <table class="dt card-table">
        <thead><tr><?php if ($isOffice): ?><th>Desk</th><?php endif; ?><th>Received</th><th>Passenger</th><th data-type="num">Seats</th><th>Travel</th><th data-type="num">Money taken</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($done as $d): ?>
          <tr>
            <?php if ($isOffice): ?><td data-label="Desk"><?= Security::e((string) ($d['admin_name'] ?? ('#' . $d['admin_id']))) ?></td><?php endif; ?>
            <td data-label="Received" data-sort="<?= Security::e((string) $d['received_at']) ?>"><?= Security::e(substr((string) $d['received_at'], 0, 16)) ?></td>
            <td data-label="Passenger"><?= Security::e((string) $d['name']) ?></td>
            <td data-label="Seats" class="num"><?= (int) $d['seats'] ?></td>
            <td data-label="Travel"><?= Security::e((string) ($d['travel_date'] ?? '')) ?></td>
            <td data-label="Money taken" class="num"><?= Security::e(inr((float) $d['cash_taken'])) ?></td>
            <td data-label="Result"><span class="oq-pill <?= Security::e((string) $d['status']) ?>"><?= Security::e((string) $d['status']) ?></span>
              <?php if (!empty($d['pnr'])): ?> <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $d['pnr']) ?>" style="font-size:12.5px"><?= Security::e((string) $d['pnr']) ?></a><?php endif; ?>
              <?php if (!empty($d['resolve_note'])): ?><br><span class="muted" style="font-size:12px">✓ <?= Security::e((string) $d['resolve_note']) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($isOffice): ?>
    <form method="post" style="margin:6px 0 0" onsubmit="return confirm('Switch the offline desk OFF for every counter?')">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="disable">
      <button type="submit" class="btn ghost" style="font-size:12.5px">Switch this feature off</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
