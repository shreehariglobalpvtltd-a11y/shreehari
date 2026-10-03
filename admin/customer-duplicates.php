<?php
/**
 * admin/customer-duplicates.php — duplicate customer finder (3 Oct 2026).
 *
 * Four lists, every one a SUGGESTION a person confirms — nothing is merged
 * automatically (cron/gem-hygiene.php explains why: folding a real
 * customer's tickets onto a stranger's number is the worst failure this
 * system can have):
 *
 *  1. Same number, name spelled differently (Ram Bahadur / RAM BAHADUR /
 *     Ram) → pick the spelling the customer record should carry.
 *  2. One person, two numbers: the name matches and the number differs by
 *     one digit / two swapped digits (a typo at the desk), or the same full
 *     name on two numbers → Link (history reads across both) or "Different
 *     people" (never suggested again).
 *  3. Numbers that carry many different travellers (an agent's, a family's
 *     or a hotel's phone) — shown so nobody links them by mistake.
 *  4. Placeholder and staff numbers on bookings — not customers at all;
 *     the fix is a real mobile on the ticket next time.
 *
 * Linking never edits a booking. Each action is written to audit_logs.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/customerbook.php';
$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('Duplicate customers', 'customer-duplicates');
    echo '<div class="flash bad">This is an office page.</div>';
    admin_footer();
    exit;
}
$canManage = Auth::isSuperadmin() || Auth::can('bookings.edit');
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a manager or super-admin can change customer records.'];
    } elseif ($act === 'set_name') {
        $err = CustomerBook::setName((string) ($_POST['phone'] ?? ''), (string) ($_POST['name'] ?? ''));
        $flash = $err === '' ? ['ok', 'Name set on the customer record (tickets keep what was printed on them).'] : ['bad', $err];
    } elseif ($act === 'link') {
        $from = (string) ($_POST['from'] ?? '');
        $into = (string) ($_POST['into'] ?? '');
        $err = CustomerBook::merge($from, $into);
        $flash = $err === '' ? ['ok', 'Linked: +' . normalisePhone($from) . ' now shows under +' . CustomerBook::canonical($into) . '. Bookings were not changed — Separate on the profile undoes it.'] : ['bad', $err];
    } elseif ($act === 'dismiss') {
        CustomerBook::dismissPair((string) ($_POST['a'] ?? ''), (string) ($_POST['b'] ?? ''), (int) ($admin['id'] ?? 0));
        $flash = ['ok', 'Marked as different people — this pair will not be suggested again.'];
    }
}

$d    = CustomerBook::duplicates();
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$e    = static fn($v): string => Security::e((string) ($v ?? ''));
$tab  = (string) ($_GET['tab'] ?? 'pairs');
if (!in_array($tab, ['pairs', 'variants', 'shared', 'other'], true)) { $tab = 'pairs'; }
$high = count(array_filter($d['pairs'], static fn(array $p): bool => $p['level'] === 'high'));

admin_header('Duplicate customers', 'customer-duplicates');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
?>
<style>
.dup-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.dup-tabs a{padding:8px 14px;border:1px solid var(--line);border-radius:999px;background:var(--card);color:var(--ink);text-decoration:none;font-size:13.5px}
.dup-tabs a.on{background:var(--navy,#12305a);color:#fff;border-color:transparent}
.dup-card{display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--line)}
.dup-card:last-child{border-bottom:0}
.dup-side{flex:1 1 220px;min-width:0}
.dup-side b{display:block}
.dup-acts{display:flex;gap:6px;flex-wrap:wrap}
.dup-acts form{display:inline}
.dup-lv{font-size:11px;font-weight:800;text-transform:uppercase;padding:3px 8px;border-radius:999px}
.dup-lv.high{background:#fde2e1;color:#8a1f1f}
.dup-lv.check{background:#fff2d6;color:#7a5200}
.dup-names{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.dup-names form{display:inline}
.dup-names button{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:5px 11px;font-size:13px;cursor:pointer}
.dup-names button:hover{background:var(--hover)}
@media(pointer:coarse){.dup-names button,.dup-acts .btn{min-height:44px}}
</style>

<div class="cards">
  <div class="card"><div class="k">Likely same person, 2 numbers</div><div class="v"><?= $high ?></div></div>
  <div class="card"><div class="k">Same full name, 2 numbers</div><div class="v"><?= count($d['pairs']) - $high ?></div></div>
  <div class="card"><div class="k">Numbers with spelling variants</div><div class="v"><?= count($d['variants']) ?></div></div>
  <div class="card"><div class="k">Shared / non-customer numbers</div><div class="v"><?= count($d['shared']) + count($d['nonCustomer']) ?></div></div>
</div>

<div class="dup-tabs">
  <a href="?tab=pairs" class="<?= $tab === 'pairs' ? 'on' : '' ?>">👥 Two numbers, one person (<?= count($d['pairs']) ?>)</a>
  <a href="?tab=variants" class="<?= $tab === 'variants' ? 'on' : '' ?>">✍️ Name spellings (<?= count($d['variants']) ?>)</a>
  <a href="?tab=shared" class="<?= $tab === 'shared' ? 'on' : '' ?>">📞 Shared numbers (<?= count($d['shared']) ?>)</a>
  <a href="?tab=other" class="<?= $tab === 'other' ? 'on' : '' ?>">⚠️ Placeholder / staff numbers (<?= count($d['nonCustomer']) ?>)</a>
</div>

<?php if ($tab === 'pairs'): ?>
<div class="panel">
  <h2>👥 One person, two numbers <span class="muted" style="font-weight:400;font-size:13px">· Link keeps both numbers and their tickets; the history shows together</span></h2>
  <?php if ($d['pairs'] === []): ?><div style="padding:18px" class="muted">No suspected duplicates right now.</div><?php endif; ?>
  <?php foreach ($d['pairs'] as $p): ?>
    <div class="dup-card">
      <span class="dup-lv <?= $p['level'] ?>"><?= $p['level'] === 'high' ? 'Likely typo' : 'Check' ?></span>
      <div class="dup-side"><b><a href="customer-view.php?phone=<?= urlencode($p['a']) ?>"><?= $e($p['name_a']) ?></a></b><span class="mono">+<?= $e($p['a']) ?></span> <span class="muted">· <?= (int) $p['n_a'] ?> booking(s)</span></div>
      <div class="dup-side"><b><a href="customer-view.php?phone=<?= urlencode($p['b']) ?>"><?= $e($p['name_b']) ?></a></b><span class="mono">+<?= $e($p['b']) ?></span> <span class="muted">· <?= (int) $p['n_b'] ?> booking(s)</span></div>
      <div class="dup-side muted" style="font-size:12.5px"><?= $e($p['why']) ?></div>
      <?php if ($canManage):
        // Keep the number with more bookings as the main one.
        [$main, $other] = $p['n_a'] >= $p['n_b'] ? [$p['a'], $p['b']] : [$p['b'], $p['a']]; ?>
      <div class="dup-acts">
        <form method="post" onsubmit="return confirm('Link +<?= $e($other) ?> under +<?= $e($main) ?> (same person)? Bookings are not changed.')">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="link">
          <input type="hidden" name="from" value="<?= $e($other) ?>"><input type="hidden" name="into" value="<?= $e($main) ?>">
          <button class="btn" type="submit">🔗 Same person — link</button>
        </form>
        <form method="post">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="dismiss">
          <input type="hidden" name="a" value="<?= $e($p['a']) ?>"><input type="hidden" name="b" value="<?= $e($p['b']) ?>">
          <button class="btn ghost" type="submit">Different people</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php elseif ($tab === 'variants'): ?>
<div class="panel">
  <h2>✍️ Same number, name written differently <span class="muted" style="font-weight:400;font-size:13px">· tap the spelling the customer record should use</span></h2>
  <?php if ($d['variants'] === []): ?><div style="padding:18px" class="muted">No spelling variants found.</div><?php endif; ?>
  <?php foreach ($d['variants'] as $v): ?>
    <div class="dup-card">
      <div class="dup-side" style="flex:0 1 230px"><b><a href="customer-view.php?phone=<?= urlencode($v['phone']) ?>" class="mono">+<?= $e($v['phone']) ?></a></b>
        <span class="muted">record: <?= $e($v['profile_name'] ?: '—') ?><?= $v['locked'] ? ' 🔒' : '' ?> · <?= (int) $v['bookings'] ?> booking(s)</span></div>
      <div class="dup-side">
        <div class="dup-names">
        <?php foreach ($v['names'] as $nm): ?>
          <?php if ($canManage): ?>
            <form method="post"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_name">
              <input type="hidden" name="phone" value="<?= $e($v['phone']) ?>"><input type="hidden" name="name" value="<?= $e($nm['name']) ?>">
              <button type="submit" title="Use this spelling on the customer record"><?= $e($nm['name']) ?> <span class="muted">×<?= (int) $nm['n'] ?></span><?= $nm['name'] === $v['profile_name'] ? ' ✓' : '' ?></button></form>
          <?php else: ?>
            <span class="dup-names"><button type="button" disabled><?= $e($nm['name']) ?> ×<?= (int) $nm['n'] ?></button></span>
          <?php endif; ?>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php elseif ($tab === 'shared'): ?>
<div class="panel">
  <h2>📞 Numbers that book for many different people <span class="muted" style="font-weight:400;font-size:13px">· likely an agent, a family or a hotel phone — do not link these to one person</span></h2>
  <?php if ($d['shared'] === []): ?><div style="padding:18px" class="muted">None.</div><?php endif; ?>
  <?php foreach ($d['shared'] as $s): ?>
    <div class="dup-card">
      <div class="dup-side" style="flex:0 1 230px"><b><a class="mono" href="customer-view.php?phone=<?= urlencode($s['phone']) ?>">+<?= $e($s['phone']) ?></a></b><span class="muted"><?= (int) $s['people'] ?> different travellers · <?= (int) $s['bookings'] ?> booking(s)</span></div>
      <div class="dup-side muted" style="white-space:normal"><?= $e(implode(' · ', $s['sample'])) ?><?= $s['people'] > count($s['sample']) ? ' …' : '' ?></div>
      <a class="btn ghost" href="customer-ledger.php?phone=<?= urlencode($s['phone']) ?>&amp;date_mode=booking">📒 Ledger</a>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel">
  <h2>⚠️ Placeholder and staff numbers on bookings <span class="muted" style="font-weight:400;font-size:13px">· these tickets carry no customer mobile — ask for the passenger's own number at the desk</span></h2>
  <?php if ($d['nonCustomer'] === []): ?><div style="padding:18px" class="muted">None — every booking carries a customer number.</div><?php endif; ?>
  <?php foreach ($d['nonCustomer'] as $n): ?>
    <div class="dup-card">
      <div class="dup-side" style="flex:0 1 230px"><b class="mono">+<?= $e($n['phone']) ?></b><span class="muted"><?= $e($n['why']) ?></span></div>
      <div class="dup-side muted"><?= (int) $n['bookings'] ?> booking(s) · <?= (int) $n['names'] ?> name(s) on them</div>
      <a class="btn ghost" href="customer-ledger.php?phone=<?= urlencode($n['phone']) ?>&amp;date_mode=booking">📒 See bookings</a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<p class="muted" style="font-size:12px;margin-top:8px">Nothing here changes a ticket. "Link" makes the second number part of the same customer's history (the profile page can Separate it again); "Use this spelling" sets the customer record's name. All actions are logged.</p>
<?php admin_footer(); ?>
