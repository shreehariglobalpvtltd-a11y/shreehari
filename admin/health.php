<?php
/**
 * admin/health.php — what is wrong RIGHT NOW, in one screen.
 *
 * First reader of health_incidents. Four monitors have been writing cards
 * there since 8 Sep 2026 (delivery sentinel, cron heartbeat, gem hygiene, and
 * from 19 Sep the night data audit) and nothing ever showed them: a monitor
 * nobody can see is a log file with extra steps.
 *
 * The page is a LIST OF THINGS TO DO, not a dashboard. Each card says what is
 * wrong in plain words, what to do about it, and links the bookings involved.
 * When nothing is open it says so in one green line and gets out of the way.
 *
 * Writes (both CSRF-guarded, both audited):
 *   ack     — "I have seen this". The card stays until the monitor that
 *             raised it sees the fault gone; a person cannot close a fault
 *             by clicking, only by fixing it.
 *   recheck — run the data audit now instead of waiting for tonight. The
 *             audit is read-only (includes/dataaudit.php); this only
 *             refreshes the cards.
 *
 * Gated on dashboard.view — company-wide, not scoped to a seller, so counter
 * agents (bookings.view only) never see it.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

require_once INCLUDE_PATH . '/health.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/dataaudit.php';

$base  = '';   // root-relative: the panel must stay on the request host
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'ack') {
            $id = (int) ($_POST['id'] ?? 0);
            $n  = $id > 0 ? Database::update('health_incidents', ['status' => 'ack'], "id = :id AND status = 'open'", ['id' => $id]) : 0;
            if ($n > 0) {
                Logger::audit('health.ack', 'health_incident', (string) $id, ['status' => 'open'], ['status' => 'ack'], 'seen by admin #' . $admin['id']);
            }
            $flash = ['ok', 'Marked as seen. The card clears by itself once the fault is gone.'];
        } elseif ($act === 'recheck') {
            $r = DataAudit::runAndReport();
            Logger::audit('health.recheck', 'health', 'data_audit', null, $r, 'data audit run by admin #' . $admin['id']);
            $flash = $r['findings'] === 0
                ? ['ok', 'Checked just now: all ' . $r['checks'] . ' data checks passed.']
                : ['bad', 'Checked just now: ' . $r['findings'] . ' of ' . $r['checks'] . ' data checks found a problem — see the cards below.'];
        } else {
            $flash = ['bad', 'Unknown action.'];
        }
    }
}

$sum   = Health::summary();
$cards = Health::open_list(100);
$beats = Health::heartbeats();

/* Booking ids -> PNR in ONE query, so a card can link straight to the
   booking (booking-view.php is keyed by PNR). */
$ids = [];
foreach ($cards as $c) {
    foreach (preg_split('/[^0-9]+/', (string) ($c['sample_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i) {
        $ids[(int) $i] = true;
    }
}
$pnrOf = [];
if ($ids !== []) {
    $in = implode(',', array_map('intval', array_keys($ids)));   // ints only, built above
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE id IN ({$in})") as $b) {
        $pnrOf[(int) $b['id']] = (string) $b['pnr'];
    }
}

$lastAudit = null;
foreach ($beats as $b) {
    if ((string) $b['job'] === 'data-audit.php') { $lastAudit = $b; }
}

/** "5 min ago" / "3 h ago" / "2 d ago" */
function hl_ago(?int $min): string
{
    if ($min === null) { return '—'; }
    if ($min < 1)      { return 'just now'; }
    if ($min < 120)    { return $min . ' min ago'; }
    if ($min < 2880)   { return round($min / 60) . ' h ago'; }
    return round($min / 1440) . ' d ago';
}

admin_header('System Health', 'health');
?>

<style>
  .hl-ok{display:flex;gap:12px;align-items:center;padding:16px 18px;border-radius:var(--r-lg,14px);background:var(--ok-bg);color:var(--ok);font-weight:700}
  .hl-ok .a-ic{width:26px;height:26px;flex:0 0 auto}
  .hl-card{border:1px solid var(--line);border-left:5px solid var(--warn);border-radius:var(--r-lg,14px);background:var(--card);padding:14px 16px;margin:0 0 12px}
  .hl-card.critical{border-left-color:var(--bad)} .hl-card.info{border-left-color:var(--info)}
  .hl-card h3{margin:0 0 6px;font-size:15.5px;line-height:1.35;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .hl-sev{font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:999px;background:var(--warn-bg);color:var(--warn)}
  .critical .hl-sev{background:var(--bad-bg);color:var(--bad)} .info .hl-sev{background:var(--info-bg);color:var(--info)}
  .hl-card p{margin:0 0 8px;font-size:13.5px;line-height:1.5;overflow-wrap:anywhere}
  .hl-fix{white-space:pre-line;background:var(--soft);border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.55;margin:0 0 10px}
  .hl-fix b{display:block;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);margin-bottom:3px}
  .hl-ids{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
  .hl-ids a{font-size:12.5px;padding:6px 10px;border:1px solid var(--line);border-radius:999px;text-decoration:none;min-height:32px;display:inline-flex;align-items:center}
  .hl-meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:12px;color:var(--mut)}
  .hl-meta form{margin:0 0 0 auto}
  .hl-top{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:0 0 12px}
  .hl-top form{margin:0} .hl-top .btn{min-height:44px}
</style>

<?php if ($flash !== null): ?>
  <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>">
    <?= Security::e($flash[1]) ?>
  </p>
<?php endif; ?>

<div class="hl-top">
  <span class="muted" style="font-size:13px">
    Data audit last ran: <b><?= $lastAudit === null ? 'never' : Security::e(hl_ago($lastAudit['age_min'])) ?></b>
    <?php if ($lastAudit === null): ?> — press the button to run it now.<?php endif; ?>
  </span>
  <form method="post">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
    <input type="hidden" name="action" value="recheck">
    <button type="submit" class="btn"><svg class="a-ic"><use href="#a-refresh"/></svg> Check data now</button>
  </form>
</div>

<?php if ($sum['total'] === 0): ?>
  <div class="hl-ok"><svg class="a-ic"><use href="#a-check-circle"/></svg>
    <span>All clear — seats, payments, agent commission, messages and scheduled jobs have nothing to report.</span></div>
<?php else: ?>
  <div class="dash-hero">
    <div class="hcard"><span>Fix today</span><b style="color:<?= $sum['critical'] > 0 ? 'var(--bad)' : 'inherit' ?>"><?= $sum['critical'] ?></b><small>critical</small></div>
    <div class="hcard"><span>Look at this week</span><b style="color:<?= $sum['warn'] > 0 ? 'var(--warn)' : 'inherit' ?>"><?= $sum['warn'] ?></b><small>warnings</small></div>
    <div class="hcard"><span>For information</span><b><?= $sum['info'] ?></b><small>no action needed</small></div>
  </div>

  <div class="panel">
    <h2>What needs attention</h2>
    <?php foreach ($cards as $c):
      $sev = (string) $c['severity'];
      $cid = preg_split('/[^0-9]+/', (string) ($c['sample_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []; ?>
      <div class="hl-card <?= Security::e($sev) ?>">
        <h3><span class="hl-sev"><?= $sev === 'critical' ? 'Fix today' : ($sev === 'warn' ? 'Warning' : 'Info') ?></span>
            <?= Security::e((string) $c['title']) ?></h3>
        <?php if ((string) ($c['detail'] ?? '') !== ''): ?><p><?= Security::e((string) $c['detail']) ?></p><?php endif; ?>
        <?php if ((string) ($c['fix_steps'] ?? '') !== ''): ?>
          <div class="hl-fix"><b>What to do</b><?= Security::e((string) $c['fix_steps']) ?></div>
        <?php endif; ?>
        <?php if ($cid !== []): ?>
          <div class="hl-ids">
            <?php foreach ($cid as $i): $i = (int) $i; ?>
              <?php if (isset($pnrOf[$i])): ?>
                <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($pnrOf[$i]) ?>"><?= Security::e($pnrOf[$i]) ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="hl-meta">
          <span>First seen <?= Security::e(substr((string) $c['first_seen_at'], 0, 16)) ?></span>
          <span>Last seen <?= Security::e(substr((string) $c['last_seen_at'], 0, 16)) ?></span>
          <span>Seen <?= (int) $c['occurrences'] ?>×</span>
          <?php if ((string) $c['status'] === 'ack'): ?>
            <span class="pill">Seen by office</span>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
              <input type="hidden" name="action" value="ack">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn ghost" style="font-size:12.5px;padding:8px 12px;min-height:36px">I have seen this</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="panel">
  <h2>Scheduled jobs</h2>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Job</th><th>Last ran</th><th data-type="num">Took</th><th>State</th></tr></thead>
      <tbody>
      <?php if ($beats === []): ?>
        <tr><td colspan="4" class="muted" style="text-align:center;padding:18px">No job has reported yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($beats as $b): $failing = (int) ($b['fail_streak'] ?? 0) > 0; ?>
        <tr>
          <td data-label="Job"><?= Security::e((string) $b['job']) ?></td>
          <td data-label="Last ran" data-sort="<?= Security::e((string) $b['last_run_at']) ?>"><?= Security::e(hl_ago($b['age_min'])) ?></td>
          <td data-label="Took" data-sort="<?= (int) $b['last_ms'] ?>"><?= number_format((int) $b['last_ms']) ?> ms</td>
          <td data-label="State"><span class="pill" style="background:<?= $failing ? 'var(--bad-bg)' : 'var(--ok-bg)' ?>;color:<?= $failing ? 'var(--bad)' : 'var(--ok)' ?>"><?= $failing ? 'failing ×' . (int) $b['fail_streak'] : 'ok' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
