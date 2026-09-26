<?php
/**
 * admin/pricing.php — ONE screen for every price the office changes
 * (26 Sep 2026).
 *
 * WHY THIS PAGE EXISTS
 * --------------------
 * The fares were spread across four places: two settings rows for the
 * directional sharing fare, the private cabin prices buried inside the
 * cabin_pricing JSON, a per-departure override on the Bus Calendar, and a
 * `coupons` row for anything festival-shaped. The owner asked for "all fares
 * editable from one simple admin pricing screen", and the reason is not
 * tidiness: a price that lives in four places is a price that disagrees with
 * itself, and the passenger pays the disagreement.
 *
 * WHAT IT EDITS
 * -------------
 *   1. The point-to-point BOARD — an ordered rule list, first match wins.
 *      This is what a sharing sleeper seat costs from each pickup.
 *   2. The two DIRECTION fallbacks, used when no rule covers a pair.
 *   3. The VIP private cabin prices (single / double).
 *   4. The ADVANCE-BOOKING OFFER: hours, percentage, window, ceiling, which
 *      modes it covers, its name and the line the home page shows.
 *   5. Whether authorised admin WhatsApp numbers may change 1–4 by message.
 *
 * Every save is written through Settings and recorded in the audit log with
 * the old and the new value, because these rows decide money.
 *
 * WHAT IT DOES NOT EDIT
 * ---------------------
 * A single departure's own fare stays on the Bus Calendar (one bus, one
 * price), and typed coupon codes stay on Offers & Discounts. Both are
 * different objects from "the board", and merging them here would have hidden
 * a route-wide change behind a one-bus control.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

/* commissions.view, not payments.view: a ticket window holds payments.view,
   and this page carries the company's whole fare board and the office's own
   WhatsApp numbers. Same reasoning as accounting.php (26 Sep 2026). */
$admin = admin_boot('commissions.view');

$canEdit = Auth::isSuperadmin() || Auth::canManageSettings();
$flash   = null;

/** Audit one row's change, only when it actually changed. */
$auditRow = static function (string $key, mixed $old, mixed $new): void {
    $o = is_array($old) ? json_encode($old) : (string) $old;
    $n = is_array($new) ? json_encode($new) : (string) $new;
    if ($o === $n) {
        return;
    }
    Logger::audit(
        'pricing.set',
        'setting',
        $key,
        ['value' => $o],
        ['value' => $n],
        $key . ': ' . ($o === '' ? '(blank)' : $o) . ' → ' . ($n === '' ? '(blank)' : $n)
    );
};

/* =====================================================================
 *  SAVE
 * ================================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only a manager or super-admin can change prices.'];
    } else {
        $section = (string) ($_POST['section'] ?? '');

        try {
            /* ---- 1. the board ---------------------------------------- */
            if ($section === 'board') {
                $rows = [];
                foreach ((array) ($_POST['rule'] ?? []) as $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    if (!empty($r['drop'])) {
                        continue;                       // the row's Remove box is ticked
                    }
                    $rows[] = [
                        'from'   => trim((string) ($r['from'] ?? '')),
                        'to'     => trim((string) ($r['to'] ?? '')),
                        'amount' => (float) ($r['amount'] ?? 0),
                        'note'   => trim((string) ($r['note'] ?? '')),
                    ];
                }
                $clean = Fare::normaliseRules($rows);
                /* A board that refuses everything is worse than no board: the
                   engine would silently fall through to the two directional
                   fares for every journey. Say so instead. */
                if ($clean === [] && $rows !== []) {
                    throw new RuntimeException('No usable rule in that list — every row needs a From, a To and an amount above zero.');
                }
                $old = Settings::getArray('fare_rules', []);
                Settings::set('fare_rules', $clean, 'json', 'pricing', true);
                Settings::flush();
                $auditRow('fare_rules', $old, $clean);
                $flash = ['ok', count($clean) . ' fare rule' . (count($clean) === 1 ? '' : 's') . ' saved. The next search and the next counter sale use them.'];
            }

            /* ---- 2. direction fallbacks + VIP cabin prices ----------- */
            if ($section === 'base') {
                $ftn = (float) ($_POST['fare_to_nepal'] ?? 0);
                $fti = (float) ($_POST['fare_to_india'] ?? 0);
                $vs  = (float) ($_POST['vip_single'] ?? 0);
                $vd  = (float) ($_POST['vip_double'] ?? 0);

                foreach (['fare_to_nepal' => $ftn, 'fare_to_india' => $fti] as $k => $v) {
                    if ($v <= 0 || $v > 1000000) {
                        throw new RuntimeException('The fallback fares must each be between ₹1 and ₹10,00,000.');
                    }
                    $old = (string) Settings::get($k, '');
                    Settings::set($k, $v, 'float', 'pricing', true);
                    $auditRow($k, $old, (string) $v);
                }
                /* main_fares is a legacy override that WINS inside
                   Fare::dirFares(); keep it in step or a saved fallback would
                   appear not to take. Same rule settings.php follows. */
                if (Settings::get('main_fares', null) !== null) {
                    Settings::set('main_fares', ['toNepal' => $ftn, 'toIndia' => $fti], 'json', 'pricing', false);
                }

                if ($vs > 0 || $vd > 0) {
                    Settings::flush();
                    $cp  = Settings::getArray('cabin_pricing', Fare::pricing());
                    $old = json_encode([
                        'single' => $cp['private']['single_1pax']['offline'] ?? null,
                        'double' => $cp['private']['double_2pax']['offline'] ?? null,
                    ]);
                    if ($vs > 0) {
                        $cp['private']['single_1pax']['offline'] = $vs;
                        $cp['private']['single_1pax']['online']  = $vs;   // flat: online == offline
                    }
                    if ($vd > 0) {
                        $cp['private']['double_2pax']['offline'] = $vd;
                        $cp['private']['double_2pax']['online']  = $vd;
                    }
                    Settings::set('cabin_pricing', $cp, 'json', 'pricing', true);
                    $auditRow('cabin_pricing.private', $old, json_encode(['single' => $vs ?: null, 'double' => $vd ?: null]));
                }
                Settings::flush();
                $flash = ['ok', 'Base fares saved.'];
            }

            /* ---- 3. the advance-booking offer ------------------------ */
            if ($section === 'offer') {
                $on    = isset($_POST['advance_offer_on']) ? '1' : '0';
                $hours = (int) ($_POST['advance_offer_hours'] ?? 24);
                $pct   = round((float) ($_POST['advance_offer_percent'] ?? 0), 2);
                $from  = trim((string) ($_POST['advance_offer_from'] ?? ''));
                $to    = trim((string) ($_POST['advance_offer_to'] ?? ''));
                $max   = max(0.0, round((float) ($_POST['advance_offer_max_inr'] ?? 0), 2));
                $modes = (string) ($_POST['advance_offer_modes'] ?? 'all');
                $title = Security::clean($_POST['advance_offer_title'] ?? '', 120);
                $text  = Security::clean($_POST['advance_offer_text'] ?? '', 240);
                /* 26 Sep 2026 follow-up: narrow the offer to one route or one
                   departure date, and count the ceiling per passenger. */
                $route  = max(0, (int) ($_POST['advance_offer_route'] ?? 0));
                $date   = trim((string) ($_POST['advance_offer_date'] ?? ''));
                $maxPer = (string) ($_POST['advance_offer_max_per'] ?? 'booking');
                if (!in_array($maxPer, ['booking', 'passenger'], true)) {
                    $maxPer = 'booking';
                }
                if ($date !== '' && !Security::isValidDate($date)) {
                    throw new RuntimeException('The one-departure date must be YYYY-MM-DD, or left blank.');
                }
                if ($route > 0 && Database::fetch('SELECT id FROM routes WHERE id = :i LIMIT 1', ['i' => $route]) === null) {
                    throw new RuntimeException('That route does not exist.');
                }

                if ($hours < 0 || $hours > 8760) {
                    throw new RuntimeException('Advance hours must be between 0 and 8760 (a year).');
                }
                if ($pct < 0 || $pct > 100) {
                    throw new RuntimeException('The discount must be between 0 and 100 percent.');
                }
                foreach (['advance_offer_from' => $from, 'advance_offer_to' => $to] as $k => $v) {
                    if ($v !== '' && !Security::isValidDate($v)) {
                        throw new RuntimeException('The offer dates must be YYYY-MM-DD, or left blank.');
                    }
                }
                if ($from !== '' && $to !== '' && $from > $to) {
                    throw new RuntimeException('The offer cannot end before it starts.');
                }
                if (!in_array($modes, ['all', 'sharing', 'private'], true)) {
                    $modes = 'all';
                }

                foreach ([
                    'advance_offer_on'      => ['bool',   $on],
                    'advance_offer_hours'   => ['int',    (string) $hours],
                    'advance_offer_percent' => ['float',  (string) $pct],
                    'advance_offer_from'    => ['string', $from],
                    'advance_offer_to'      => ['string', $to],
                    'advance_offer_max_inr' => ['float',  (string) $max],
                    'advance_offer_modes'   => ['string', $modes],
                    'advance_offer_title'   => ['string', $title],
                    'advance_offer_text'    => ['string', $text],
                    'advance_offer_route'   => ['int',    (string) $route],
                    'advance_offer_date'    => ['string', $date],
                    'advance_offer_max_per' => ['string', $maxPer],
                ] as $k => [$type, $v]) {
                    $old = (string) Settings::get($k, '');
                    Settings::set($k, $v, $type, 'pricing', true);
                    $auditRow($k, $old, $v);
                }
                Settings::flush();
                $flash = ['ok', 'Advance offer saved — it is now ' . ($on === '1' ? 'ON' : 'OFF') . '.'];
            }

            /* ---- 4. WhatsApp rule control ---------------------------- */
            if ($section === 'wa') {
                $on   = isset($_POST['wa_rules_control']) ? '1' : '0';
                $nums = [];
                foreach (preg_split('/[\s,;]+/', (string) ($_POST['wa_rules_numbers'] ?? '')) ?: [] as $n) {
                    $d = normalisePhone($n);
                    if ($d !== '') {
                        $nums[$d] = true;
                    }
                }
                $list = implode(',', array_keys($nums));
                if ($on === '1' && $list === '') {
                    throw new RuntimeException('Add at least one authorised number before switching WhatsApp control on.');
                }
                foreach ([
                    'wa_rules_control' => ['bool',   $on],
                    'wa_rules_numbers' => ['string', $list],
                ] as $k => [$type, $v]) {
                    $old = (string) Settings::get($k, '');
                    Settings::set($k, $v, $type, 'whatsapp', false);
                    $auditRow($k, $old, $v);
                }
                Settings::flush();
                $flash = ['ok', 'WhatsApp rule control is ' . ($on === '1' ? 'ON' : 'OFF') . '.'];
            }
        } catch (Throwable $e) {
            Settings::flush();
            $flash = ['bad', 'Not saved — ' . $e->getMessage()];
        }
    }
}

/* =====================================================================
 *  READ THE CURRENT STATE
 * ================================================================= */
Seats::forgetModeMap();          // a settings save in this request must not read stale
$rules   = Fare::fareRules();
$dir     = Fare::dirFares();
$cpNow   = Settings::getArray('cabin_pricing', Fare::pricing());
$vipS    = (float) ($cpNow['private']['single_1pax']['offline'] ?? 3800);
$vipD    = (float) ($cpNow['private']['double_2pax']['offline'] ?? 7600);
$offer   = Fare::advanceOffer();
$board   = Fare::fareBoard();
$points  = Fare::mainPoints();
$routesAll = Database::fetchAll("SELECT id, route_code, from_city, to_city, coach_type FROM routes WHERE is_active = 1 ORDER BY from_city, to_city");
$waOn    = Settings::getBool('wa_rules_control', false);
$waNums  = array_values(array_filter(array_map('trim', explode(',', Settings::getString('wa_rules_numbers', '')))));

/* Does each authorised number actually belong to an office account? A number
   in this list that is not a manager / super-admin can never be obeyed (the
   assistant's role gate refuses it first), so say so here rather than let the
   owner believe a number is armed when it is not. */
$waCheck = [];
foreach ($waNums as $n) {
    $row = null;
    foreach (Database::fetchAll("SELECT full_name, phone, role, is_active FROM admins WHERE phone IS NOT NULL AND phone <> ''") as $a) {
        if (normalisePhone((string) $a['phone']) === $n) {
            $row = $a;
            break;
        }
    }
    $waCheck[$n] = $row;
}

/* A worked example so the percentage is not an abstraction. */
$sample     = $board !== [] ? (float) $board[0]['amount'] : 2200.0;
$sampleCut  = $offer['percent'] > 0 ? round($sample * $offer['percent'] / 100) : 0.0;
if ($offer['max'] > 0) {
    $sampleCut = min($sampleCut, (float) $offer['max']);
}

/* "What would this journey on this date cost?" (26 Sep 2026 follow-up).
   Mirrors api/quote.php step for step — the same board, the same cabin list,
   the same per-departure override and the same advance-offer clock — so the
   office reads here exactly what a passenger will be charged. GET only:
   nothing is saved. */
$pv = [
    'route' => (int) ($_GET['pv_route'] ?? 0),
    'date'  => Security::clean((string) ($_GET['pv_date'] ?? ''), 10),
    'mode'  => (string) ($_GET['pv_mode'] ?? 'sharing'),
    'seats' => max(1, min(20, (int) ($_GET['pv_seats'] ?? 1))),
    'from'  => Security::clean((string) ($_GET['pv_from'] ?? ''), 191),
    'to'    => Security::clean((string) ($_GET['pv_to'] ?? ''), 191),
];
$pvOut = null;
if ($pv['route'] > 0 && Security::isValidDate($pv['date'])) {
    if (!class_exists('BookingService')) {
        require_once INCLUDE_PATH . '/booking.php';
    }
    $pvRoute = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $pv['route']]);
    if ($pvRoute !== null) {
        $pvMode  = in_array($pv['mode'], ['sharing', 'private'], true) ? $pv['mode'] : 'sharing';
        $pvEnds  = Fare::journeyPoints($pvRoute, $pv['from'], $pv['to']);
        $pvSched = Database::fetch(
            'SELECT id, route_id, fare_override, dep_time_override FROM schedules WHERE route_id = :r AND travel_date = :d ORDER BY id LIMIT 1',
            ['r' => $pv['route'], 'd' => $pv['date']]
        );
        $pvOver = $pvSched !== null ? BookingService::scheduleFareOverride($pvSched) : null;
        if ($pvOver !== null && ($pvRoute['coach_type'] !== 'sleeper' || $pvMode !== 'private')) {
            $pvPer   = $pvOver;
            $pvBase  = $pvPer * $pv['seats'];
            $pvLabel = "this bus's own fare";
        } elseif ($pvRoute['coach_type'] === 'sleeper') {
            $pvCabin = Fare::cabinFare($pvMode === 'private' && $pv['seats'] >= 2 ? 'double' : 'single', $pvMode, $pv['seats'], true, (string) ($pvRoute['to_city'] ?? ''), 4, $pvEnds['from']);
            $pvBase  = (float) $pvCabin['total'];
            $pvPer   = $pv['seats'] > 0 ? round($pvBase / $pv['seats'], 2) : $pvBase;
            $pvLabel = (string) ($pvCabin['label'] ?? 'the fare board');
        } else {
            $pvPer   = (float) $pvRoute['base_fare'];
            $pvBase  = $pvPer * $pv['seats'];
            $pvLabel = 'route base fare';
        }
        $pvDep   = Fare::departureTime($pvSched, $pvRoute);
        $pvQuote = Fare::quote($pvBase, $pv['seats'], 0, 0, 0, '', '', $pv['route'], [
            'travelDate' => $pv['date'], 'departureTime' => $pvDep, 'bookingMode' => $pvMode,
        ]);
        $pvOut = ['ends' => $pvEnds, 'per' => $pvPer, 'label' => $pvLabel, 'q' => $pvQuote, 'mode' => $pvMode, 'dep' => $pvDep];
    }
}

admin_header('Fares & offers', 'pricing');
?>
<style>
  .pr-wrap{display:grid;gap:18px}
  .pr-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .pr-card > h2{margin:0;padding:14px 18px;border-bottom:1px solid var(--line);font-size:16px;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}
  .pr-card > h2 small{font-weight:500;color:var(--mut);font-size:12px}
  .pr-body{padding:16px 18px}
  .pr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
  .pr-grid label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
  .pr-grid input,.pr-grid select{padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:16px;font-weight:700;background:var(--card);color:var(--ink);min-width:0}
  .pr-grid small{font-weight:500;text-transform:none;letter-spacing:0;color:var(--mut)}
  table.pr-rules{width:100%;border-collapse:collapse;font-size:14px}
  table.pr-rules th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--mut);padding:8px 6px;border-bottom:1px solid var(--line)}
  table.pr-rules td{padding:6px;border-bottom:1px solid var(--line);vertical-align:middle}
  table.pr-rules input[type=text],table.pr-rules input[type=number]{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font-size:15px}
  table.pr-rules td.num{width:130px}
  .pr-eff{width:100%;border-collapse:collapse;font-size:13px}
  .pr-eff td,.pr-eff th{padding:6px 8px;border-bottom:1px solid var(--line)}
  .pr-eff th{text-align:left;font-size:11px;text-transform:uppercase;color:var(--mut)}
  .pr-eff .amt{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}
  .pr-eff .src{font-size:11px;color:var(--mut)}
  .pr-hint{font-size:12px;color:var(--mut);line-height:1.6}
  .pr-hint code{background:var(--bg2,rgba(0,0,0,.05));padding:1px 5px;border-radius:5px}
  .pr-live{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;padding:3px 10px;border-radius:999px}
  .pr-live.on{background:rgba(46,160,67,.14);color:#2ea043}
  .pr-live.off{background:rgba(120,120,120,.14);color:var(--mut)}
  .pr-eg{margin-top:12px;padding:12px 14px;border:1px dashed var(--line);border-radius:10px;font-size:14px;line-height:1.7}
  .pr-actions{padding:12px 18px;border-top:1px solid var(--line);display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .pr-bad{color:#d1242f;font-weight:700}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'good' : 'bad' ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$canEdit): ?>
  <div class="flash">You can read these numbers. Only a manager or super-admin may change them.</div>
<?php endif; ?>

<div class="pr-wrap">

  <!-- ============ 1. THE BOARD ============ -->
  <form method="post" class="pr-card">
    <?= Security::csrfField() ?>
    <input type="hidden" name="section" value="board">
    <h2>🧭 Fare board · भाडा तालिका
      <small>per person, sharing sleeper · first matching rule wins</small></h2>
    <div class="pr-body">
      <p class="pr-hint">
        Each rule says what ONE journey costs. <code>From</code> and <code>To</code> take a
        pickup name, or a whole side of the border: <code>@india</code> = any Gujarat point,
        <code>@nepal</code> = any Nepal point, <code>*</code> = anything.
        Rules are read top to bottom and the <b>first one that fits</b> is charged — so put
        the exact towns above the <code>@india</code> catch-alls.
        “Ahmedabad” is understood as <b>S Hari Parking, Nana Chiloda</b>.
      </p>
      <table class="pr-rules">
        <thead><tr><th>#</th><th>From</th><th>To</th><th>₹ per person</th><th>Note</th><th>Remove</th></tr></thead>
        <tbody id="prRules">
        <?php foreach ($rules as $i => $r): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><input type="text" name="rule[<?= $i ?>][from]" value="<?= Security::e($r['from']) ?>" <?= $canEdit ? '' : 'readonly' ?>></td>
            <td><input type="text" name="rule[<?= $i ?>][to]" value="<?= Security::e($r['to']) ?>" <?= $canEdit ? '' : 'readonly' ?>></td>
            <td class="num"><input type="number" min="1" max="1000000" step="1" name="rule[<?= $i ?>][amount]" value="<?= (int) round($r['amount']) ?>" <?= $canEdit ? '' : 'readonly' ?>></td>
            <td><input type="text" name="rule[<?= $i ?>][note]" value="<?= Security::e($r['note']) ?>" <?= $canEdit ? '' : 'readonly' ?>></td>
            <td><input type="checkbox" name="rule[<?= $i ?>][drop]" value="1" <?= $canEdit ? '' : 'disabled' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($canEdit): ?>
        <p style="margin:12px 0 0"><button type="button" class="btn" id="prAdd">+ Add a rule</button></p>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
      <div class="pr-actions"><button class="btn primary" type="submit">Save the board</button>
        <span class="pr-hint">Applies to the next search and the next sale. Tickets already sold keep their price.</span></div>
    <?php endif; ?>
  </form>

  <!-- ============ what that board actually charges ============ -->
  <div class="pr-card">
    <h2>✅ What each pickup pays now <small>every bookable pair, worked out by the rules above</small></h2>
    <div class="pr-body">
      <table class="pr-eff">
        <thead><tr><th>Journey</th><th class="amt">Per person</th><th>Priced by</th></tr></thead>
        <tbody>
        <?php foreach ($board as $b): ?>
          <tr>
            <td><?= Security::e($b['from']) ?> → <?= Security::e($b['to']) ?></td>
            <td class="amt"><?= Security::e(inr($b['amount'])) ?></td>
            <td class="src"><?= $b['source'] === 'rule' ? 'a rule above' : 'the direction fallback' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="pr-hint" style="margin-top:10px">
        A VIP private cabin is priced from the cabin list below, not per person.
        One departure can still carry its own fare — that stays on the Bus Calendar.
      </p>
    </div>
  </div>

  <!-- ============ 1b. WHAT WOULD THIS JOURNEY COST ============ -->
  <form method="get" class="pr-card" id="pvBox">
    <h2>🔎 What would this journey on this date cost? <small>the exact charge a passenger meets — board, this bus's fare, the offer, all of it</small></h2>
    <div class="pr-body">
      <div class="pr-grid">
        <label>Bus / route
          <select name="pv_route">
            <?php foreach ($routesAll as $rt): ?>
              <option value="<?= (int) $rt['id'] ?>" <?= $pv['route'] === (int) $rt['id'] ? 'selected' : '' ?>><?= Security::e($rt['from_city'] . ' → ' . $rt['to_city'] . ' (' . $rt['route_code'] . ')') ?></option>
            <?php endforeach; ?>
          </select></label>
        <label>Travel date
          <input type="date" name="pv_date" value="<?= Security::e($pv['date'] !== '' ? $pv['date'] : todayISO()) ?>" required></label>
        <label>Booking
          <select name="pv_mode">
            <option value="sharing" <?= $pv['mode'] !== 'private' ? 'selected' : '' ?>>Sharing sleeper</option>
            <option value="private" <?= $pv['mode'] === 'private' ? 'selected' : '' ?>>VIP private cabin</option>
          </select></label>
        <label>Passengers
          <input type="number" name="pv_seats" min="1" max="20" value="<?= (int) $pv['seats'] ?>"></label>
        <label>Boarding at
          <input type="text" name="pv_from" list="pvPoints" value="<?= Security::e($pv['from']) ?>" placeholder="blank = route start"></label>
        <label>Getting off at
          <input type="text" name="pv_to" list="pvPoints" value="<?= Security::e($pv['to']) ?>" placeholder="blank = route end"></label>
      </div>
      <datalist id="pvPoints"><?php foreach (array_merge((array) ($points['india'] ?? []), (array) ($points['nepal'] ?? [])) as $pt): ?><option value="<?= Security::e((string) $pt) ?>"></option><?php endforeach; ?></datalist>
      <?php if ($pvOut !== null): $q = $pvOut['q']; ?>
        <div class="pr-eg" id="pvResult">
          <b><?= Security::e($pvOut['ends']['from']) ?> → <?= Security::e($pvOut['ends']['to']) ?></b>
          · <?= Security::e(formatDate($pv['date'])) ?> · departs <?= Security::e(substr($pvOut['dep'], 0, 5)) ?>
          · <?= (int) $pv['seats'] ?> passenger<?= $pv['seats'] === 1 ? '' : 's' ?> · <?= $pvOut['mode'] === 'private' ? 'VIP private' : 'sharing' ?><br>
          Original fare <b><?= Security::e(inr((float) $q['originalFare'])) ?></b>
          <small>(<?= (int) $pv['seats'] ?> × <?= Security::e(inr((float) $pvOut['per'])) ?> · <?= Security::e($pvOut['label']) ?>)</small><br>
          Discount <b><?= Security::e(rtrim(rtrim(number_format((float) $q['discountPercent'], 2, '.', ''), '0'), '.')) ?>%</b>
          · Discount amount <b>− <?= Security::e(inr((float) $q['discountAmount'])) ?></b><br>
          Final fare <b style="font-size:18px"><?= Security::e(inr((float) $q['finalFare'])) ?></b>
          <?php if ((float) $q['advanceDiscount'] > 0): ?>
            <br><span class="pr-live on"><?= Security::e((string) $q['advanceTitle']) ?> − <?= Security::e(inr((float) $q['advanceDiscount'])) ?></span>
            booked <?= Security::e(number_format((float) $q['advanceHoursLeft'], 0)) ?> h before departure (needs <?= (int) $q['advanceHoursNeeded'] ?>)
          <?php else: ?>
            <br><span class="pr-hint">No advance offer on this quote: <?= Security::e((string) $q['advanceWhy']) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($pv['route'] > 0): ?>
        <div class="pr-eg pr-bad">Pick a bus and a valid date.</div>
      <?php endif; ?>
    </div>
    <div class="pr-actions"><button class="btn primary" type="submit">Work it out</button>
      <span class="pr-hint">Reads the same engine as the website, the counter and WhatsApp — nothing is saved.</span></div>
  </form>

  <!-- ============ 2. BASE + VIP ============ -->
  <form method="post" class="pr-card">
    <?= Security::csrfField() ?>
    <input type="hidden" name="section" value="base">
    <h2>💰 Fallback fares &amp; VIP private cabin <small>used when no rule above covers a journey</small></h2>
    <div class="pr-body">
      <div class="pr-grid">
        <label>Gujarat → Rupaidiha (₹ / person)
          <input type="number" min="1" max="1000000" step="1" name="fare_to_nepal" value="<?= (int) round($dir['toNepal']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>the “jane” fallback</small></label>
        <label>Rupaidiha → Gujarat (₹ / person)
          <input type="number" min="1" max="1000000" step="1" name="fare_to_india" value="<?= (int) round($dir['toIndia']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>the “aune” fallback</small></label>
        <label>⭐ VIP private — single cabin (₹)
          <input type="number" min="1" max="1000000" step="1" name="vip_single" value="<?= (int) round($vipS) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>whole cabin, 1 traveller · online = counter</small></label>
        <label>⭐ VIP private — double cabin (₹)
          <input type="number" min="1" max="1000000" step="1" name="vip_double" value="<?= (int) round($vipD) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>whole cabin, 2 travellers</small></label>
      </div>
    </div>
    <?php if ($canEdit): ?>
      <div class="pr-actions"><button class="btn primary" type="submit">Save base fares</button></div>
    <?php endif; ?>
  </form>

  <!-- ============ 3. ADVANCE OFFER ============ -->
  <form method="post" class="pr-card">
    <?= Security::csrfField() ?>
    <input type="hidden" name="section" value="offer">
    <h2>🎉 Advance booking offer
      <span class="pr-live <?= $offer['live'] ? 'on' : 'off' ?>"><?= $offer['live'] ? 'RUNNING TODAY' : ($offer['on'] ? 'ON, outside its dates' : 'OFF') ?></span>
      <small>book early, pay less — sharing and VIP private alike</small></h2>
    <div class="pr-body">
      <div class="pr-grid">
        <label style="flex-direction:row;align-items:center;gap:10px">
          <input type="checkbox" name="advance_offer_on" value="1" <?= $offer['on'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> style="width:22px;height:22px">
          <span>Offer switched ON</span>
        </label>
        <label>Book at least … hours before departure
          <input type="number" min="0" max="8760" step="1" name="advance_offer_hours" value="<?= (int) $offer['hours'] ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>24 today. Change it to 48 or 12 whenever you like.</small></label>
        <label>Discount (%)
          <input type="number" min="0" max="100" step="0.5" name="advance_offer_percent" value="<?= Security::e(rtrim(rtrim(number_format($offer['percent'], 2, '.', ''), '0'), '.')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>0 turns the discount off without losing the settings.</small></label>
        <label>Largest discount (₹)
          <input type="number" min="0" max="1000000" step="1" name="advance_offer_max_inr" value="<?= (int) round($offer['max']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>0 = no ceiling</small></label>
        <label>Runs from
          <input type="date" name="advance_offer_from" value="<?= Security::e($offer['from']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>blank = already running</small></label>
        <label>Runs until
          <input type="date" name="advance_offer_to" value="<?= Security::e($offer['to']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>blank = no end</small></label>
        <label>Applies to
          <select name="advance_offer_modes" <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach (['all' => 'Every booking (sharing + VIP private)', 'sharing' => 'Sharing sleeper only', 'private' => 'VIP private only'] as $k => $v): ?>
              <option value="<?= $k ?>" <?= $offer['modes'] === $k ? 'selected' : '' ?>><?= Security::e($v) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label>One route only
          <select name="advance_offer_route" <?= $canEdit ? '' : 'disabled' ?>>
            <option value="0">Every route</option>
            <?php foreach ($routesAll as $rt): ?>
              <option value="<?= (int) $rt['id'] ?>" <?= (int) ($offer['routeId'] ?? 0) === (int) $rt['id'] ? 'selected' : '' ?>><?= Security::e($rt['from_city'] . ' → ' . $rt['to_city'] . ' (' . $rt['route_code'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
          <small>Every route = company-wide, as before</small></label>
        <label>One departure date only
          <input type="date" name="advance_offer_date" value="<?= Security::e((string) ($offer['date'] ?? '')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>only tickets FOR this travel date get it; blank = any date</small></label>
        <label>Largest discount counts
          <select name="advance_offer_max_per" <?= $canEdit ? '' : 'disabled' ?>>
            <option value="booking" <?= ($offer['maxPer'] ?? 'booking') === 'booking' ? 'selected' : '' ?>>per booking</option>
            <option value="passenger" <?= ($offer['maxPer'] ?? 'booking') === 'passenger' ? 'selected' : '' ?>>per passenger</option>
          </select>
          <small>per passenger: a family of four keeps 4 × the ceiling</small></label>
        <label>Offer name
          <input type="text" name="advance_offer_title" maxlength="120" value="<?= Security::e($offer['title']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>printed on the fare breakdown and the ticket</small></label>
      </div>
      <div class="pr-grid" style="margin-top:14px">
        <label style="grid-column:1/-1">Line shown on the home page
          <input type="text" name="advance_offer_text" maxlength="240" value="<?= Security::e($offer['text']) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <small>This is what a passenger reads on the offer card.</small></label>
      </div>
      <div class="pr-eg">
        <b>Worked example.</b>
        A <?= Security::e(inr($sample)) ?> seat booked <?= (int) $offer['hours'] ?> hours or more before departure:
        <?= Security::e(inr($sample)) ?> − <?= Security::e(inr($sampleCut)) ?>
        = <b><?= Security::e(inr(max(0, $sample - $sampleCut))) ?></b>.
        Booked later than that, the passenger pays <?= Security::e(inr($sample)) ?>.
        <?php if (!$offer['live']): ?><br><span class="pr-bad">The offer is not running today, so nobody is getting this yet.</span><?php endif; ?>
      </div>
    </div>
    <?php if ($canEdit): ?>
      <div class="pr-actions"><button class="btn primary" type="submit">Save the offer</button>
        <span class="pr-hint">Every channel reads this at once — website, counter, agent panel, Quick Ticket, chatbot and WhatsApp.</span></div>
    <?php endif; ?>
  </form>

  <!-- ============ 4. WHATSAPP RULE CONTROL ============ -->
  <form method="post" class="pr-card">
    <?= Security::csrfField() ?>
    <input type="hidden" name="section" value="wa">
    <h2>💬 Change these by WhatsApp
      <span class="pr-live <?= $waOn ? 'on' : 'off' ?>"><?= $waOn ? 'ON' : 'OFF' ?></span>
      <small>only the numbers listed here, and only after they confirm</small></h2>
    <div class="pr-body">
      <p class="pr-hint">
        With this on, an authorised admin can write “<i>Ahmedabad to Rupaidiha fare 2100 gara</i>”
        or “<i>advance offer 15% gara</i>” to the company WhatsApp. The assistant only
        <b>reads the sentence</b>; the change itself is checked and written by the server,
        it is shown back for a yes before anything is saved, and every save lands in the
        audit log with who, what, from and to.
        A number that is not on this list is refused even if it belongs to a manager.
      </p>
      <div class="pr-grid">
        <label style="flex-direction:row;align-items:center;gap:10px">
          <input type="checkbox" name="wa_rules_control" value="1" <?= $waOn ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> style="width:22px;height:22px">
          <span>Allow fare / offer changes by WhatsApp</span>
        </label>
        <label style="grid-column:1/-1">Authorised numbers
          <input type="text" name="wa_rules_numbers" value="<?= Security::e(implode(', ', $waNums)) ?>" placeholder="9726401507, 9104801507" <?= $canEdit ? '' : 'readonly' ?>>
          <small>Comma separated. These must also be manager / super-admin staff accounts.</small></label>
      </div>
      <?php if ($waNums !== []): ?>
        <table class="pr-eff" style="margin-top:14px">
          <thead><tr><th>Number</th><th>Staff account</th><th>Can it actually command?</th></tr></thead>
          <tbody>
          <?php foreach ($waCheck as $num => $row): ?>
            <?php
            $role = (string) ($row['role'] ?? '');
            $ok   = $row !== null && (int) ($row['is_active'] ?? 0) === 1
                    && in_array($role, ['manager', 'superadmin'], true);
            ?>
            <tr>
              <td><?= Security::e($num) ?></td>
              <td><?= $row === null ? '<span class="pr-bad">no staff account</span>' : Security::e((string) $row['full_name']) . ' · ' . Security::e($role) ?></td>
              <td><?= $ok ? '✅ yes' : '<span class="pr-bad">no — the assistant will refuse it</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
      <div class="pr-actions"><button class="btn primary" type="submit">Save WhatsApp control</button></div>
    <?php endif; ?>
  </form>

</div>

<?php if ($canEdit): ?>
<script>
/* One new blank rule row. Indices only have to be unique — the server
   re-numbers the list when it saves. */
(function () {
  var btn = document.getElementById('prAdd');
  var tb  = document.getElementById('prRules');
  if (!btn || !tb) { return; }
  btn.addEventListener('click', function () {
    var i = Date.now() % 100000;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>new</td>' +
      '<td><input type="text" name="rule[' + i + '][from]" placeholder="Surat or @india or *"></td>' +
      '<td><input type="text" name="rule[' + i + '][to]" placeholder="Rupaidiha or @nepal"></td>' +
      '<td class="num"><input type="number" min="1" max="1000000" step="1" name="rule[' + i + '][amount]"></td>' +
      '<td><input type="text" name="rule[' + i + '][note]" placeholder="why this rule exists"></td>' +
      '<td><input type="checkbox" name="rule[' + i + '][drop]" value="1"></td>';
    tb.appendChild(tr);
  });
})();
</script>
<?php endif; ?>

<?php admin_footer(); ?>
