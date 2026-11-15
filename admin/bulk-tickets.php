<?php
/**
 * admin/bulk-tickets.php — many tickets from one list, in the panel
 * (16 Nov 2026, P8-7c: "agent quick action: bulk Excel passenger upload ->
 * book all").
 *
 * The same engine as the WhatsApp bulk list (includes/wabulk.php), so the
 * same rules: the list is read by code, never guessed at; one passenger per
 * line (name, 10-digit mobile, M/F, age); one mobile = one booking;
 * every row that does not read is reported by its line number.
 *
 *   1. paste the list, or upload a CSV (Excel: File -> Save as -> CSV);
 *      the optional boxes (date, pickup, direction, payment) win over any
 *      Date: / From: / To: / Pay: lines in the list
 *   2. Preview -> WaBulk::quote(): every name, number, seat count and fare
 *      read back, nothing sold, nothing held
 *   3. Confirm -> WaBulk::issue(): each booking re-planned and refused if
 *      its bus, date, pickup or fare moved since the preview, then sold
 *      through QuickTicket::sell() exactly like the desk (seat lock,
 *      cut-off, fare, commission; the ticket goes to the passenger's
 *      WhatsApp as on every desk sale), credited to the signed-in seller
 *
 * One-shot: the preview's token is staged under this seller's key
 * ('panel:<admin id>'); the Confirm button posts it back and the stage is
 * consumed by exactly one request (WaBulk::take) - a reload, a double
 * click or a second tab cannot sell the list twice. After a sale the page
 * redirects (POST -> GET), so a reload only shows the result again.
 *
 * Who: the desk sale's gate - admin/smartbook.php opens on bookings.view,
 * and the sale itself needs a selling role (Auth::isSellingStaff, as
 * api/quick-ticket.php). CSRF on every POST; quote and sale audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/wabulk.php';
require_once INCLUDE_PATH . '/seats.php';

$me      = (int) $admin['id'];
$canSell = Auth::isSellingStaff();
$ctx     = ['phone' => 'panel:' . $me, 'role' => 'staff', 'channel' => 'panel', 'turn' => 0, 'admin' => $admin];
$flash   = null;
$preview = null;
$maxRows = max(1, Settings::getInt('wa_bulk_max_rows', 30));
$postStr = static fn(string $k): string => is_scalar($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : '';
$form    = [
    'list' => mb_substr(str_replace("\r\n", "\n", $postStr('list')), 0, 20000),
    'date' => Security::isValidDate($postStr('date')) ? $postStr('date') : '',
    'from' => Security::clean($postStr('from'), 80),
    'dir'  => in_array($postStr('dir'), ['toNepal', 'toIndia'], true) ? $postStr('dir') : '',
    'pay'  => in_array($postStr('pay'), ['cash', 'upi', 'esewa', 'bank'], true) ? $postStr('pay') : '',
];

/* A sample file to fill in Excel (GET ?sample=1). */
if (($_GET['sample'] ?? '') === '1') {
    require_once __DIR__ . '/_export.php';
    shg_export_csv('bulk-tickets-sample.csv', ['Name', 'Mobile', 'Gender', 'Age'], [
        ['Ram Thapa', '9876543210', 'M', '35'],
        ['Sita Thapa', '9876543211', 'F', '32'],
        ['Maya Thapa', '9876543211', 'F', '8'],
        ['Hari Gurung', '977 9812345678', 'M', ''],
    ]);
}

/* ---------------------------------------------------------------- POST */
if (Security::isPost()) {
    $act = $postStr('action');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Page was open too long — please try again. · सत्र सकियो, फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$canSell) {
        $flash = ['bad', 'Your role can view tickets but not sell. · तपाईंको भूमिकाले टिकट बेच्न मिल्दैन।'];
    } elseif (!Security::rateLimit('bulk_tickets', (string) $me, 40, 600)) {
        $flash = ['bad', 'Too many tries — wait a few minutes. · धेरै पटक भयो, केही मिनेट पर्खनुहोस्।'];
    } elseif ($act === 'cancel') {
        WaBulk::clear('panel:' . $me);
        $flash = ['ok', 'Preview cleared — nothing was sold. · रद्द भयो, कुनै टिकट काटिएन।'];
    } elseif ($act === 'quote') {
        /* The CSV, when one came, replaces the box (and is shown in it, so the
           line numbers in any error point at what the seller can see). */
        $up = $_FILES['csv'] ?? null;
        if (is_array($up) && (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $ext = strtolower(pathinfo((string) ($up['name'] ?? ''), PATHINFO_EXTENSION));
            if ((int) $up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $up['tmp_name'])) {
                $flash = ['bad', 'The file did not arrive — please choose it again. · फाइल आएन, फेरि छान्नुहोस्।'];
            } elseif (!in_array($ext, ['csv', 'txt'], true)) {
                $flash = ['bad', 'Please upload a .csv file (Excel: File → Save as → CSV). · .csv फाइल मात्र।'];
            } elseif ((int) $up['size'] > 256 * 1024) {
                $flash = ['bad', 'That file is too big (256 KB at most). · फाइल धेरै ठूलो भयो।'];
            } else {
                $lines = WaBulk::csvLines((string) file_get_contents((string) $up['tmp_name']));
                if ($lines === []) {
                    $flash = ['bad', 'No passenger rows in that file. · फाइलमा यात्रु भेटिएन।'];
                } else {
                    $form['list'] = implode("\n", $lines);
                }
            }
        }
        if ($flash === null) {
            if (trim($form['list']) === '') {
                $flash = ['bad', 'Paste the list or choose a CSV file first. · सूची टाँस्नुहोस् वा CSV छान्नुहोस्।'];
            } else {
                /* The boxes go AFTER the list: a later Date:/From: line wins in
                   WaBulk::parse(), and the passenger line numbers stay those of
                   the box. */
                $tail = [];
                if ($form['date'] !== '') { $tail[] = 'Date: ' . $form['date']; }
                if ($form['from'] !== '') { $tail[] = 'From: ' . $form['from']; }
                if ($form['dir'] !== '')  { $tail[] = 'To: ' . ($form['dir'] === 'toNepal' ? 'nepal' : 'india'); }
                if ($form['pay'] !== '')  { $tail[] = 'Pay: ' . $form['pay']; }
                $text    = rtrim($form['list']) . ($tail !== [] ? "\n" . implode("\n", $tail) : '');
                $preview = WaBulk::quote($ctx, WaBulk::parse($text));
                $pd      = (array) ($preview['data'] ?? []);
                Logger::audit('bulk_tickets.quote', 'booking', '', null, [
                    'bookings' => count((array) ($pd['bookings'] ?? [])), 'seats' => (int) ($pd['seats'] ?? 0),
                    'total' => (float) ($pd['total'] ?? 0), 'errors' => count((array) ($pd['errors'] ?? [])),
                ], 'Bulk list previewed on admin/bulk-tickets.php (nothing sold)');
            }
        }
    } elseif ($act === 'issue') {
        @set_time_limit(180);
        $res  = WaBulk::issue($ctx, $admin, $postStr('token') !== '' ? $postStr('token') : 'none');
        $data = (array) ($res['data'] ?? []);
        $sold = array_values((array) ($data['sold'] ?? []));
        Logger::audit('bulk_tickets.issue', 'booking', $sold !== [] ? (string) ($sold[0]['pnr'] ?? '') : '', null, [
            'sold'   => array_map(static fn(array $d): string => (string) ($d['pnr'] ?? ''), $sold),
            'failed' => count((array) ($data['failed'] ?? [])),
            'seats'  => (int) ($data['seats'] ?? 0),
            'total'  => (float) ($data['total'] ?? 0),
        ], $sold !== [] ? 'Bulk tickets sold from admin/bulk-tickets.php' : 'Bulk confirm refused: ' . mb_substr((string) ($res['text'] ?? ''), 0, 150));
        $_SESSION['bulk_tickets_result'] = ['at' => time(), 'ok' => !empty($res['ok']), 'text' => (string) ($res['text'] ?? ''), 'data' => $data];
        Response::redirect('admin/bulk-tickets.php?done=1');
    }
}

/* The result of the last Confirm, shown once (POST -> redirect -> GET). */
$result = null;
if (($_GET['done'] ?? '') === '1' && is_array($_SESSION['bulk_tickets_result'] ?? null)) {
    $result = $_SESSION['bulk_tickets_result'];
    unset($_SESSION['bulk_tickets_result']);
}

$pickups = [];
try {
    foreach (Database::fetchAll(
        "SELECT DISTINCT s.stop_name FROM route_stops s JOIN routes r ON r.id = s.route_id
          WHERE s.is_active = 1 AND r.is_active = 1 AND s.stop_type = 'boarding' ORDER BY s.stop_name LIMIT 80"
    ) as $p) {
        $pickups[] = trim((string) preg_replace('/\s*\[[-\d.,\s]+\]\s*$/', '', (string) $p['stop_name']));
    }
} catch (Throwable $ex) {
    $pickups = [];
}
$seatLbl = static function (array $seats): string {
    try {
        return Seats::displayLabels(array_map('strval', $seats), 'sleeper', 'sharing');
    } catch (Throwable $ex) {
        return implode(', ', array_map('strval', $seats));
    }
};

admin_header('Bulk Tickets', 'bulk-tickets');
?>
<style>
  .bt{display:grid;gap:14px;max-width:980px}
  .bt .panel h2{margin:0 0 6px}
  .bt-help{font-size:13.5px;line-height:1.6;margin:0 0 6px;color:var(--ink)}
  .bt-help.mut{color:var(--mut)}
  .bt textarea{display:block;width:100%;min-height:190px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;resize:vertical;box-sizing:border-box}
  .bt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:10px 0}
  .bt label.f{display:block;font-size:12.5px;color:var(--mut)}
  .bt label.f input,.bt label.f select{display:block;width:100%;min-height:42px;margin-top:4px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:14px;box-sizing:border-box}
  .bt-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}
  .bt-row .btn{min-height:44px;display:inline-flex;align-items:center;gap:6px}
  .bt-file{font-size:13px;max-width:100%}
  .bt-bus{background:var(--hover);border-radius:10px;padding:10px 12px;font-size:14px;margin:0 0 10px}
  .bt-tbl{width:100%;border-collapse:collapse;font-size:13.5px;min-width:560px}
  .bt-tbl th,.bt-tbl td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
  .bt-tbl td.n{text-align:right;white-space:nowrap}
  .bt-warn{display:inline-block;background:#fff1dc;color:#7a4300;border-radius:8px;padding:2px 8px;font-size:12px;font-weight:700}
  :root[data-theme="dark"] .bt-warn{background:#3b2a12;color:#ffd9a8}
  .bt-err{margin:8px 0 0;padding-left:18px;color:#b42318;font-size:13px;line-height:1.55}
  :root[data-theme="dark"] .bt-err{color:#ff9b93}
  .bt-total{font-size:15px;font-weight:800;margin:10px 0 0}
</style>

<div class="bt">
<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($result !== null): $rd = (array) $result['data']; ?>
  <div class="panel">
    <h2><?= !empty($result['ok']) ? 'टिकट काटियो · Tickets issued' : 'काटिएन · Nothing sold' ?></h2>
    <?php if (!empty($rd['sold'])): ?>
      <p class="bt-total"><?= count((array) $rd['sold']) ?> booking(s) · <?= (int) ($rd['seats'] ?? 0) ?> seat(s) · <?= Security::e(inr((float) ($rd['total'] ?? 0))) ?></p>
      <div style="overflow-x:auto"><table class="bt-tbl">
        <thead><tr><th>#</th><th>यात्रु · Passengers</th><th>PNR</th><th>सिट · Seats</th><th class="n">भाडा · Fare</th><th>WhatsApp</th></tr></thead><tbody>
        <?php foreach ((array) $rd['sold'] as $i => $d): ?>
          <tr><td><?= $i + 1 ?></td><td><?= Security::e((string) $d['who']) ?></td>
            <td><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $d['pnr']) ?>"><?= Security::e((string) $d['pnr']) ?></a></td>
            <td><?= Security::e($seatLbl((array) ($d['seats'] ?? []))) ?></td>
            <td class="n"><?= Security::e(inr((float) ($d['total'] ?? 0))) ?></td>
            <td><?= !empty($d['sent']) ? '✓ sent' : '<span class="muted">not sent — share from the booking page</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
    <?php if (!empty($rd['failed'])): ?>
      <p class="bt-help" style="margin-top:10px"><b>❌ <?= count((array) $rd['failed']) ?> not sold · काटिएन</b> — fix these lines and preview them again:</p>
      <ul class="bt-err"><?php foreach ((array) $rd['failed'] as $f): ?><li><?= Security::e((string) $f['who'] . ' — ' . (string) $f['why']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (empty($rd['sold']) && empty($rd['failed'])): ?>
      <p class="bt-help"><?= Security::e((string) $result['text']) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($preview !== null): $pd = (array) ($preview['data'] ?? []); ?>
  <div class="panel" id="bt-preview">
    <h2>जाँच्नुहोस् · Check before you confirm</h2>
    <?php if (!empty($preview['ok'])): $bus = $pd['bus'] ?? null; ?>
      <?php if (is_array($bus)): ?>
        <p class="bt-bus">🚌 <b><?= Security::e($bus['route']) ?></b> · <?= Security::e($bus['dateLabel']) ?> · <?= Security::e($bus['depTime']) ?>
          · चढ्ने · pickup: <b><?= Security::e($bus['pickup']) ?></b><?= $bus['pickupTime'] !== '' ? ' ' . Security::e($bus['pickupTime']) : '' ?>
          <?= (int) $bus['seatsLeft'] < (int) ($pd['seats'] ?? 0) ? '<br><span class="bt-warn">⚠ Only ' . (int) $bus['seatsLeft'] . ' seats left on this bus — not all may fit</span>' : '' ?></p>
      <?php endif; ?>
      <div style="overflow-x:auto"><table class="bt-tbl">
        <thead><tr><th>#</th><th>यात्रु · Passengers</th><th>मोबाइल · Mobile</th><th class="n">सिट</th><th class="n">भाडा · Fare</th><th>बस · Bus</th></tr></thead><tbody>
        <?php foreach ((array) ($pd['bookings'] ?? []) as $i => $b):
            $names = [];
            foreach ((array) $b['passengers'] as $pi => $pn) {
                $g = $b['genders'][$pi] ?? null;
                $a = $b['ages'][$pi] ?? null;
                $names[] = $pn . ($g !== null ? ' (' . mb_substr((string) $g, 0, 1) . ')' : '') . ($a !== null ? ' ' . (int) $a : '');
            } ?>
          <tr><td><?= $i + 1 ?></td><td><?= Security::e(implode(', ', $names)) ?></td>
            <td style="white-space:nowrap"><?= ($b['country'] ?? '') === 'NP' ? '+977 ' : '' ?><?= Security::e((string) $b['phone']) ?></td>
            <td class="n"><?= (int) $b['seats'] ?></td><td class="n"><?= Security::e(inr((float) $b['total'])) ?></td>
            <td><?= !empty($b['sameBus']) ? '✓' : '<span class="bt-warn">⚠ ' . Security::e((string) ($b['dateLabel'] ?? '') . ' ' . (string) ($b['depTime'] ?? '') . ' · ' . (string) ($b['pickup'] ?? '')) . '</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <p class="bt-total">जम्मा · Total: <?= (int) ($pd['seats'] ?? 0) ?> seat(s) · <?= Security::e(inr((float) ($pd['total'] ?? 0))) ?> · भुक्तानी · pay: <?= Security::e((string) ($pd['pay'] ?? '')) ?></p>
    <?php endif; ?>
    <?php if (!empty($pd['errors'])): ?>
      <p class="bt-help" style="margin-top:10px"><b><?= !empty($preview['ok']) ? 'These lines will NOT be sold · यी लाइन काटिँदैनन्:' : 'No ticket could be made from this list · कुनै टिकट बन्न सकेन:' ?></b></p>
      <ul class="bt-err"><?php foreach (array_slice((array) $pd['errors'], 0, 30) as $er): ?><li><?= Security::e((string) $er) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($preview['ok']) && !empty($pd['token'])): ?>
      <div class="bt-row">
        <form method="post" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='काट्दै… · Issuing…';return true;">
          <?= Security::csrfField() ?><input type="hidden" name="action" value="issue"><input type="hidden" name="token" value="<?= Security::e((string) $pd['token']) ?>">
          <button class="btn" type="submit">✓ सबै काट्ने · Confirm and issue <?= count((array) ($pd['bookings'] ?? [])) ?> booking(s)</button>
        </form>
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="cancel"><button class="btn ghost" type="submit">✕ रद्द · Cancel</button></form>
      </div>
      <p class="bt-help mut" style="margin-top:8px">The preview holds no seats. On Confirm each booking is checked again: if its bus, date, pickup or fare changed meanwhile it is not sold and is listed. Valid for 15 minutes. · Confirm थिच्दा मात्र टिकट काटिन्छ।</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <div class="panel">
    <h2>बल्क टिकट · Bulk tickets</h2>
    <p class="bt-help">Paste the list, or upload a CSV (Excel: <b>File → Save as → CSV</b>). One passenger per line: <b>name, 10-digit mobile</b>, then M/F and age if known. The same mobile on two lines is one booking (a family). Up to <?= (int) $maxRows ?> passengers at a time. Nothing is sold until you check the preview and press Confirm.</p>
    <p class="bt-help mut">एक लाइनमा एक यात्रु: नाम, १० अंकको मोबाइल, M/F, उमेर। एउटै नम्बर दुई लाइनमा = एउटै बुकिङ। नेपाली नम्बरमा 977 अगाडि लेख्नुहोस्। <a href="?sample=1">⬇ Sample CSV</a></p>
    <?php if (!$canSell): ?>
      <p class="flash bad" style="margin-top:8px">Your role can read this page but not sell. · तपाईंको भूमिकाले टिकट बेच्न मिल्दैन।</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="bulk-tickets.php#bt-preview">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="quote">
      <label class="f" for="btList">सूची · List</label>
      <textarea id="btList" name="list" spellcheck="false" placeholder="Ram Thapa 9876543210 M 35&#10;Sita Thapa 9876543211 F 32&#10;Maya Thapa 9876543211 F 8&#10;Hari Gurung 977 9812345678 M"><?= Security::e($form['list']) ?></textarea>
      <div class="bt-row"><label class="f" style="flex:1 1 260px">वा CSV फाइल · or a CSV file <input class="bt-file" type="file" name="csv" accept=".csv,text/csv,.txt"></label></div>
      <div class="bt-grid">
        <label class="f">मिति · Date <input type="date" name="date" value="<?= Security::e($form['date']) ?>"></label>
        <label class="f">चढ्ने ठाउँ · Pickup <input name="from" list="btPickups" maxlength="80" value="<?= Security::e($form['from']) ?>" placeholder="Mehsana"></label>
        <label class="f">दिशा · Direction
          <select name="dir">
            <option value="">आफैं · from the pickup</option>
            <option value="toNepal" <?= $form['dir'] === 'toNepal' ? 'selected' : '' ?>>Gujarat → Rupaidiha</option>
            <option value="toIndia" <?= $form['dir'] === 'toIndia' ? 'selected' : '' ?>>Rupaidiha → Gujarat</option>
          </select></label>
        <label class="f">भुक्तानी · Payment
          <select name="pay">
            <option value="">पूर्वनिर्धारित · default</option>
            <?php foreach (['cash' => 'Cash · नगद', 'upi' => 'UPI', 'esewa' => 'eSewa', 'bank' => 'Bank'] as $pk => $pl): ?>
              <option value="<?= $pk ?>" <?= $form['pay'] === $pk ? 'selected' : '' ?>><?= Security::e($pl) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <datalist id="btPickups"><?php foreach ($pickups as $p): ?><option value="<?= Security::e($p) ?>"><?php endforeach; ?></datalist>
      <div class="bt-row"><button class="btn" type="submit" <?= $canSell ? '' : 'disabled' ?>>👁 हेर्ने · Preview</button></div>
    </form>
  </div>
</div>

<?php admin_footer(); ?>
