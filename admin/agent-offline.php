<?php
/**
 * admin/agent-offline.php — record a ticket sold on paper.
 *
 * An agent who cut a ticket from the paper book, away from the system,
 * enters it here so the sale exists, the commission is earned and the
 * cash is on their wallet.
 *
 * Two shapes, chosen by whether seats are named:
 *
 *   seats named  — a real confirmed booking is created (same availability
 *                  and shared-cabin checks as any counter sale), so the
 *                  seat map, the manifest and the QR ticket all agree with
 *                  the paper. This is the one to use whenever the seat is
 *                  known.
 *
 *   no seats     — a register-only entry. Nothing touches seat inventory;
 *                  the sale and its commission are still recorded. Use it
 *                  for a ticket whose seat was never fixed, or one sold on
 *                  a bus that is already running.
 *
 * The same paper ticket number can only be entered once per agent — that
 * is the commonest way a counter accidentally claims commission twice.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
// Office viewers need the commissions permission — an agent files their own
// paper tickets, but counter/support roles stay out of the commission flow.
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

if (!AgentWallet::offlineTicketsEnabled()) {
    admin_header('Paper tickets', 'agent');
    echo '<div class="flash bad">Offline ticket entry is switched off. Turn on <span class="mono">agent_offline_tickets</span> in Admin → Settings.</div>';
    admin_footer();
    exit;
}

$isSupervisor = Auth::can('dashboard.view');
$selfId       = (int) ($admin['id'] ?? 0);
$base         = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash        = null;
$lastResult   = null;

/* ---- Save ---------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // The offline outbox (see the script at the foot of the page) submits via
    // fetch with ajax=1 and expects a JSON verdict, so it can tell a real
    // rejection (bad data / duplicate — drop it) from a transient network
    // failure (re-queue and retry when back online). A no-JS browser posts the
    // form normally and still gets the HTML page below, unchanged.
    $wantsJson = ($_POST['ajax'] ?? '') === '1';

    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            // An agent may only file their own paper tickets; a supervisor
            // files on behalf of whoever actually sold it.
            $agentId = $isSupervisor ? ((int) ($_POST['agent_id'] ?? 0) ?: $selfId) : $selfId;

            $seats = array_values(array_filter(array_map(
                'trim',
                preg_split('/[\s,]+/', (string) ($_POST['seats'] ?? '')) ?: []
            ), static fn(string $s): bool => $s !== ''));

            $lastResult = AgentWallet::recordOfflineTicket([
                'agentId'     => $agentId,
                'paperNo'     => $_POST['paper_no']    ?? '',
                'name'        => $_POST['pax_name']    ?? '',
                'phone'       => $_POST['pax_phone']   ?? '',
                'gender'      => $_POST['pax_gender']  ?? null,
                'travelDate'  => $_POST['travel_date'] ?? '',
                'routeId'     => (int) ($_POST['route_id'] ?? 0),
                'seats'       => $seats,
                'seatText'    => $_POST['seat_text']   ?? '',
                'paxCount'    => (int) ($_POST['pax_count'] ?? 1),
                'amount'      => $_POST['amount']      ?? 0,
                'paymentMode' => $_POST['payment_mode'] ?? 'cash',
                'note'        => $_POST['note']        ?? '',
            ], $selfId);

            $flash = ['ok', $lastResult['mode'] === 'booking'
                ? 'Recorded. A real booking was created (' . $lastResult['pnr'] . ') and '
                  . inr($lastResult['commission']) . ' commission added — the passenger now has a QR ticket too.'
                : 'Recorded in the register. ' . inr($lastResult['commission'])
                  . ' commission added. No seat was reserved, so the seat map is unchanged.'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }

    if ($wantsJson) {
        $ok  = $flash !== null && $flash[0] === 'ok';
        $msg = (string) ($flash[1] ?? '');
        // A duplicate paper-ticket number means this entry already reached the
        // server on an earlier attempt — the client treats it as synced and
        // drops it, so a replay can never double-book or double-pay.
        $dup = stripos($msg, 'already been entered') !== false;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok'         => $ok,
            'duplicate'  => $dup,
            'message'    => $msg,
            'pnr'        => (string) ($lastResult['pnr'] ?? ''),
            'mode'       => (string) ($lastResult['mode'] ?? ''),
            'commission' => (float) ($lastResult['commission'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, coach_type, base_fare
       FROM routes WHERE is_active = 1 ORDER BY sort_order, id"
);
$agentList = $isSupervisor
    ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name, username")
    : [];

$recent = AgentWallet::offlineTickets($isSupervisor ? 0 : $selfId, 25);
$csrf   = Security::e(Security::csrfToken());
$k      = CSRF_TOKEN_NAME;
// Commission note must describe what is ACTUALLY paid. The company pays a
// flat ₹ per passenger by default (agent_commission_mode = flat_per_seat);
// the old "Commission at X%" line showed a percent that was never credited.
$flatMode = AgentWallet::flatMode();
$pct      = AgentWallet::commissionPercentFor($selfId);
$flatDirect = Settings::getFloat('agent_flat_direct', 200.0);
$flatJoint  = Settings::getFloat('agent_flat_joint', 400.0);
$commissionHint = $flatMode
    ? 'Commission of ' . inr($flatDirect) . '/passenger (direct) or ' . inr($flatJoint)
        . '/passenger (team) is added to the seller\'s wallet.'
    : 'Commission at ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% is added to the seller\'s wallet.';
$inp    = 'width:100%;margin-top:4px;padding:10px 12px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px';

admin_header('Paper ticket entry', 'agent');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
    if ($lastResult !== null && ($lastResult['pnr'] ?? '') !== '') {
        echo '<p><a class="btn" href="' . $base . '/admin/booking-view.php?pnr=' . urlencode((string) $lastResult['pnr'])
           . '">Open ' . Security::e((string) $lastResult['pnr']) . ' →</a></p>';
    }
}
?>
<div class="panel">
  <h2>📝 Enter a ticket you sold on paper</h2>
  <div id="offlineBar" class="off-bar" hidden></div>
  <div id="offlineFlash" class="flash" style="margin:0 18px 6px;display:none"></div>
  <form method="post" id="offlineForm" style="padding:16px 18px">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px">
      <?php if ($isSupervisor && $agentList !== []): ?>
      <label style="font-size:12px;color:var(--mut)">Sold by
        <select name="agent_id" style="<?= $inp ?>">
          <?php foreach ($agentList as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $selfId ? 'selected' : '' ?>>
              <?= Security::e($a['full_name'] ?: $a['username']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>

      <label style="font-size:12px;color:var(--mut)">Paper ticket no <span style="color:#b02a2a">*</span>
        <input type="text" name="paper_no" required maxlength="60" placeholder="e.g. A-004512" style="<?= $inp ?>;text-transform:uppercase"></label>

      <label style="font-size:12px;color:var(--mut)">Passenger name <span style="color:#b02a2a">*</span>
        <input type="text" name="pax_name" required maxlength="120" style="<?= $inp ?>"></label>

      <label style="font-size:12px;color:var(--mut)">Mobile
        <input type="text" name="pax_phone" maxlength="20" inputmode="numeric" style="<?= $inp ?>"></label>

      <label style="font-size:12px;color:var(--mut)">Gender
        <select name="pax_gender" style="<?= $inp ?>">
          <option value="">—</option><option>Male</option><option>Female</option><option>Other</option>
        </select></label>

      <label style="font-size:12px;color:var(--mut)">Journey date <span style="color:#b02a2a">*</span>
        <input type="date" name="travel_date" required value="<?= Security::e(todayISO()) ?>" style="<?= $inp ?>"></label>

      <label style="font-size:12px;color:var(--mut)">Route
        <select name="route_id" style="<?= $inp ?>">
          <option value="0">— not recorded —</option>
          <?php foreach ($routes as $r): ?>
            <option value="<?= (int) $r['id'] ?>"><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?> (<?= Security::e((string) $r['route_code']) ?>)</option>
          <?php endforeach; ?>
        </select></label>

      <label style="font-size:12px;color:var(--mut)">Fare collected (₹) <span style="color:#b02a2a">*</span>
        <input type="number" name="amount" required step="0.01" min="1" style="<?= $inp ?>"></label>

      <label style="font-size:12px;color:var(--mut)">Paid by
        <select name="payment_mode" style="<?= $inp ?>">
          <option value="cash">Cash</option><option value="upi">UPI</option><option value="other">Other</option>
        </select></label>

      <label style="font-size:12px;color:var(--mut)">Passengers
        <input type="number" name="pax_count" min="1" max="10" value="1" style="<?= $inp ?>"></label>
    </div>

    <div style="margin-top:18px;padding:14px 16px;border:1px dashed var(--line);border-radius:11px;background:var(--head)">
      <strong style="font-size:13px">💺 Seat numbers <span class="muted" style="font-weight:400">— optional, but use them when you know them</span></strong>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:10px">
        <label style="font-size:12px;color:var(--mut)">Seats to reserve <span class="muted">(e.g. <span class="mono">3A, 3B</span>)</span>
          <input type="text" name="seats" maxlength="120" placeholder="leave blank for register-only" style="<?= $inp ?>;text-transform:uppercase"></label>
        <label style="font-size:12px;color:var(--mut)">Or just note the seat
          <input type="text" name="seat_text" maxlength="120" placeholder="e.g. back row" style="<?= $inp ?>"></label>
      </div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">
        <strong>With seat numbers</strong> a real booking is created — the seat is taken off the map, the manifest shows the
        passenger and a QR ticket is issued. If that seat has already been sold the entry is refused, which is how you find
        a double-sell before the passenger does.<br>
        <strong>Without them</strong> only the sale and its commission are recorded; the seat map is untouched.
      </p>
    </div>

    <div style="display:grid;grid-template-columns:1fr;gap:14px;margin-top:14px">
      <label style="font-size:12px;color:var(--mut)">Note
        <input type="text" name="note" maxlength="255" placeholder="anything the office should know" style="<?= $inp ?>"></label>
    </div>

    <div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <button class="btn ok" type="submit" style="padding:11px 20px">Record this ticket</button>
      <span class="muted" style="font-size:12.5px">
        <?= Security::e($commissionHint) ?>
        A cash sale also goes onto the cash-in-hand.
      </span>
    </div>
  </form>
</div>

<div class="panel">
  <h2><?= $isSupervisor ? 'Paper tickets — all agents' : 'My paper tickets' ?></h2>
  <table>
    <thead><tr>
      <th>Paper no</th><?= $isSupervisor ? '<th>Agent</th>' : '' ?><th>Passenger</th>
      <th>Journey</th><th>Seat</th><th>Amount</th><th>Commission</th><th>Booking</th>
    </tr></thead>
    <tbody>
    <?php if ($recent === []): ?>
      <tr><td colspan="<?= $isSupervisor ? 8 : 7 ?>" class="muted" style="padding:24px;text-align:center">
        Nothing entered yet.</td></tr>
    <?php else: foreach ($recent as $p): ?>
      <tr>
        <td class="mono"><?= Security::e((string) $p['paper_ticket_no']) ?>
          <div class="muted" style="font-size:11px"><?= Security::e(timeAgo((string) $p['created_at'])) ?></div></td>
        <?php if ($isSupervisor): ?><td><?= Security::e((string) ($p['agent_name'] ?? '—')) ?></td><?php endif; ?>
        <td><?= Security::e((string) $p['passenger_name']) ?>
          <div class="muted mono" style="font-size:11px"><?= Security::e(maskPhone((string) ($p['passenger_phone'] ?? ''))) ?></div></td>
        <td><?= Security::e(($p['from_city'] ?? '—') . ' → ' . ($p['to_city'] ?? '—')) ?>
          <div class="muted"><?= Security::e(formatDate((string) $p['travel_date'])) ?></div></td>
        <td class="mono"><?= Security::e((string) ($p['seat_text'] ?: '—')) ?></td>
        <td><?= Security::e(inr((float) $p['amount'])) ?>
          <div class="muted" style="font-size:11px"><?= Security::e(strtoupper((string) $p['payment_mode'])) ?></div></td>
        <td><?= Security::e(inr((float) $p['commission'])) ?></td>
        <td class="mono"><?php if (!empty($p['pnr'])): ?>
            <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>"><?= Security::e((string) $p['pnr']) ?></a>
          <?php else: ?><span class="muted">register only</span><?php endif; ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<style>
  .off-bar{margin:0 18px 10px;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .off-bar.warn{background:#fff4d1;color:#8a6d00;border:1px solid #e6d18a}
  .off-bar.ok{background:#d7f4e3;color:#0a6b3b;border:1px solid #b8dfc4}
  .off-bar.bad{background:#f7dcdc;color:#8a1f1f;border:1px solid #f0c2c2}
  .off-bar button{background:transparent;border:1px solid currentColor;color:inherit;border-radius:7px;padding:4px 10px;font-size:12px;font-weight:700;cursor:pointer}
  :root[data-theme="dark"] .off-bar.warn{background:#3d3200;color:#e6d18a;border-color:#66551a}
  :root[data-theme="dark"] .off-bar.ok{background:#0e3d24;color:#a5d9b9;border-color:#1e6a3d}
  :root[data-theme="dark"] .off-bar.bad{background:#3d1414;color:#f0b6b6;border-color:#7a2626}
</style>

<script>
/* =====================================================================
 *  Offline outbox for paper-ticket entry (requirement 5).
 *
 *  When the counter has no internet, a submit is saved into IndexedDB and
 *  replayed automatically the moment the connection returns (and on every
 *  page load). The server dedupes on (agent + paper ticket number), so a
 *  replay of an already-synced entry is rejected as a duplicate and simply
 *  dropped from the queue — a double-book or double-pay is impossible.
 *
 *  Progressive enhancement: with JavaScript off the form posts normally
 *  and works exactly as before (online only).
 * ===================================================================== */
(function () {
  var form  = document.getElementById('offlineForm');
  var bar   = document.getElementById('offlineBar');
  var flash = document.getElementById('offlineFlash');
  if (!form || !window.indexedDB) return;   // no IDB → leave the plain POST alone

  var CSRF_NAME = <?= json_encode(CSRF_TOKEN_NAME) ?>;
  var CSRF_TOK  = <?= json_encode(Security::csrfToken()) ?>;
  var ENDPOINT  = window.location.pathname;   // same page

  /* ---- tiny IndexedDB wrapper ------------------------------------ */
  var DB = null;
  function db(cb) {
    if (DB) { cb(DB); return; }
    var rq = indexedDB.open('shg_offline_v1', 1);
    rq.onupgradeneeded = function (e) {
      var d = e.target.result;
      if (!d.objectStoreNames.contains('paper')) {
        d.createObjectStore('paper', { keyPath: 'id', autoIncrement: true });
      }
    };
    rq.onsuccess = function (e) { DB = e.target.result; cb(DB); };
    rq.onerror   = function () { cb(null); };
  }
  function qAdd(rec, cb) {
    db(function (d) { if (!d) return cb && cb(false);
      var done = false, fin = function (v) { if (!done) { done = true; cb && cb(v); } };
      try {
        var tx = d.transaction('paper', 'readwrite');
        var rq = tx.objectStore('paper').add(rec);
        rq.onerror    = function () { fin(false); };   // e.g. QuotaExceededError
        tx.oncomplete = function () { fin(true); };
        tx.onerror    = function () { fin(false); };
        tx.onabort    = function () { fin(false); };
      } catch (e) { fin(false); }
    });
  }
  function qPut(rec, cb) {
    db(function (d) { if (!d) return cb && cb();
      var tx = d.transaction('paper', 'readwrite');
      tx.objectStore('paper').put(rec);
      tx.oncomplete = function () { cb && cb(); };
      tx.onerror    = function () { cb && cb(); };
    });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function qAll(cb) {
    db(function (d) { if (!d) return cb([]);
      var out = [], tx = d.transaction('paper', 'readonly');
      tx.objectStore('paper').openCursor().onsuccess = function (e) {
        var c = e.target.result;
        if (c) { out.push(c.value); c.continue(); } else { cb(out); }
      };
    });
  }
  function qDel(id, cb) {
    db(function (d) { if (!d) return cb && cb();
      var tx = d.transaction('paper', 'readwrite');
      tx.objectStore('paper').delete(id);
      tx.oncomplete = function () { cb && cb(); };
    });
  }

  /* ---- helpers --------------------------------------------------- */
  function fields() {
    var fd = new FormData(form), o = {};
    fd.forEach(function (v, k) { if (k !== CSRF_NAME && k !== 'ajax') o[k] = v; });
    return o;
  }
  function body(o) {
    var p = new URLSearchParams();
    Object.keys(o).forEach(function (k) { p.append(k, o[k]); });
    p.append('ajax', '1');
    p.append(CSRF_NAME, CSRF_TOK);   // always the live session token
    return p;
  }
  function send(o) {
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, 15000);
    return fetch(ENDPOINT, {
      method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body(o).toString()
    }).then(function (r) { clearTimeout(timer); if (!r.ok) throw new Error('http'); return r.json(); },
            function (e) { clearTimeout(timer); throw e; });
  }
  function showBar(cls, html) {
    bar.className = 'off-bar ' + cls; bar.innerHTML = html; bar.hidden = false;
  }
  // kind: 'ok' | 'warn' | 'bad'. The base admin .flash only styles ok/bad,
  // so warn is painted inline (amber) to match the outbox bar.
  function showFlash(kind, msg) {
    flash.textContent = msg; flash.style.display = 'block';
    if (kind === 'ok')        { flash.className = 'flash ok';  flash.style.background = ''; flash.style.color = ''; }
    else if (kind === 'warn') { flash.className = 'flash';     flash.style.background = '#fff4d1'; flash.style.color = '#8a6d00'; }
    else                      { flash.className = 'flash bad'; flash.style.background = ''; flash.style.color = ''; }
  }
  function updateBar() {
    qAll(function (items) {
      var pending = items.filter(function (it) { return !it.error; });
      var failed  = items.filter(function (it) { return it.error; });
      if (!pending.length && !failed.length) { bar.hidden = true; return; }
      var html = '';
      if (failed.length) {
        html += '<div style="width:100%"><strong>⚠️ ' + failed.length + ' offline ticket'
             + (failed.length === 1 ? '' : 's') + ' could NOT sync — enter manually:</strong>'
             + '<ul style="margin:6px 0 0;padding-left:18px;font-weight:500">';
        failed.forEach(function (it) {
          var pn = (it.fields && it.fields.paper_no) ? it.fields.paper_no : '(no number)';
          html += '<li>' + esc(pn) + ' — ' + esc(it.error)
               + ' <button type="button" data-del="' + it.id + '">Remove</button></li>';
        });
        html += '</ul></div>';
      }
      if (pending.length) {
        html += '<div style="width:100%">📴 ' + pending.length + ' ticket' + (pending.length === 1 ? '' : 's')
             + ' saved offline, waiting to sync. <button type="button" id="offSyncNow">Try now</button></div>';
      }
      showBar(failed.length ? 'bad' : 'warn', html);
      var sb = document.getElementById('offSyncNow'); if (sb) sb.onclick = flush;
      bar.querySelectorAll('button[data-del]').forEach(function (b) {
        b.onclick = function () { qDel(parseInt(b.getAttribute('data-del'), 10), updateBar); };
      });
    });
  }

  /* ---- flush queued entries ------------------------------------- */
  var flushing = false;
  function flush() {
    if (flushing) return; flushing = true;
    qAll(function (items) {
      // Only auto-send items that have not already been rejected — a failed
      // one (e.g. seat gone) will never succeed on retry, so it waits for
      // staff instead of looping silently forever.
      var pending = items.filter(function (it) { return !it.error; });
      var synced = 0;
      (function next(i) {
        if (i >= pending.length) {
          flushing = false;
          if (synced > 0) {
            showBar('ok', '✅ Synced ' + synced + ' offline ticket' + (synced === 1 ? '' : 's')
              + '. <button type="button" onclick="location.reload()">Refresh list</button>');
            setTimeout(updateBar, 4000);
          } else { updateBar(); }
          return;
        }
        var it = pending[i];
        send(it.fields).then(function (j) {
          if (j && (j.ok || j.duplicate)) { synced++; qDel(it.id, function () { next(i + 1); }); }
          else {
            // A real, non-duplicate rejection (bad data, seat taken). Record the
            // reason on the item so the counter is told, and stop retrying it.
            it.error = (j && j.message) ? j.message : 'Rejected by the server.';
            qPut(it, function () { next(i + 1); });
          }
        }, function () { flushing = false; updateBar(); });  // network gone — stop, keep the rest pending
      })(0);
    });
  }

  /* ---- submit --------------------------------------------------- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    flash.style.display = 'none';
    var data = fields();

    function queueIt() {
      qAdd({ fields: data, createdAt: Date.now() }, function (ok) {
        if (!ok) {
          // The save itself failed (storage full/blocked) — do NOT clear the
          // form or claim success, or the sale is lost with no trace.
          showFlash('bad', 'Could not save this ticket on this device (storage full or blocked). '
            + 'Please keep the paper ticket and re-enter it once you are back online.');
          return;
        }
        form.reset(); updateBar();
        showFlash('warn', 'No internet — saved on this device. It will sync automatically when you are back online.');
      });
    }

    if (!navigator.onLine) { queueIt(); return; }

    send(data).then(function (j) {
      if (j && j.ok) {
        // Persisted server-side — flush anything queued, then refresh so the
        // recent-tickets table and commission totals update.
        location.reload();
      } else {
        showFlash('bad', (j && j.message) ? j.message : 'Could not save — please check the details.');
      }
    }, function () { queueIt(); });   // network error mid-flight → keep it safe offline
  });

  window.addEventListener('online', flush);
  updateBar();
  flush();   // catch anything left from a previous offline session
})();
</script>
<?php
admin_footer();
