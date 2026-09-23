<?php
/**
 * admin/complaints.php — the Help bot's complaint board (23 Sep 2026).
 *
 * Every complaint the bot files (includes/complaints.php) lands here with
 * its ticket number, category, booking and the passenger's own words, so
 * the office can call / WhatsApp them and move it Open → In progress →
 * Resolved. Gated on customers.view like Enquiries: a complaint is company
 * business, not a seller's.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/complaints.php';
$admin = admin_boot('customers.view');

$base  = '';
$flash = null;

/* ---- Status / note update ---------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $id     = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        $note   = isset($_POST['note']) ? (string) $_POST['note'] : null;
        if ($id > 0 && in_array($status, Complaints::STATUSES, true)
            && Complaints::setStatus($id, $status, $note, (int) ($admin['id'] ?? 0) ?: null)) {
            $flash = ['ok', 'Complaint #' . $id . ' marked ' . str_replace('_', ' ', $status) . '.'];
        } else {
            $flash = ['bad', 'Unknown action.'];
        }
    }
}

/* ---- Filters ----------------------------------------------------- */
$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$cat    = Security::clean($_GET['cat'] ?? '', 20);
$daysIn = $_GET['days'] ?? '';
$dayOpts = [1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
$days    = (is_numeric($daysIn) && isset($dayOpts[(int) $daysIn])) ? (int) $daysIn : 0;

$where  = [];
$params = [];
if ($q !== '') {
    $like         = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[]      = '(name LIKE :q1 OR phone LIKE :q2 OR ticket_no LIKE :q3 OR pnr LIKE :q4)';
    $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like; $params['q4'] = $like;
}
if (in_array($status, Complaints::STATUSES, true)) { $where[] = 'status = :st';   $params['st']  = $status; }
if (in_array($cat, Complaints::CATEGORIES, true))  { $where[] = 'category = :ct'; $params['ct']  = $cat; }
if ($days > 0) { $where[] = 'created_at >= (CURDATE() - INTERVAL :days DAY)'; $params['days'] = $days - 1; }

$rows = [];
$byStatus = [];
$tableMissing = false;
try {
    $rows = Database::fetchAll('SELECT * FROM complaints'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY FIELD(status, "open", "in_progress", "resolved"), id DESC LIMIT 300', $params);
    foreach (Database::fetchAll('SELECT status, COUNT(*) AS n FROM complaints GROUP BY status') as $c) {
        $byStatus[$c['status']] = (int) $c['n'];
    }
} catch (Throwable $e) {
    $tableMissing = true;   // migration not applied yet - say so instead of a white page
}
$total = array_sum($byStatus);
$cc = Settings::getString('whatsapp_default_country', '91');
$wa = static function (string $digits) use ($cc): string {
    $d = preg_replace('/\D+/', '', $digits) ?? '';
    if (strlen($d) === 10) { $d = $cc . $d; }
    return $d;
};
$pill = static function (string $s): string {
    $map = [
        'open'        => ['#8a1f1f', '#f7dcdc', 'Open'],
        'in_progress' => ['#8a6d00', '#fff4d1', 'In progress'],
        'resolved'    => ['#0a6b3b', '#d7f4e3', 'Resolved'],
    ];
    [$fg, $bg, $label] = $map[$s] ?? ['#333', '#eee', ucfirst($s)];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . $label . '</span>';
};

admin_header('Complaints', 'complaints');
?>
<?php if ($flash): ?><div class="flash <?= $flash[0] ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>
<?php if (!Complaints::enabled()): ?>
  <div class="flash bad">The complaint desk is <b>switched off</b> (setting <code>complaints_on</code>). The Help bot files complaints into <a href="<?= $base ?>/admin/enquiries.php?source=complaint">Enquiries</a> until it is on.</div>
<?php endif; ?>
<?php if ($tableMissing): ?>
  <div class="flash bad">The <code>complaints</code> table is missing — apply <code>database/upgrade-2026-09-23-complaints.sql</code>.</div>
<?php endif; ?>

<div class="cards">
  <div class="card"><div class="k">Total</div><div class="v"><?= $total ?></div></div>
  <div class="card"><div class="k">Open</div><div class="v" style="color:#8a1f1f"><?= $byStatus['open'] ?? 0 ?></div></div>
  <div class="card"><div class="k">In progress</div><div class="v" style="color:#8a6d00"><?= $byStatus['in_progress'] ?? 0 ?></div></div>
  <div class="card"><div class="k">Resolved</div><div class="v" style="color:#0a6b3b"><?= $byStatus['resolved'] ?? 0 ?></div></div>
</div>

<form class="toolbar" method="get">
  <input type="search" name="q" placeholder="Ticket, PNR, name or phone" value="<?= Security::e($q) ?>" style="min-width:240px">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach (Complaints::STATUSES as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="cat">
    <option value="">All categories</option>
    <?php foreach (Complaints::CATEGORIES as $c): ?>
      <option value="<?= $c ?>" <?= $cat === $c ? 'selected' : '' ?>><?= Security::e(Complaints::categoryLabel($c)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="days">
    <option value="">Any time</option>
    <?php foreach ($dayOpts as $dv => $dlabel): ?>
      <option value="<?= (int) $dv ?>" <?= $days === $dv ? 'selected' : '' ?>><?= Security::e($dlabel) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $status !== '' || $cat !== '' || $days > 0): ?><a class="btn ghost" href="<?= $base ?>/admin/complaints.php">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2><?= count($rows) ?> complaint<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 300 ? ' (showing latest 300)' : '' ?></h2>
  <table>
    <thead><tr>
      <th>Ticket</th><th>Passenger</th><th>Booking</th><th>Complaint</th><th>Status</th><th>Received</th><th>Update</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="7" class="muted" style="padding:22px;text-align:center">No complaints. 🙏</td></tr>
    <?php else: foreach ($rows as $r): $waNum = $wa((string) $r['phone']); ?>
      <tr>
        <td class="mono"><b><?= Security::e($r['ticket_no']) ?></b>
          <div class="muted" style="font-size:11px"><?= $r['wa_sent'] ? '✅ office WhatsApp sent' : '🔗 wa.me link offered' ?></div></td>
        <td>
          <b><?= Security::e($r['name']) ?></b>
          <div class="mono" style="font-size:12px"><a href="tel:+<?= Security::e($waNum) ?>">📞 <?= Security::e($r['phone']) ?></a>
            · <a href="https://wa.me/<?= Security::e($waNum) ?>?text=<?= rawurlencode('Namaste, S Hari Global here about your complaint ' . $r['ticket_no'] . '.') ?>" target="_blank" rel="noopener" style="color:#0a6b3b">💬 WhatsApp</a></div>
        </td>
        <td class="mono"><?= $r['pnr'] ? '<a href="' . $base . '/admin/booking-view.php?pnr=' . rawurlencode((string) $r['pnr']) . '">' . Security::e($r['pnr']) . '</a>' : '<span class="muted">—</span>' ?></td>
        <td style="max-width:300px">
          <b><?= Security::e(Complaints::categoryLabel((string) $r['category'])) ?></b> <span class="muted" style="font-size:11px">· <?= strtoupper(Security::e((string) $r['lang'])) ?></span>
          <div style="font-size:13px;white-space:pre-wrap"><?= Security::e($r['message']) ?></div>
          <?php if (!empty($r['admin_note'])): ?><div class="muted" style="font-size:12px;margin-top:4px">📝 <?= Security::e($r['admin_note']) ?></div><?php endif; ?>
        </td>
        <td><?= $pill((string) $r['status']) ?><?php if (!empty($r['resolved_at'])): ?><div class="muted" style="font-size:11px"><?= Security::e(timeAgo((string) $r['resolved_at'])) ?></div><?php endif; ?></td>
        <td class="muted"><?= Security::e(timeAgo((string) $r['created_at'])) ?></td>
        <td>
          <form method="post" class="row-actions" style="gap:6px;flex-wrap:wrap">
            <?= Security::csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input name="note" placeholder="Note (optional)" value="<?= Security::e((string) ($r['admin_note'] ?? '')) ?>" maxlength="500" style="min-width:150px">
            <?php if ($r['status'] !== 'in_progress'): ?><button class="btn" name="status" value="in_progress">In progress</button><?php endif; ?>
            <?php if ($r['status'] !== 'resolved'): ?><button class="btn ok" name="status" value="resolved">Resolved</button><?php endif; ?>
            <?php if ($r['status'] !== 'open'): ?><button class="btn ghost" name="status" value="open">Reopen</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php admin_footer(); ?>
