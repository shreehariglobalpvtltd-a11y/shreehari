<?php
/**
 * admin/compliance.php — Fleet & crew document expiry at a glance.
 *
 * One screen that answers "what is about to lapse?" for a cross-border fleet,
 * where an expired permit / insurance / fitness certificate or a lapsed driver
 * licence can stop a bus at the border (fines + lost revenue). It reads the
 * dates ALREADY stored on buses (insurance_exp / permit_exp / fitness_exp) and
 * drivers (licence_exp), flags each as EXPIRED / expiring soon (<= 30 days) /
 * OK with days-left, and sorts the most urgent first. Links to Bus Fleet and
 * Drivers to renew.
 *
 * Read-only: no new storage, no POST, moves no money. Shows minimal crew info
 * (name, role, licence expiry only — no phone / licence number). Gated on
 * schedules.manage (fleet-management level; a counter agent does not hold it).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('schedules.manage');

$base   = '';
$SOON   = 30;   // days: the "expiring soon" window
$today  = new DateTimeImmutable('today');
// Crew licence data (even just name + expiry) rides the drivers.view gate too,
// so an account with schedules.manage but not drivers.view sees only bus docs.
$canCrew = Auth::can('drivers.view');

/**
 * Build one document row with its expiry status. A missing/invalid date is
 * flagged 'none' (not recorded) and sorted to the bottom.
 *
 * @return array{kind:string,name:string,sub:string,doc:string,exp:?string,days:?int,status:string,link:string,sort:int}
 */
function compliance_row(string $kind, string $name, string $sub, string $doc, ?string $exp, DateTimeImmutable $today, string $link, int $soon): array
{
    $exp = (string) ($exp ?? '');
    if ($exp === '' || !Security::isValidDate($exp)) {
        return ['kind' => $kind, 'name' => $name, 'sub' => $sub, 'doc' => $doc,
                'exp' => null, 'days' => null, 'status' => 'none', 'link' => $link, 'sort' => PHP_INT_MAX];
    }
    $days   = (int) $today->diff(new DateTimeImmutable($exp))->format('%r%a');   // signed days from today
    $status = $days < 0 ? 'expired' : ($days <= $soon ? 'soon' : 'ok');
    return ['kind' => $kind, 'name' => $name, 'sub' => $sub, 'doc' => $doc,
            'exp' => $exp, 'days' => $days, 'status' => $status, 'link' => $link, 'sort' => $days];
}

$items = [];

/* --- Buses: insurance / permit / fitness --- */
$busDocs = ['insurance_exp' => 'Insurance', 'permit_exp' => 'Permit', 'fitness_exp' => 'Fitness'];
foreach (Database::fetchAll(
    "SELECT bus_name, bus_number, insurance_exp, permit_exp, fitness_exp
       FROM buses WHERE is_active = 1 ORDER BY bus_name"
) as $b) {
    foreach ($busDocs as $col => $docLabel) {
        $items[] = compliance_row(
            'bus', (string) $b['bus_name'], (string) ($b['bus_number'] ?? ''),
            $docLabel, $b[$col] ?? null, $today, $base . '/admin/buses.php', $SOON
        );
    }
}

/* --- Drivers / crew: licence (only for viewers who may see crew data) --- */
if ($canCrew) {
    foreach (Database::fetchAll(
        "SELECT full_name, role, licence_exp FROM drivers WHERE is_active = 1 ORDER BY full_name"
    ) as $d) {
        $items[] = compliance_row(
            'crew', (string) $d['full_name'], ucfirst((string) ($d['role'] ?? 'driver')),
            'Licence', $d['licence_exp'] ?? null, $today, $base . '/admin/drivers.php', $SOON
        );
    }
}

/* Most urgent first (most-negative days-left on top); 'not recorded' last. */
usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

$counts = ['expired' => 0, 'soon' => 0, 'ok' => 0, 'none' => 0];
foreach ($items as $it) { $counts[$it['status']]++; }

/* Default view: only what needs attention (expired + expiring). ?show=all for everything. */
$show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'attention';

$pill = static function (string $status): string {
    [$txt, $bg, $fg] = match ($status) {
        'expired' => ['EXPIRED',  '#fbebeb', '#c0392b'],
        'soon'    => ['Expiring', '#f9eeda', '#8a6d00'],
        'ok'      => ['OK',       '#e5f4e4', '#0a6b3b'],
        default   => ['Not set',  '#f0efec', '#666'],
    };
    return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:' . $bg . ';color:' . $fg . '">' . $txt . '</span>';
};

admin_header('Documents & Expiry', 'compliance');
?>
<div class="cards">
  <div class="card"><div class="k">Expired</div><div class="v" style="color:<?= $counts['expired'] > 0 ? '#c0392b' : 'inherit' ?>"><?= $counts['expired'] ?></div></div>
  <div class="card"><div class="k">Expiring ≤ <?= $SOON ?> days</div><div class="v" style="color:<?= $counts['soon'] > 0 ? '#8a6d00' : 'inherit' ?>"><?= $counts['soon'] ?></div></div>
  <div class="card"><div class="k">OK</div><div class="v"><?= $counts['ok'] ?></div></div>
  <div class="card"><div class="k">Not recorded</div><div class="v"><?= $counts['none'] ?></div></div>
</div>

<div class="toolbar">
  <a class="btn <?= $show === 'attention' ? '' : 'ghost' ?>" href="?show=attention">Needs attention (<?= $counts['expired'] + $counts['soon'] ?>)</a>
  <a class="btn <?= $show === 'all' ? '' : 'ghost' ?>" href="?show=all">All documents (<?= count($items) ?>)</a>
</div>

<div class="panel">
  <h2><?= $show === 'all' ? 'All fleet &amp; crew documents' : 'Documents expired or expiring soon' ?></h2>
  <table>
    <thead><tr>
      <th>Bus / Crew</th><th>Document</th><th>Expiry</th><th style="text-align:right">Days left</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php
    $shown = 0;
    foreach ($items as $it):
      if ($show !== 'all' && !in_array($it['status'], ['expired', 'soon'], true)) { continue; }
      $shown++;
    ?>
      <tr>
        <td>
          <?= $it['kind'] === 'bus' ? '🚌 ' : '👤 ' ?><?= Security::e($it['name']) ?>
          <?php if ($it['sub'] !== ''): ?><div class="muted" style="font-size:11px"><?= Security::e($it['sub']) ?></div><?php endif; ?>
        </td>
        <td><?= Security::e($it['doc']) ?></td>
        <td><?= $it['exp'] === null ? '<span class="muted">—</span>' : Security::e(formatDate($it['exp'])) ?></td>
        <td style="text-align:right" class="mono">
          <?php if ($it['days'] === null): ?><span class="muted">—</span>
          <?php elseif ($it['days'] < 0): ?><span style="color:#c0392b;font-weight:700"><?= abs($it['days']) ?> ago</span>
          <?php else: ?><?= $it['days'] ?><?php endif; ?>
        </td>
        <td><?= $pill($it['status']) ?></td>
        <td><a class="btn ghost" style="padding:5px 10px;font-size:12px" href="<?= Security::e($it['link']) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?>
      <tr><td colspan="6" class="muted" style="padding:26px;text-align:center">
        <?= $show === 'all' ? 'No buses or drivers on file yet.' : '🎉 Nothing expired or expiring in the next ' . $SOON . ' days.' ?>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<p class="muted">
  Dates come from the <a href="<?= $base ?>/admin/buses.php">Bus Fleet</a> (insurance / permit / fitness) and
  <a href="<?= $base ?>/admin/drivers.php">Drivers</a> (licence) records — edit them there to renew.
  "Expiring" = within <?= $SOON ?> days. Read-only; this page changes nothing.
</p>
<?php
admin_footer();
