<?php
/**
 * admin/shift.php — open the drawer, sell, count the drawer, go home.
 *
 * One screen, three states (includes/countershift.php has the reasoning):
 *   no shift  → one number (the float) and one button;
 *   open      → what the register says you took so far, and the close form:
 *               tap the notes you hold OR type the total, the difference is
 *               shown live before anything is saved;
 *   closed    → the result, a printable slip, the summary to the office.
 *
 * Anyone who can sell may keep a shift (bookings.view: counter, agent,
 * manager, superadmin). A cashier sees only their own drawer; the office
 * (dashboard.view) sees every shift and may close one somebody forgot.
 *
 * The feature ships OFF (counter_shift_on). The office switches it on from
 * this page — a setting nobody can find is a feature nobody has.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

require_once INCLUDE_PATH . '/countershift.php';

$base     = '';   // root-relative: the panel must stay on the request host
$flash    = null;
$me       = (int) $admin['id'];
$isOffice = Auth::can('dashboard.view');
$closed   = null;   // the row just closed, for the result card

$money = static function (mixed $v): float {
    $s = preg_replace('/[^0-9.]/', '', (string) $v) ?? '';
    return $s === '' ? 0.0 : round((float) $s, 2);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            if ($act === 'enable' || $act === 'disable') {
                if (!$isOffice) {
                    throw new RuntimeException('Only the office can switch this on or off.');
                }
                Settings::set('counter_shift_on', $act === 'enable' ? '1' : '0', 'bool', 'agent');
                Logger::audit('shift.switch', 'setting', 'counter_shift_on', null, ['on' => $act === 'enable'], 'by admin #' . $me);
                $flash = ['ok', $act === 'enable' ? 'Shift and cash count is ON for every counter.' : 'Shift and cash count is OFF.'];
            } elseif (!CounterShift::enabled()) {
                throw new RuntimeException('Shift and cash count is switched off.');
            } elseif ($act === 'open') {
                $s = CounterShift::open($me, $money($_POST['opening_cash'] ?? 0), Security::clean((string) ($_POST['note'] ?? ''), 255));
                Logger::audit('shift.open', 'counter_shift', (string) $s['id'], null, ['opening_cash' => $s['opening_cash']], 'opened by admin #' . $me);
                $flash = ['ok', 'Shift opened. Good selling! / शिफ्ट सुरु भयो।'];
            } elseif ($act === 'close') {
                $sid  = (int) ($_POST['shift_id'] ?? 0);
                $row  = Database::fetch('SELECT * FROM counter_shifts WHERE id = :i', ['i' => $sid]);
                if ($row === null || ((int) $row['admin_id'] !== $me && !$isOffice)) {
                    throw new RuntimeException('That shift is not yours to close.');
                }
                $denoms = [];
                foreach (CounterShift::denominationsFor(CounterShift::currencyOf($row)) as $d) {
                    $denoms[$d] = (int) ($_POST['d' . $d] ?? 0);
                }
                $closed = CounterShift::close(
                    $sid, $me,
                    $money($_POST['counted_cash'] ?? 0),
                    $money($_POST['paid_out'] ?? 0),
                    $denoms,
                    Security::clean((string) ($_POST['note'] ?? ''), 255)
                );
                Logger::audit('shift.close', 'counter_shift', (string) $sid, null, [
                    'expected' => $closed['expected_cash'], 'counted' => $closed['counted_cash'], 'variance' => $closed['variance'],
                ], 'closed by admin #' . $me);

                if (Settings::getBool('counter_shift_notify', true)) {
                    try {
                        require_once INCLUDE_PATH . '/notify.php';
                        $office = Settings::getString('admin_whatsapp', Settings::officePhone());
                        $who    = (string) (Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => (int) $closed['admin_id']], '') ?: ('#' . $closed['admin_id']));
                        if ($office !== '') {
                            Notify::whatsapp($office, CounterShift::summaryText($closed, $who), null, null, [], null, ['purpose' => 'shift_close']);
                        }
                    } catch (Throwable $e) {
                        Logger::error('shift summary not sent', ['e' => $e->getMessage()]);   // never fail a close on a message
                    }
                }
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$on = CounterShift::enabled();

/* Which drawer is on screen: mine, or (office only) one somebody left open. */
$shift = null;
if ($on && $closed === null) {
    $want = (int) ($_GET['shift'] ?? 0);
    if ($want > 0 && $isOffice) {
        $shift = Database::fetch('SELECT * FROM counter_shifts WHERE id = :i AND is_open = 1', ['i' => $want]);
    }
    $shift = $shift ?? CounterShift::current($me);
}
$live    = $shift !== null ? CounterShift::live($shift) : null;

/* The money THIS drawer holds (26 Sep 2026). A Nepalgunj window counts NPR:
   printing a rupee sign over a Nepali count is simply a wrong number. An
   unassigned desk, and every Indian desk, is unchanged. */
$shiftCur  = CounterShift::currencyOf($closed ?? $shift ?? ['currency' => CounterDesk::currency(CounterDesk::forAdmin($me)['code'])]);
$deskNotes = CounterShift::denominationsFor($shiftCur);
$curSym    = CounterDesk::SYMBOL[$shiftCur] ?? '';
$mc        = static fn(float $v, string $cur = ''): string => CounterDesk::format($v, $cur !== '' ? $cur : $shiftCur);
$history = $on ? CounterShift::recent($isOffice ? null : $me, 60) : [];
$whoName = static fn(array $r): string => (string) ($r['admin_name'] ?? '') !== '' ? (string) $r['admin_name'] : ('#' . (int) $r['admin_id']);

admin_header('Shift & Cash', 'shift');
?>

<style>
  .sh-wrap{max-width:760px}
  .sh-big{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .sh-big label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);flex:1 1 180px}
  .sh-big input,.sh-note input{font-size:22px;font-weight:700;padding:12px 14px;border:1px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);min-height:54px;width:100%;font-variant-numeric:tabular-nums}
  .sh-note input{font-size:15px;font-weight:500;min-height:46px}
  .sh-go{min-height:54px;padding:0 26px;font-size:16px;font-weight:800;border-radius:12px;flex:0 0 auto}
  .sh-go.green{background:var(--ok);border-color:var(--ok);color:#fff}
  .sh-go.red{background:var(--bad);border-color:var(--bad);color:#fff}
  .sh-pad{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin:10px 0}
  .sh-pad label{display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:12px;padding:6px 8px 6px 12px;background:var(--card);font-weight:800;font-variant-numeric:tabular-nums}
  .sh-pad label span{flex:0 0 52px}
  .sh-pad input{width:100%;min-height:44px;border:0;background:var(--soft);border-radius:9px;text-align:center;font-size:18px;font-weight:700;color:var(--ink)}
  .sh-sum{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:12px 0}
  .sh-sum div{border-radius:12px;padding:10px 12px;background:var(--soft)}
  .sh-sum small{display:block;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mut)}
  .sh-sum b{font-size:20px;font-variant-numeric:tabular-nums}
  .sh-diff.ok{background:var(--ok-bg);color:var(--ok)} .sh-diff.short{background:var(--bad-bg);color:var(--bad)} .sh-diff.over{background:var(--warn-bg);color:var(--warn)}
  .sh-result{border-radius:16px;padding:18px;margin:0 0 14px;font-weight:700}
  .sh-result h2{margin:0 0 6px;font-size:20px}
  .sh-pill{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:999px;white-space:nowrap}
  .sh-pill.ok{background:var(--ok-bg);color:var(--ok)} .sh-pill.short{background:var(--bad-bg);color:var(--bad)} .sh-pill.over{background:var(--warn-bg);color:var(--warn)} .sh-pill.open{background:var(--info-bg);color:var(--info)}
  details.sh-more{margin:10px 0} details.sh-more summary{cursor:pointer;font-weight:700;color:var(--blue);min-height:40px;display:flex;align-items:center}
  @media (max-width:560px){.sh-sum{grid-template-columns:1fr 1fr}.sh-go{width:100%}}
  @media print{.sh-noprint,.dt-wrap,.panel.sh-hist{display:none!important}}
</style>

<div class="sh-wrap">
<?php if ($flash !== null): ?>
  <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= Security::e($flash[1]) ?></p>
<?php endif; ?>

<?php if (!$on): ?>
  <div class="panel">
    <h2>Shift &amp; cash count is switched off</h2>
    <p class="muted" style="line-height:1.6">When it is on, each counter opens a shift in the morning with one number (the cash already in the drawer). The system adds up the cash and UPI that person took. In the evening they count the drawer, and the screen shows at once whether it matches. The office gets the summary on WhatsApp. Selling works exactly the same with it on or off.</p>
    <?php if ($isOffice): ?>
      <form method="post" class="sh-noprint">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
        <input type="hidden" name="action" value="enable">
        <button type="submit" class="btn sh-go green">Switch ON · चालु गर्नुहोस्</button>
      </form>
    <?php else: ?>
      <p><b>Ask the office to switch it on. / अफिसलाई चालु गर्न भन्नुहोस्।</b></p>
    <?php endif; ?>
  </div>

<?php elseif ($closed !== null):
  $ver = CounterShift::verdict((float) $closed['variance']); ?>
  <div class="sh-result sh-diff <?= $ver ?>">
    <h2><?= $ver === 'ok' ? '✅ Drawer matches · नगद मिल्यो' : ($ver === 'short' ? '🔴 Short by ' . Security::e($mc(abs((float) $closed['variance']))) . ' · कम' : '🟠 Over by ' . Security::e($mc((float) $closed['variance'])) . ' · बढी') ?></h2>
    Shift closed <?= Security::e(substr((string) $closed['closed_at'], 0, 16)) ?>
  </div>
  <div class="panel">
    <h2>Shift slip</h2>
    <div class="sh-sum">
      <div><small>Opening cash</small><b><?= Security::e($mc((float) $closed['opening_cash'])) ?></b></div>
      <div><small>Cash sales</small><b><?= Security::e($mc((float) $closed['cash_sales'])) ?></b></div>
      <div><small>Cash paid out</small><b><?= Security::e($mc((float) $closed['cash_paid_out'])) ?></b></div>
      <div><small>Should be in drawer</small><b><?= Security::e($mc((float) $closed['expected_cash'])) ?></b></div>
      <div><small>Counted</small><b><?= Security::e($mc((float) $closed['counted_cash'])) ?></b></div>
      <div><small>UPI / bank (not in drawer)</small><b><?= Security::e($mc((float) $closed['upi_sales'])) ?></b></div>
      <div><small>Tickets</small><b><?= (int) $closed['tickets'] ?></b></div>
      <div><small>Seats</small><b><?= (int) $closed['seats'] ?></b></div>
    </div>
    <div class="sh-big sh-noprint">
      <button type="button" class="btn sh-go" onclick="window.print()">🖨 Print slip</button>
      <a class="btn ghost sh-go" style="display:inline-flex;align-items:center" href="<?= $base ?>/admin/shift.php">Done</a>
    </div>
  </div>

<?php elseif ($shift === null): ?>
  <div class="panel">
    <h2>Open your shift · शिफ्ट सुरु गर्नुहोस्</h2>
    <form method="post">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="open">
      <div class="sh-big">
        <label>Cash in the drawer now · अहिले दराजमा भएको नगद (₹)
          <input type="text" name="opening_cash" inputmode="decimal" autocomplete="off" value="0" autofocus onfocus="this.select()">
        </label>
        <button type="submit" class="btn sh-go green">▶ Open shift</button>
      </div>
    </form>
  </div>

<?php else:
  $mine = (int) $shift['admin_id'] === $me; ?>
  <div class="panel">
    <h2><?= $mine ? 'Your shift is open' : 'Open shift of ' . Security::e((string) (Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => (int) $shift['admin_id']], '') ?: '#' . $shift['admin_id'])) ?>
      <span class="muted" style="font-size:13px;font-weight:500"> · since <?= Security::e(substr((string) $shift['opened_at'], 0, 16)) ?></span></h2>
    <div class="sh-sum">
      <div><small>Cash taken · नगद</small><b><?= Security::e($mc($live['cash'])) ?></b></div>
      <div><small>UPI / bank</small><b><?= Security::e($mc($live['upi'])) ?></b></div>
      <div><small>Tickets · seats</small><b><?= (int) $live['tickets'] ?> · <?= (int) $live['seats'] ?></b></div>
      <div><small>Opening cash</small><b><?= Security::e($mc((float) $shift['opening_cash'])) ?></b></div>
      <div style="grid-column:span 2"><small>Should be in the drawer now · दराजमा हुनुपर्ने</small><b id="shExpected" data-v="<?= number_format($live['expected'], 2, '.', '') ?>"><?= Security::e($mc($live['expected'])) ?></b></div>
    </div>
    <?php if ($live['cancelled'] > 0): ?>
      <p class="muted" style="font-size:13px">ℹ️ <?= (int) $live['cancelled'] ?> ticket(s) you took money for were cancelled. If you gave cash back from the drawer, enter it under “Cash paid out”.</p>
    <?php endif; ?>
    <p class="sh-noprint" style="margin:6px 0 0"><a class="btn" href="<?= $base ?>/admin/quick-ticket.php">🤖 Sell a ticket</a></p>
  </div>

  <div class="panel sh-noprint">
    <h2>Close the shift · शिफ्ट बन्द गर्नुहोस्</h2>
    <form method="post" id="shClose">
      <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="close">
      <input type="hidden" name="shift_id" value="<?= (int) $shift['id'] ?>">

      <p class="muted" style="margin:0;font-size:13px">Tap how many of each note you have — or just type the total below. · कति वटा नोट छ लेख्नुहोस्, वा तल जम्मा रकम लेख्नुहोस्।</p>
      <div class="sh-pad">
        <?php foreach ($deskNotes as $d): ?>
          <label><span><?= Security::e($curSym) ?><?= $d ?></span><input type="text" inputmode="numeric" pattern="[0-9]*" name="d<?= $d ?>" data-d="<?= $d ?>" autocomplete="off" placeholder="0" aria-label="Number of <?= $d ?> <?= Security::e($shiftCur) ?> notes"></label>
        <?php endforeach; ?>
      </div>

      <div class="sh-big">
        <label>Counted cash · गनेको नगद (<?= Security::e($curSym !== '' ? $curSym : $shiftCur) ?>)
          <input type="text" name="counted_cash" id="shCounted" inputmode="decimal" autocomplete="off" value="" required>
        </label>
      </div>

      <details class="sh-more">
        <summary>Gave cash back or paid an expense from the drawer? · दराजबाट नगद दिनुभयो?</summary>
        <div class="sh-big" style="margin-top:8px">
          <label>Cash paid out (₹)
            <input type="text" name="paid_out" id="shPaid" inputmode="decimal" autocomplete="off" value="">
          </label>
        </div>
      </details>
      <div class="sh-note"><input type="text" name="note" maxlength="255" placeholder="Note (optional) · टिप्पणी"></div>

      <div class="sh-sum" style="grid-template-columns:1fr">
        <div class="sh-diff" id="shDiff"><small>Difference · फरक</small><b id="shDiffV">—</b></div>
      </div>
      <button type="submit" class="btn sh-go red">■ Close shift</button>
    </form>
  </div>

  <script>
  (function(){
    var f=document.getElementById('shClose'); if(!f) return;
    var pads=f.querySelectorAll('[data-d]'), counted=document.getElementById('shCounted'), paid=document.getElementById('shPaid');
    var exp0=parseFloat(document.getElementById('shExpected').getAttribute('data-v'))||0;
    var box=document.getElementById('shDiff'), out=document.getElementById('shDiffV');
    var tol=<?= json_encode(max(0.0, Settings::getFloat('counter_shift_tolerance', 0.0))) ?>;
    function num(el){return el&&el.value?parseFloat(String(el.value).replace(/[^0-9.]/g,''))||0:0;}
    function rupee(n){return '₹'+Math.abs(n).toLocaleString('en-IN',{maximumFractionDigits:2});}
    function show(){
      if(counted.value===''){box.className='sh-diff';out.textContent='—';return;}
      var diff=Math.round((num(counted)-(exp0-num(paid)))*100)/100;
      if(Math.abs(diff)<=tol+0.004){box.className='sh-diff ok';out.textContent='✅ Matches · मिल्यो';}
      else if(diff<0){box.className='sh-diff short';out.textContent='🔴 Short · कम '+rupee(diff);}
      else{box.className='sh-diff over';out.textContent='🟠 Over · बढी '+rupee(diff);}
    }
    function fromPad(){
      var sum=0,any=false;
      for(var i=0;i<pads.length;i++){var n=parseInt(pads[i].value,10)||0; if(n>0){any=true;sum+=n*parseInt(pads[i].getAttribute('data-d'),10);}}
      if(any){counted.value=String(sum);counted.readOnly=true;}else{counted.readOnly=false;}
      show();
    }
    for(var i=0;i<pads.length;i++) pads[i].addEventListener('input',fromPad);
    counted.addEventListener('input',show); if(paid) paid.addEventListener('input',show);
    f.addEventListener('submit',function(e){
      if(box.className.indexOf('short')>=0||box.className.indexOf('over')>=0){
        if(!confirm('The drawer does not match ('+out.textContent+'). Close anyway?\nनगद मिलेको छैन। तैपनि बन्द गर्ने?')) e.preventDefault();
      }
    });
  })();
  </script>
<?php endif; ?>

<?php if ($on && $history !== []): ?>
  <div class="panel sh-hist">
    <h2><?= $isOffice ? 'All shifts' : 'Your shifts' ?></h2>
    <div class="dt-wrap">
      <table class="dt card-table">
        <thead><tr>
          <?php if ($isOffice): ?><th>Who</th><?php endif; ?>
          <th>Opened</th><th>Closed</th><th data-type="num">Tickets</th><th data-type="num">Cash sales</th><th data-type="num">UPI</th>
          <th data-type="num">Expected</th><th data-type="num">Counted</th><th>Result</th>
        </tr></thead>
        <tbody>
        <?php foreach ($history as $h):
          $open = (int) ($h['is_open'] ?? 0) === 1;
          $ver  = $open ? 'open' : CounterShift::verdict((float) $h['variance']);
          $hCur = CounterShift::currencyOf($h); ?>
          <tr>
            <?php if ($isOffice): ?><td data-label="Who"><?= Security::e($whoName($h)) ?></td><?php endif; ?>
            <td data-label="Opened" data-sort="<?= Security::e((string) $h['opened_at']) ?>"><?= Security::e(substr((string) $h['opened_at'], 0, 16)) ?></td>
            <td data-label="Closed"><?= $open ? '—' : Security::e(substr((string) $h['closed_at'], 0, 16)) ?></td>
            <td data-label="Tickets" class="num"><?= $open ? '…' : (int) $h['tickets'] ?></td>
            <td data-label="Cash sales" class="num"><?= $open ? '…' : Security::e($mc((float) $h['cash_sales'], $hCur)) ?></td>
            <td data-label="UPI" class="num"><?= $open ? '…' : Security::e($mc((float) $h['upi_sales'], $hCur)) ?></td>
            <td data-label="Expected" class="num"><?= $open ? '…' : Security::e($mc((float) $h['expected_cash'], $hCur)) ?></td>
            <td data-label="Counted" class="num"><?= $open ? '…' : Security::e($mc((float) $h['counted_cash'], $hCur)) ?></td>
            <td data-label="Result">
              <?php if ($open): ?>
                <span class="sh-pill open">open</span>
                <?php if ($isOffice && (int) $h['admin_id'] !== $me): ?> <a href="<?= $base ?>/admin/shift.php?shift=<?= (int) $h['id'] ?>" style="font-size:12.5px">close for them</a><?php endif; ?>
              <?php else: ?>
                <span class="sh-pill <?= $ver ?>"><?= $ver === 'ok' ? 'matches' : ($ver === 'short' ? 'short ' . Security::e($mc(abs((float) $h['variance']), $hCur)) : 'over ' . Security::e($mc((float) $h['variance']), $hCur)) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($on && $isOffice): ?>
  <form method="post" class="sh-noprint" style="margin:6px 0 0" onsubmit="return confirm('Switch shift and cash count OFF for every counter?')">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
    <input type="hidden" name="action" value="disable">
    <button type="submit" class="btn ghost" style="font-size:12.5px">Switch this feature off</button>
  </form>
<?php endif; ?>
</div>

<?php admin_footer(); ?>
