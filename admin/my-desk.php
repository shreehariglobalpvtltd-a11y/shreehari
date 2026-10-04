<?php
/**
 * admin/my-desk.php — a counter keeps its own desk's contact details (4 Oct 2026).
 *
 * Owner: "jati bela ni update garna milos admin ra user counter wala le".
 * The clerk at a desk may change what the PASSENGER reads about that desk -
 * phone, opening hours, address, map link - and nothing else: the desk's
 * money, company, payment methods and the run it sells stay with the office
 * (Admin -> Counters & collection). Every change is an audit row (old -> new).
 *
 * Who: anyone signed in with bookings.view who has been put on a desk (Staff
 * & Approvals). The office (reports.view) may also pick any desk here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
$me    = (int) ($admin['id'] ?? 0);
$e     = static fn($v): string => Security::e((string) $v);
$flash = null;

$isOffice = Auth::isSuperadmin() || Auth::can('reports.view');
$myCode   = CounterDesk::forAdmin($me)['code'];
$code     = $myCode;
if ($isOffice && isset($_REQUEST['desk'])) {
    $pick = mb_strtoupper(Security::clean((string) $_REQUEST['desk'], 16));
    if (CounterDesk::get($pick) !== null) {
        $code = $pick;
    }
}
$desk  = $code !== '' ? CounterDesk::get($code) : null;
$ready = CounterDesk::hasTable() && CounterDesk::hasColumn('counter_locations', 'hours');

if ($desk !== null && $ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        try {
            $before = $desk;
            /* Only the four contact fields; every other column is passed back
               exactly as it is, so a clerk cannot move money or company. */
            CounterDesk::save(array_merge($desk, [
                'phone'   => $_POST['phone'] ?? '',
                'hours'   => $_POST['hours'] ?? '',
                'address' => $_POST['address'] ?? '',
                'map_url' => $_POST['map_url'] ?? '',
                'is_active' => (int) $desk['is_active'],
            ]));
            $after = CounterDesk::get($code) ?? [];
            $pick  = static fn(array $d): array => array_intersect_key($d, array_flip(['phone', 'hours', 'address', 'map_url']));
            Logger::audit('counter.contact', 'counter_location', $code, $pick($before), $pick($after),
                'desk contact edited by admin #' . $me . ($code === $myCode ? ' (own desk)' : ''));
            $desk  = $after;
            $flash = ['ok', 'Saved. Tickets cut at ' . $code . ' print the new details. / सेभ भयो।'];
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

admin_header('My desk', 'my-desk');
?>
<style>
.md-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px;max-width:640px}
.md-f{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;font-size:12.5px;font-weight:700;color:var(--mut)}
.md-f input,.md-f textarea{padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:15px;font-weight:400;background:var(--card);color:var(--ink);min-height:46px;width:100%;box-sizing:border-box}
.md-facts{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
.md-facts span{font-size:12.5px;font-weight:700;padding:4px 10px;border-radius:999px;background:var(--bg,#F4F6FB);border:1px solid var(--line)}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php if ($isOffice && CounterDesk::all() !== []): ?>
<form method="get" class="toolbar" style="margin-bottom:12px">
  <label style="font-size:12px;font-weight:700">Desk
    <select name="desk" onchange="this.form.submit()">
      <?php if ($code === ''): ?><option value="">— choose —</option><?php endif; ?>
      <?php foreach (CounterDesk::all() as $c => $d): ?>
        <option value="<?= $e($c) ?>" <?= $c === $code ? 'selected' : '' ?>><?= CounterDesk::flag((string) $d['country']) ?> <?= $e($c . ' · ' . $d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash bad">The desk table is not ready on this database yet.</div>
<?php elseif ($desk === null): ?>
  <div class="md-card">
    <b>You are not on a desk yet.</b>
    <p class="muted" style="margin:6px 0 0">Ask the office to put you on one in Staff &amp; Approvals. · काउन्टर तोकिएको छैन — कार्यालयलाई भन्नुहोस्।</p>
  </div>
<?php else: ?>
  <div class="md-card">
    <h2 style="margin:0 0 6px;font-size:18px"><?= CounterDesk::flag((string) $desk['country']) ?> <?= $e($desk['name']) ?> <span class="muted" style="font-size:13px">(<?= $e($desk['code']) ?>)</span></h2>
    <div class="md-facts">
      <span><?= $desk['currency'] === 'NPR' ? 'रू NPR' : '₹ INR' ?></span>
      <span><?= $desk['entity'] === 'NP' ? 'Nepal company' : 'India company' ?></span>
      <?php $m = CounterDesk::allowedMethods((string) $desk['code']); ?>
      <span><?= $e($m === null ? 'all payment methods' : strtoupper(implode(' / ', $m)) . ' only') ?></span>
    </div>
    <p class="muted" style="margin:0 0 14px;font-size:13px">These print on tickets cut at this desk and on its page. Leave a box empty and it is simply not printed.
      Money, company and payment methods are set by the office.</p>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?>
      <?php if ($code !== $myCode): ?><input type="hidden" name="desk" value="<?= $e($code) ?>"><?php endif; ?>
      <label class="md-f">Desk phone · फोन
        <input type="tel" name="phone" maxlength="40" value="<?= $e($desk['phone'] ?? '') ?>" placeholder="<?= $desk['country'] === 'NP' ? '+977 …' : '+91 …' ?>"></label>
      <label class="md-f">Opening hours · खुल्ने समय <?= $desk['country'] === 'NP' ? '(NPT)' : '(IST)' ?>
        <input type="text" name="hours" maxlength="120" value="<?= $e($desk['hours'] ?? '') ?>" placeholder="e.g. 8:00 AM – 7:00 PM, every day"></label>
      <label class="md-f">Address · ठेगाना
        <input type="text" name="address" maxlength="190" value="<?= $e($desk['address'] ?? '') ?>" placeholder="street, landmark, town"></label>
      <label class="md-f">Map link · नक्सा (Google Maps)
        <input type="url" name="map_url" maxlength="255" value="<?= $e($desk['map_url'] ?? '') ?>" placeholder="https://maps.app.goo.gl/…"></label>
      <button class="btn ok" type="submit">💾 Save · सेभ गर्नुहोस्</button>
    </form>
  </div>
<?php endif; ?>
<?php admin_footer();
