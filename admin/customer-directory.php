<?php
/**
 * admin/customer-directory.php — 📇 every customer number in one place (5 Oct 2026).
 *
 * One row per mobile number, built live from the bookings (the booking
 * number and the family's second number), optionally with the leads that
 * never booked. Filter by booking or travel date, by agent / counter /
 * online, route, India or Nepal, trips, "not travelled for N days".
 * "Whose customers, how many" sits beside it for the same filters.
 *
 * From here: export (CSV / Excel / contacts .vcf / plain numbers), block a
 * number from marketing, or launch a campaign to exactly this list.
 * customers.view to see it; exports need reports.export; campaigns need
 * marketing.send. Counter agents are refused (office page).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
require_once INCLUDE_PATH . '/customerdirectory.php';
require_once INCLUDE_PATH . '/campaigns.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('All Customers', 'customer-directory');
    echo '<div class="flash bad">The customer directory is an office page — your own customers are under My Passengers.</div>';
    admin_footer();
    exit;
}
$me        = (int) $admin['id'];
$mayExport = Auth::isSuperadmin() || Auth::can('reports.export');
$mayMarket = Auth::isSuperadmin() || Auth::can('marketing.send');
$flash     = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayMarket) {
        $flash = ['bad', 'Only someone who may send campaigns can block or unblock numbers.'];
    } elseif (!Campaigns::ready()) {
        $flash = ['bad', 'Apply database/upgrade-2026-10-05-campaigns.sql first.'];
    } else {
        $ph = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        if (($_POST['action'] ?? '') === 'block') {
            Campaigns::optOut($ph, 'blocked by office', $me);
            Logger::audit('marketing.optout', 'phone', maskPhone($ph), null, null, 'by admin #' . $me);
            $flash = ['ok', maskPhone($ph) . ' will not get campaign messages.'];
        } elseif (($_POST['action'] ?? '') === 'unblock') {
            Database::run('DELETE FROM mk_optout WHERE phone = :p', ['p' => substr($ph, -10)]);
            Logger::audit('marketing.optin', 'phone', maskPhone($ph), null, null, 'by admin #' . $me);
            $flash = ['ok', maskPhone($ph) . ' may get campaign messages again (unless they said STOP on WhatsApp).'];
        }
    }
}

$f = CustomerDirectory::filters($_GET);

/* ---- exports (whole filtered list, not just this page) ---- */
$export = (string) ($_GET['export'] ?? '');
if ($export !== '') {
    if (!$mayExport) {
        http_response_code(403);
        exit('Exports need the reports.export permission.');
    }
    $all = CustomerDirectory::rows($f, 20000, 0)['rows'];
    Logger::audit('customers.export', 'directory', $export, null, ['rows' => count($all), 'filters' => CustomerDirectory::qs($f)], 'by admin #' . $me);
    $stamp = date('Ymd-Hi');
    if ($export === 'numbers') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo implode("\n", array_map(static fn ($r) => '+' . $r['intl'], $all));
        exit;
    }
    if ($export === 'vcf') {
        $list = array_map(static fn ($r) => ['ph' => $r['ph'], 'intl' => $r['intl'], 'name' => (string) $r['name']], $all);
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="shg-customers-' . $stamp . '.vcf"');
        header('Cache-Control: no-store');
        echo CustomerDirectory::vcards($list);
        exit;
    }
    $head = ['Name', 'Passengers', 'Mobile', 'Country', 'Trips', 'First booking', 'Last booking', 'Last travel', 'Spent', 'Agent / source', 'Opted out', 'Note'];
    $data = array_map(static fn ($r) => [
        (string) $r['name'], (string) $r['passengers'], '+' . $r['intl'], $r['cc'] === '977' ? 'Nepal' : 'India', (int) $r['trips'],
        substr((string) $r['first_at'], 0, 10), substr((string) $r['last_at'], 0, 10), (string) ($r['last_travel'] ?? ''),
        (float) $r['spent'], (string) $r['agents'], $r['optout'] ? 'yes' : '', trim(($r['staff'] !== '' ? 'staff: ' . $r['staff'] : '') . (!empty($r['lead']) ? ' lead' : '') . (!empty($r['is_alt']) && empty($r['is_main']) ? ' 2nd number' : '')),
    ], $all);
    if ($export === 'xlsx') {
        shg_export_xlsx('shg-customers-' . $stamp . '.xlsx', 'Customers', $head, $data, [4, 8]);
    } else {
        shg_export_csv('shg-customers-' . $stamp . '.csv', $head, $data);
    }
    exit;
}

$page   = max(1, (int) ($_GET['p'] ?? 1));
$per    = 100;
$res    = CustomerDirectory::rows($f, $per, ($page - 1) * $per);
$rows   = $res['rows'];
$total  = $res['total'];
$agents = CustomerDirectory::agentBreakdown($f);
$routes = Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes ORDER BY is_active DESC, id');
$qs     = CustomerDirectory::qs($f);
$e      = static fn ($v): string => Security::e((string) $v);
$csrf   = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';
$link   = static fn (array $over): string => '?' . CustomerDirectory::qs($f, $over);
$range  = (string) ($_GET['range'] ?? '');
$repeat = count(array_filter($rows, static fn ($r) => (int) $r['trips'] >= 2));

admin_header('All Customers', 'customer-directory');
?>
<style>
  .cd-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.5}
  .cd-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
  .cd-chips a{padding:6px 11px;border:1px solid var(--line);border-radius:999px;font-size:13px;text-decoration:none;color:var(--ink);background:var(--card)}
  .cd-chips a.on{background:var(--ink);color:var(--card);border-color:var(--ink)}
  .cd-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,160px),1fr));gap:8px;margin:0 0 10px}
  .cd-form label{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--mut)}
  .cd-form input,.cd-form select{padding:8px 9px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:38px}
  .cd-checks{display:flex;gap:14px;flex-wrap:wrap;font-size:13px;margin:0 0 10px}
  .cd-checks label{display:inline-flex;gap:6px;align-items:center}
  .cd-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:14px}
  @media (min-width:1100px){.cd-grid{grid-template-columns:300px minmax(0,1fr)}}
  .cd-agents table{width:100%;border-collapse:collapse;font-size:13px}
  .cd-agents td{padding:7px 6px;border-bottom:1px solid var(--line)}
  .cd-agents td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .cd-agents tr.on td{background:var(--ok-bg)}
  .cd-agents a{color:var(--ink);text-decoration:none}
  .cd-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}
  .cd-tag{display:inline-block;font-size:10.5px;padding:0 6px;border-radius:6px;border:1px solid var(--line);color:var(--mut);margin-left:4px}
  .cd-tag.bad{color:var(--bad);border-color:var(--bad)}
  .cd-pax{font-size:12px;color:var(--mut)}
  .cd-pager{display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap}
  .cd-inline{display:inline}.cd-inline button{font-size:11.5px;padding:3px 8px;min-height:28px}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= $e($flash[1]) ?></p>
<?php endif; ?>

<p class="cd-note">Every customer number in one place — the booking number and the family’s second number, one row per person. Pick dates, an agent or a route;
  “Whose customers” shows how many each agent brought for the same dates. Export the list, or launch a campaign to exactly these people.
  Numbers that said STOP, staff numbers and placeholders never get campaign messages.</p>

<div class="cd-chips">
  <?php foreach (['' => 'All time', 'today' => 'Today', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month', 'year' => 'This year'] as $k => $lbl):
      $u = $k === '' ? $link(['from' => '', 'to' => '']) : '?' . http_build_query(array_filter(['range' => $k, 'mode' => $f['mode'], 'agent' => $f['agent'], 'route' => $f['route'] ?: '', 'country' => $f['country'], 'status' => $f['status']], static fn ($v) => $v !== ''));
      $on = $k === '' ? ($f['from'] === '' && $f['to'] === '') : $range === $k; ?>
    <a class="<?= $on ? 'on' : '' ?>" href="<?= $e($u) ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php /* 16 Oct 2026 (owner: "dherai option, nachaine faldeu"): the full filter
         form folds away; it opens by itself when one of its filters is set. */
      $cdMore = $f['agent'] !== '' || $f['route'] || $f['country'] !== '' || $f['status'] !== 'confirmed' || $f['min'] || $f['lapsed'] || !$f['alt'] || $f['leads'] || $f['mode'] !== 'booking'; ?>
<form method="get" class="cd-quick" style="display:flex;gap:8px;margin:0 0 10px">
  <?php foreach (['range' => $range ?? '', 'from' => $f['from'], 'to' => $f['to']] as $hk => $hv): if ((string) $hv !== ''): ?><input type="hidden" name="<?= $hk ?>" value="<?= $e((string) $hv) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= $e($f['q']) ?>" placeholder="🔍 Name or mobile digits…" aria-label="Search name or mobile" style="flex:1 1 auto;min-width:0;min-height:46px;padding:10px 14px;font-size:16px;border:2px solid var(--royal,#1a3fc4);border-radius:12px;background:var(--card);color:var(--ink)">
  <button class="btn" type="submit">Search</button>
</form>
<details class="dt-more" style="margin:0 0 12px"<?= $cdMore ? ' open' : '' ?>>
<summary class="btn ghost">☰ More filters<?= $cdMore ? ' · on' : '' ?></summary>
<form method="get" style="margin-top:8px">
  <div class="cd-form">
    <label>Date is<select name="mode"><option value="booking" <?= $f['mode'] === 'booking' ? 'selected' : '' ?>>Booking date</option><option value="travel" <?= $f['mode'] === 'travel' ? 'selected' : '' ?>>Travel date</option></select></label>
    <label>From<input type="date" name="from" value="<?= $e($f['from']) ?>"></label>
    <label>To<input type="date" name="to" value="<?= $e($f['to']) ?>"></label>
    <label>Agent / source<select name="agent"><option value="">Everyone</option>
      <?php foreach ($agents as $a): ?><option value="<?= $e($a['key']) ?>" <?= $f['agent'] === $a['key'] ? 'selected' : '' ?>><?= $e($a['label'] . ' (' . $a['customers'] . ')') ?></option><?php endforeach; ?>
      <?php if ($f['agent'] !== '' && !in_array($f['agent'], array_column($agents, 'key'), true)): ?><option value="<?= $e($f['agent']) ?>" selected>#<?= $e($f['agent']) ?></option><?php endif; ?>
    </select></label>
    <label>Route<select name="route"><option value="">All routes</option>
      <?php foreach ($routes as $rt): ?><option value="<?= (int) $rt['id'] ?>" <?= $f['route'] === (int) $rt['id'] ? 'selected' : '' ?>><?= $e($rt['route_code'] . ' · ' . $rt['from_city'] . ' → ' . $rt['to_city']) ?></option><?php endforeach; ?>
    </select></label>
    <label>Country<select name="country"><option value="">India + Nepal</option><option value="IN" <?= $f['country'] === 'IN' ? 'selected' : '' ?>>India +91</option><option value="NP" <?= $f['country'] === 'NP' ? 'selected' : '' ?>>Nepal +977</option></select></label>
    <label>Bookings<select name="status"><option value="confirmed" <?= $f['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed / travelled</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All (incl. cancelled)</option><option value="cancelled" <?= $f['status'] === 'cancelled' ? 'selected' : '' ?>>Only cancelled</option></select></label>
    <label>At least … trips<input type="number" name="min" min="0" max="50" value="<?= $f['min'] ?: '' ?>" placeholder="any"></label>
    <label>Not travelled for … days<input type="number" name="lapsed" min="0" max="1000" value="<?= $f['lapsed'] ?: '' ?>" placeholder="any"></label>
    <label>Search<input type="search" name="q" value="<?= $e($f['q']) ?>" placeholder="name or last digits"></label>
  </div>
  <div class="cd-checks">
    <input type="hidden" name="alt" value="0">
    <label><input type="checkbox" name="alt" value="1" <?= $f['alt'] ? 'checked' : '' ?>> include second (family) numbers</label>
    <label><input type="checkbox" name="leads" value="1" <?= $f['leads'] ? 'checked' : '' ?>> include leads who never booked (enquiries, help desk)</label>
    <button class="btn" type="submit">Show</button>
    <a class="btn ghost" href="?">Reset</a>
  </div>
</form>
</details>

<div class="kpis">
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-users"/></svg></span><div class="kt"><div class="kk">Numbers</div><div class="kv"><?= number_format($total) ?></div><div class="ks">people in this list</div></div></div>
  <div class="kpi tone-green"><span class="ki"><svg class="a-ic"><use href="#a-refresh"/></svg></span><div class="kt"><div class="kk">Came back</div><div class="kv"><?= $repeat ?></div><div class="ks">2+ trips (this page)</div></div></div>
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-handshake"/></svg></span><div class="kt"><div class="kk">Sellers</div><div class="kv"><?= count($agents) ?></div><div class="ks">agents / counters / online</div></div></div>
</div>

<div class="cd-actions">
  <?php if ($mayMarket): ?><a class="btn" href="/admin/campaigns.php?new=1&amp;aud=<?= $e(rawurlencode($qs)) ?>">📣 Launch a campaign to this list</a><?php endif; ?>
  <?php if ($mayExport): ?>
    <a class="btn ghost" href="?<?= $e($qs) ?>&amp;export=xlsx">⬇ Excel</a>
    <a class="btn ghost" href="?<?= $e($qs) ?>&amp;export=csv">⬇ CSV</a>
    <a class="btn ghost" href="?<?= $e($qs) ?>&amp;export=vcf" title="Import into the phone’s contacts — WhatsApp broadcast lists only reach saved contacts">📇 Contacts (.vcf)</a>
    <a class="btn ghost" href="?<?= $e($qs) ?>&amp;export=numbers" target="_blank" rel="noopener">📋 All numbers (text)</a>
  <?php endif; ?>
</div>

<div class="cd-grid">
  <div class="panel cd-agents">
    <h2>Whose customers — how many</h2>
    <?php if ($agents === []): ?><p class="cd-note">No bookings in this range.</p><?php else: ?>
    <table>
      <tr><td><b>Seller</b></td><td class="n"><b>People</b></td><td class="n"><b>Bookings</b></td></tr>
      <?php foreach ($agents as $a): ?>
        <tr class="<?= $f['agent'] === $a['key'] ? 'on' : '' ?>"><td><a href="<?= $e($link(['agent' => $a['key']])) ?>"><?= $e($a['label']) ?></a></td><td class="n"><?= $a['customers'] ?></td><td class="n"><?= $a['bookings'] ?></td></tr>
      <?php endforeach; ?>
      <?php if ($f['agent'] !== ''): ?><tr><td colspan="3"><a href="<?= $e($link(['agent' => ''])) ?>">← everyone</a></td></tr><?php endif; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Customers (<?= number_format($total) ?>)</h2>
    <?php if ($rows === []): ?>
      <p class="cd-note">Nobody matches these filters.</p>
    <?php else: ?>
    <div class="dt-wrap">
      <table class="dt card-table">
        <thead><tr><th>Name · passengers</th><th>Mobile</th><th>Trips</th><th>Last booking</th><th>Last travel</th><th>Spent</th><th>Agent / source</th><th data-nosort></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td data-label="Name"><b><?= $e($r['name'] !== '' ? $r['name'] : '—') ?></b>
              <?php if ($r['passengers'] !== '' && $r['passengers'] !== $r['name']): ?><div class="cd-pax"><?= $e($r['passengers']) ?></div><?php endif; ?>
              <?php if (!empty($r['lead'])): ?><span class="cd-tag"><?= $r['lead'] === 'help' ? 'help desk' : 'lead' ?></span><?php endif; ?>
              <?php if (!empty($r['is_alt']) && empty($r['is_main'])): ?><span class="cd-tag">2nd number</span><?php endif; ?>
              <?php if ($r['optout']): ?><span class="cd-tag bad">no marketing</span><?php endif; ?>
              <?php if ($r['staff'] !== ''): ?><span class="cd-tag">staff</span><?php endif; ?></td>
            <td data-label="Mobile"><a href="https://wa.me/<?= $e($r['intl']) ?>" target="_blank" rel="noopener">+<?= $e($r['intl']) ?></a> <a href="tel:+<?= $e($r['intl']) ?>" aria-label="Call">📞</a></td>
            <td data-label="Trips" data-sort="<?= (int) $r['trips'] ?>"><?= (int) $r['trips'] ?></td>
            <td data-label="Last booking" data-sort="<?= $e($r['last_at']) ?>"><?= $e(substr((string) $r['last_at'], 0, 10)) ?></td>
            <td data-label="Last travel"><?= $e((string) ($r['last_travel'] ?? '')) ?></td>
            <td data-label="Spent" data-sort="<?= (float) $r['spent'] ?>"><?= $r['spent'] > 0 ? number_format((float) $r['spent']) : '—' ?></td>
            <td data-label="Agent / source" class="wrap"><?= $e($r['agents']) ?></td>
            <td data-label="">
              <div class="dt-acts">
                <a class="btn ghost" href="/admin/customer-view.php?phone=<?= $e($r['ph']) ?>">360</a>
                <?php if ($mayMarket && Campaigns::ready()): ?>
                  <form method="post" class="cd-inline"><?= $csrf ?><input type="hidden" name="phone" value="<?= $e($r['ph']) ?>">
                    <?php if ($r['optout']): ?><button class="btn ghost" name="action" value="unblock" type="submit">Allow offers</button>
                    <?php else: ?><button class="btn ghost" name="action" value="block" type="submit" title="Never send this number a campaign">No offers</button><?php endif; ?>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="cd-pager">
      <?php if ($page > 1): ?><a class="btn ghost" href="?<?= $e($qs) ?>&amp;p=<?= $page - 1 ?>">← Previous</a><?php endif; ?>
      <span class="cd-note" style="margin:0">Page <?= $page ?> of <?= max(1, (int) ceil($total / $per)) ?></span>
      <?php if ($page * $per < $total): ?><a class="btn ghost" href="?<?= $e($qs) ?>&amp;p=<?= $page + 1 ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer(); ?>
