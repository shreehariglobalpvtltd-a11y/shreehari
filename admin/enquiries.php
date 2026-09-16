<?php
/**
 * admin/enquiries.php — homepage "Quick Booking" leads.
 *
 * Every visitor who fills the homepage passenger card (no OTP, save-only)
 * lands here so staff can call / WhatsApp them and mark progress.
 *
 * Gated on customers.view, not bookings.view. An enquiry is an unclaimed
 * company lead — there is no seller column to scope it by — so the whole
 * board is the company's prospect list. Counter agents hold bookings.view
 * and would otherwise walk away with it; they are not granted customers.view.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('customers.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash = null;

/* ---- Handle a status update ------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $id     = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? '');
        if ($id > 0 && in_array($status, ['new', 'contacted', 'converted', 'closed'], true)) {
            $was = (string) Database::scalar('SELECT status FROM enquiries WHERE id = :id', ['id' => $id], '');
            Database::update('enquiries', ['status' => $status], 'id = :id', ['id' => $id]);
            if ($was !== $status) {
                Logger::audit('enquiry.status', 'enquiry', (string) $id, ['status' => $was], ['status' => $status], 'lead status changed');
            }
            $flash = ['ok', 'Enquiry #' . $id . ' marked ' . $status . '.'];
        } else {
            $flash = ['bad', 'Unknown action.'];
        }
    }
}

/* ---- Filters ----------------------------------------------------- */
$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$valid  = ['new', 'contacted', 'converted', 'closed'];

/* Which desk the lead came from. 'agent_apply' is someone asking to become a
   counter agent — the office phones them and creates the account by hand. */
$source   = Security::clean($_GET['source'] ?? '', 20);
$sources  = ['quick_booking' => 'Booking enquiry', 'agent_apply' => 'Agent application', 'complaint' => 'Complaint'];

/* Date window. The office wanted to narrow the inbox to a day, a few days or a
   month without typing dates. 0 = no limit. */
$daysIn = $_GET['days'] ?? '';
$dayOpts = [1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
$days    = (is_numeric($daysIn) && isset($dayOpts[(int) $daysIn])) ? (int) $daysIn : 0;

$where  = [];
$params = [];
if ($q !== '') {
    // Two SEPARATE placeholders: this connection does not emulate prepares, so
    // re-using one name across both LIKEs raises SQLSTATE[HY093] and the search
    // dies. `%` and `_` are escaped so a typed wildcard stays literal.
    $like         = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[]      = '(name LIKE :q1 OR phone LIKE :q2)';
    $params['q1'] = $like;
    $params['q2'] = $like;
}
if (in_array($status, $valid, true)) {
    $where[] = 'status = :st';
    $params['st'] = $status;
}
if (isset($sources[$source])) {
    $where[] = 'source = :src';
    $params['src'] = $source;
}
if ($days > 0) {
    // Whole days back from midnight, so "Today" means today's calendar day.
    $where[] = 'created_at >= (CURDATE() - INTERVAL :days DAY)';
    $params['days'] = $days - 1;
}
$sql = 'SELECT * FROM enquiries'
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY id DESC LIMIT 300';
$rows = Database::fetchAll($sql, $params);

$counts = Database::fetchAll('SELECT status, COUNT(*) AS n FROM enquiries GROUP BY status');
$byStatus = [];
foreach ($counts as $c) { $byStatus[$c['status']] = (int) $c['n']; }
$total = array_sum($byStatus);

$cc = Settings::getString('whatsapp_default_country', '91');

/** Local coloured pill for the four enquiry states. */
$pill = static function (string $s): string {
    $map = [
        'new'       => ['#8a6d00', '#fff4d1', 'New'],
        'contacted' => ['#1c4e80', '#dfeaf9', 'Contacted'],
        'converted' => ['#0a6b3b', '#d7f4e3', 'Converted'],
        'closed'    => ['#555',    '#e7e7e7', 'Closed'],
    ];
    [$fg, $bg, $label] = $map[$s] ?? ['#333', '#eee', ucfirst($s)];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . $label . '</span>';
};

/** wa.me needs a country code; local 10-digit numbers get the default. */
$wa = static function (string $digits) use ($cc): string {
    $d = preg_replace('/\D+/', '', $digits) ?? '';
    if (strlen($d) === 10) { $d = $cc . $d; }
    return $d;
};

admin_header('Enquiries', 'enquiries');
?>
<?php if ($flash): ?><div class="flash <?= $flash[0] ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>

<div class="cards">
  <div class="card"><div class="k">Total leads</div><div class="v"><?= $total ?></div></div>
  <div class="card"><div class="k">New</div><div class="v"><?= $byStatus['new'] ?? 0 ?></div></div>
  <div class="card"><div class="k">Contacted</div><div class="v"><?= $byStatus['contacted'] ?? 0 ?></div></div>
  <div class="card"><div class="k">Converted</div><div class="v" style="color:#0a6b3b"><?= $byStatus['converted'] ?? 0 ?></div></div>
</div>

<form class="toolbar" method="get">
  <input type="search" name="q" placeholder="Name or phone" value="<?= Security::e($q) ?>" style="min-width:240px">
  <select name="status">
    <option value="">All statuses</option>
    <?php foreach ($valid as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="source">
    <option value="">All types</option>
    <?php foreach ($sources as $sv => $slabel): ?>
      <option value="<?= Security::e($sv) ?>" <?= $source === $sv ? 'selected' : '' ?>><?= Security::e($slabel) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="days">
    <option value="">Any time</option>
    <?php foreach ($dayOpts as $dv => $dlabel): ?>
      <option value="<?= (int) $dv ?>" <?= $days === $dv ? 'selected' : '' ?>><?= Security::e($dlabel) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $status !== '' || $source !== '' || $days > 0): ?><a class="btn ghost" href="<?= $base ?>/admin/enquiries.php">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 300 ? ' (showing latest 300)' : '' ?></h2>
  <table>
    <thead><tr>
      <th>Passenger</th><th>Contact</th><th>Trip</th><th>Note</th><th>Status</th><th>Received</th><th>Update</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="7" class="muted" style="padding:22px;text-align:center">No enquiries yet.</td></tr>
    <?php else: foreach ($rows as $r):
        $meta = array_filter([
            $r['gender'] ?? '',
            !empty($r['age']) ? $r['age'] . ' yrs' : '',
            $r['nationality'] ?? '',
        ]);
        $waNum = $wa((string) $r['phone']);
    ?>
      <tr>
        <td>
          <b><?= Security::e($r['name']) ?></b>
          <?php if ($meta): ?><div class="muted" style="font-size:12px"><?= Security::e(implode(' · ', $meta)) ?></div><?php endif; ?>
        </td>
        <td class="mono">
          <a href="tel:+<?= Security::e($waNum) ?>">📞 <?= Security::e($r['phone']) ?></a>
          <div><a href="https://wa.me/<?= Security::e($waNum) ?>" target="_blank" rel="noopener" style="color:#0a6b3b">💬 WhatsApp</a></div>
        </td>
        <td>
          <?= $r['travel_date'] ? Security::e(formatDate($r['travel_date'])) : '<span class="muted">—</span>' ?>
          <div class="muted" style="font-size:12px"><?= (int) $r['seats'] ?> seat<?= (int) $r['seats'] === 1 ? '' : 's' ?></div>
        </td>
        <td style="max-width:220px"><?= $r['note'] ? Security::e($r['note']) : '<span class="muted">—</span>' ?></td>
        <td><?= $pill((string) $r['status']) ?></td>
        <td class="muted"><?= Security::e(timeAgo((string) $r['created_at'])) ?></td>
        <td>
          <form method="post" class="row-actions" style="gap:6px">
            <?= Security::csrfField() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <?php if ($r['status'] === 'new'): ?>
              <button class="btn" name="status" value="contacted">Contacted</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'converted'): ?>
              <button class="btn ok" name="status" value="converted">Converted</button>
            <?php endif; ?>
            <?php if ($r['status'] !== 'closed'): ?>
              <button class="btn ghost" name="status" value="closed">Close</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php admin_footer(); ?>
