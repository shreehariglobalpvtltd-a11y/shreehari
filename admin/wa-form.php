<?php
/**
 * admin/wa-form.php — the WhatsApp booking FORM (15 Oct 2026).
 *
 * Owner: "WhatsApp ma clickable form / template - location, date, name ra
 * number diyo, ticket WhatsApp mai aaune, fully automatic".
 *
 * One page to: publish the form on Meta (a WhatsApp Flow, includes/waflow.php),
 * make the template that carries it to anyone outside the 24 h window, switch
 * it on / off, send it to a number to try, and see exactly what the customer
 * sees. Once published, "ticket" / the Book button on the WhatsApp number opens
 * this form; Book in the form = the sale + the ticket in the same chat.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/waflow.php';
if (is_file(ROOT_PATH . '/whatsapp/config.php')) {
    require_once ROOT_PATH . '/whatsapp/config.php';
}

$admin   = admin_boot('reports.view');
$canEdit = Auth::canManageSettings();
$isSuper = Auth::isSuperadmin();
$e       = static fn($v): string => Security::e((string) $v);

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        if (!$canEdit) {
            throw new RuntimeException('This needs the Settings permission.');
        }
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'recreate') {
            /* 19 Nov 2026 (WA-3): drop the stored form (it may belong to the old
               WhatsApp account) and make a fresh one in THIS account. Owner only. */
            if (!$isSuper) {
                throw new RuntimeException('Only the owner (superadmin) can re-create the form.');
            }
            $old = WaFlow::flowId();
            $r   = WaFlow::publish(true);
            Logger::audit('wa.flow_recreate', 'settings', 'whatsapp', null, ['old' => $old] + $r, 'WhatsApp booking form re-created by admin #' . (int) $admin['id']);
            $flash = $r['ok']
                ? ['ok', 'New form made and PUBLISHED in this WhatsApp account (id ' . $r['id'] . ')' . ($old !== '' ? '; the old form ' . $old . ' is no longer used' : '') . '. / नयाँ फारम चालु भयो।']
                : ['bad', ($r['id'] !== '' ? 'New form ' . $r['id'] . ' made in this account but Meta kept it as a DRAFT - it stays OFF; the retry job publishes it once Meta allows. ' : 'No new form was made. ')
                    . 'Meta said: ' . $r['error'] . ($r['errors'] !== [] ? ' — ' . implode(' · ', $r['errors']) : '')];
        } elseif ($act === 'publish') {
            $r = WaFlow::publish();
            Logger::audit('wa.flow_publish', 'settings', 'whatsapp', null, $r, 'WhatsApp booking form published by admin #' . (int) $admin['id']);
            $flash = $r['ok']
                ? ['ok', 'Form published on Meta (id ' . $r['id'] . ')' . ($r['replaced'] !== '' ? ' - it replaces ' . $r['replaced'] . ', which was not in this WhatsApp account' : '') . '. "ticket" on WhatsApp now opens it. / फारम चालु भयो।']
                : ['bad', ($r['replaced'] !== '' ? 'The old form ' . $r['replaced'] . ' was not in this WhatsApp account (' . $r['why'] . ') and was dropped. ' : '')
                    . ($r['id'] !== '' ? 'Meta made the form (id ' . $r['id'] . ') but kept it as a DRAFT — customers still get the chat questions. ' : '')
                    . 'Meta said: ' . $r['error'] . ($r['errors'] !== [] ? ' — ' . implode(' · ', $r['errors']) : '')
                    . (stripos($r['error'], 'integrity') !== false ? ' → Meta Business Manager: finish Business Verification / fix the phone number quality, then press Publish again.' : '')];
        } elseif ($act === 'template') {
            // Template needs a PUBLISHED flow — auto-publish the draft first so the office doesn't hit the "flow should be published" wall.
            $pub = ['ok' => true, 'error' => '', 'errors' => []];
            if (WaFlow::flowId() !== '' && Settings::getBool('wa_flow_draft', false)) {
                $pub = WaFlow::publish();
                Logger::audit('wa.flow_publish', 'settings', 'whatsapp', null, $pub, 'auto-publish before template, admin #' . (int) $admin['id']);
            }
            if (!$pub['ok']) {
                $flash = ['bad', 'Template needs a published form. Meta would not publish the draft: ' . $pub['error']
                    . ($pub['errors'] !== [] ? ' — ' . implode(' · ', $pub['errors']) : '')
                    . ' → WhatsApp Manager → Flows → open flow ' . WaFlow::flowId() . ' to see the exact validation message, fix it there, then press this button again.'];
            } else {
                $r = WaFlow::createTemplate();
                $flash = $r['ok'] ? ['ok', 'Template ' . WaFlow::TEMPLATE . ' sent to Meta for review (usually a few minutes).'] : ['bad', 'Meta said: ' . $r['error']];
            }
        } elseif ($act === 'save') {
            $id = preg_replace('/\D/', '', (string) ($_POST['wa_flow_book_id'] ?? '')) ?? '';
            if ($id !== WaFlow::flowId()) {
                Settings::set('wa_flow_waba', '', 'string', 'whatsapp');   // a pasted id is checked again before it can go on
            }
            Settings::set('wa_flow_book_id', $id, 'string', 'whatsapp');
            Settings::set('wa_flow_draft', !empty($_POST['wa_flow_draft']) ? '1' : '0', 'bool', 'whatsapp');
            $wantOn = !empty($_POST['wa_flow_on']);
            $note   = '';
            if ($wantOn) {
                /* 19 Nov 2026 (WA-3): ON only for a form Meta reports PUBLISHED in
                   THIS WhatsApp account - never a draft or another account's form. */
                $own = WaFlow::ownership();
                if ($own['state'] === 'own' && $own['status'] === 'PUBLISHED') {
                    Settings::set('wa_flow_waba', defined('META_WABA_ID') ? (string) META_WABA_ID : '', 'string', 'whatsapp');
                    Settings::set('wa_flow_draft', '0', 'bool', 'whatsapp');
                } else {
                    $wantOn = false;
                    $note   = ' The form stays OFF: Meta does not show form ' . ($id !== '' ? $id : '(none)') . ' as PUBLISHED in this WhatsApp account ('
                        . $own['state'] . ($own['status'] !== '' ? ', ' . $own['status'] : '') . ($own['error'] !== '' ? ', ' . $own['error'] : '') . ').';
                }
            }
            Settings::set('wa_flow_on', $wantOn ? '1' : '0', 'bool', 'whatsapp');
            Logger::audit('wa.flow_settings', 'settings', 'whatsapp', null, ['id' => $id, 'on' => $wantOn, 'draft' => !empty($_POST['wa_flow_draft'])], 'WhatsApp form settings');
            $flash = [$note === '' ? 'ok' : 'bad', 'Saved. / सेभ भयो।' . $note];
        } elseif ($act === 'test') {
            $cc = (string) ($_POST['country'] ?? '');
            $r = WaFlow::sendTo((string) ($_POST['phone'] ?? ''), (string) ($_POST['lang'] ?? 'ne'), false, in_array($cc, ['IN', 'NP'], true) ? $cc : '');
            $flash = $r['success'] ? ['ok', 'Sent via ' . $r['via'] . '. Open WhatsApp on that phone.'] : ['bad', 'Not sent — ' . $r['error']];
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="shg-booking-form.json"');
    echo json_encode(WaFlow::flowJson(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$id      = WaFlow::flowId();
$on      = WaFlow::enabled();
$draft   = Settings::getBool('wa_flow_draft', false);
$metaOk  = WaFlow::metaReady();
$status  = $id !== '' && $metaOk ? WaFlow::status() : ['status' => '', 'error' => '', 'validation_errors' => []];
$owner   = $id !== '' && $metaOk ? WaFlow::ownership() : ['state' => $id === '' ? 'none' : 'unknown', 'status' => '', 'error' => ''];
$dropped = Settings::getString('wa_flow_dropped', '');
$data    = WaFlow::screenData('ne', false);
$sold    = (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'wa.flow_submit' AND created_at >= CURDATE() - INTERVAL 30 DAY", [], 0);

admin_header('WhatsApp booking form', 'wa-form');
admin_page_head('The one-sheet booking form inside WhatsApp: pickup, date, seats, name, mobile → Book → the ticket arrives in the same chat. Fully automatic — no desk step.');
?>
<style>
.wf-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:16px}
@media (max-width:900px){.wf-grid{grid-template-columns:minmax(0,1fr)}}
.wf-steps{margin:0;padding-left:20px;line-height:1.7}
.wf-st{display:inline-block;padding:3px 10px;border-radius:999px;font-weight:800;font-size:12px}
.wf-st.ok{background:#e3f6ea;color:#16794a}.wf-st.no{background:#fde8ea;color:#b4232f}.wf-st.wait{background:#fff4dc;color:#8a5a00}
.wf-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0}
.wf-row input[type=text],.wf-row input[type=tel]{min-height:40px;flex:1 1 200px}
.wf-phone{max-width:340px;margin:0 auto;border-radius:28px;border:10px solid #111;background:#ece5dd;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.18)}
.wf-phone .bar{background:#075e54;color:#fff;padding:10px 14px;font-weight:700;font-size:14px}
.wf-sheet{background:#fff;margin:10px;border-radius:14px;padding:14px;color:#111}
.wf-sheet h4{margin:0 0 4px;font-size:15px}.wf-sheet p{margin:0 0 10px;font-size:12.5px;color:#555}
.wf-f{border:1px solid #ccc;border-radius:8px;padding:8px 10px;margin:0 0 8px;font-size:13px;color:#333;background:#fff}
.wf-f small{display:block;color:#777;font-size:11px}
.wf-btn{background:#00a884;color:#fff;text-align:center;border-radius:22px;padding:10px;font-weight:800;font-size:14px}
.wf-msg{background:#fff;margin:10px;border-radius:10px;padding:10px;font-size:13px;color:#111}
.wf-msg .cta{border-top:1px solid #eee;margin-top:8px;padding-top:8px;text-align:center;color:#027eb5;font-weight:700}
</style>
<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="wf-grid">
  <div>
    <div class="panel"><h2>Status</h2><div class="panel-body">
      <p>Meta number + token:
        <?= $metaOk ? '<span class="wf-st ok">ready</span>' : '<span class="wf-st no">missing — Settings → WhatsApp (token, WABA id)</span>' ?></p>
      <p>Form on Meta:
        <?php if ($id === ''): ?><span class="wf-st no">not published yet</span>
        <?php else: ?><span class="wf-st <?= in_array($status['status'], ['PUBLISHED'], true) ? 'ok' : 'wait' ?>"><?= $e($status['status'] !== '' ? $status['status'] : 'id ' . $id) ?></span>
          <small class="muted">id <?= $e($id) ?><?= $status['error'] !== '' ? ' · ' . $e($status['error']) : '' ?></small><?php endif; ?></p>
      <?php if ($owner['state'] === 'foreign'): ?>
      <div class="flash bad" style="margin:6px 0 10px"><b>This form is not in the current WhatsApp account</b> (WABA <?= $e(defined('META_WABA_ID') ? META_WABA_ID : '') ?>).
        It was made under the old account and Meta can never publish it for this number. Press <b>♻️ Re-create form</b> (owner) or <b>Publish</b> — a fresh form is made here.</div>
      <?php elseif ($owner['state'] === 'deprecated'): ?>
      <div class="flash bad" style="margin:6px 0 10px">Meta has retired this form (DEPRECATED). Publish makes a new one.</div>
      <?php endif; ?>
      <?php if ($dropped !== ''): ?><p class="muted text-sm">Last form dropped: <?= $e($dropped) ?></p><?php endif; ?>
      <p>Switched: <?= $on ? '<span class="wf-st ok">ON</span>' : '<span class="wf-st no">OFF</span>' ?>
        <small class="muted">(goes ON only when Meta shows the form PUBLISHED in this account)</small>
        · Template <?= $e(WaFlow::TEMPLATE) ?>: <span class="wf-st wait"><?= $e(Settings::getString('wa_flow_template_status', 'not made')) ?></span>
        · Forms filled (30 days): <b><?= $sold ?></b></p>
      <?php if (!empty($status['validation_errors'])): ?>
      <div class="flash bad" style="margin:6px 0 10px"><b>Meta won't publish — fix these first:</b>
        <ul style="margin:4px 0 0 18px;padding:0">
          <?php foreach ($status['validation_errors'] as $ve): ?><li><?= $e($ve) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <ol class="wf-steps">
        <li><b>Publish the form</b> (once). Meta checks it and returns an id — stored here automatically.</li>
        <li>Customer writes <b>ticket</b> / taps 🎫 Book on <?= $e(Settings::bookingWhatsApp()) ?> → the form opens.</li>
        <li>They pick pickup + date + seats, type name + mobile, choose Cash / UPI → <b>Book</b>.</li>
        <li>The ticket PNG + PDF link arrive in the same chat at once (UPI: the QR). Agents / counters on file get the same form — the sale is theirs and the ticket goes to the customer's mobile.</li>
      </ol>
      <?php if ($canEdit): ?>
      <div class="wf-row">
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="publish">
          <button class="btn" type="submit" <?= $metaOk ? '' : 'disabled' ?>>🚀 <?= $id === '' ? 'Publish form on Meta' : ($draft ? 'Publish the draft' : 'Publish again (new version)') ?></button></form>
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="template">
          <button class="btn ghost" type="submit" <?= $id !== '' && $metaOk ? '' : 'disabled' ?>>📨 Make the template (reach anyone)</button></form>
        <a class="btn ghost" href="?json=1">⬇ Form JSON</a>
      </div>
      <?php endif; ?>
      <?php if ($isSuper): ?>
      <form method="post" class="wf-row" onsubmit="return confirm('Make a NEW booking form in this WhatsApp account? The stored form (if any) stops being used.');">
        <?= Security::csrfField() ?><input type="hidden" name="action" value="recreate">
        <button class="btn ghost" type="submit" <?= $metaOk ? '' : 'disabled' ?>>♻️ Re-create form (new, in this WhatsApp account)</button>
        <small class="muted">Owner only. Use when the form belongs to an old account or Meta refuses it.</small>
      </form>
      <?php endif; ?>
    </div></div>

    <div class="panel"><h2>Try it</h2><div class="panel-body">
      <p class="muted text-sm" style="margin-top:0">Sends booking instructions to one customer who asked to book. While the form is in review, they can book by replying in chat. Sending the starter outside an open service window requires Meta template approval.</p>
      <form method="post" class="wf-row"><?= Security::csrfField() ?><input type="hidden" name="action" value="test">
        <input type="tel" name="phone" placeholder="98XXXXXXXX or +977 98…" required>
        <select name="country" aria-label="Country of a 10-digit number"><option value="IN">🇮🇳 +91</option><option value="NP">🇳🇵 +977</option></select>
        <select name="lang"><option value="ne">नेपाली</option><option value="hi">हिन्दी</option><option value="en">English</option><option value="gu">ગુજરાતી</option></select>
        <button class="btn" type="submit" <?= $canEdit && $metaOk ? '' : 'disabled' ?>>Send booking instructions</button></form>
    </div></div>

    <?php if ($canEdit): ?>
    <details class="panel"><summary style="cursor:pointer;font-weight:700;padding:14px 18px">Settings</summary><div class="panel-body">
      <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="save">
        <label class="wf-row"><input type="checkbox" name="wa_flow_on" value="1" <?= $on ? 'checked' : '' ?>> Use the form when a customer asks to book (off = the chat questions only)</label>
        <label class="wf-row"><input type="checkbox" name="wa_flow_draft" value="1" <?= $draft ? 'checked' : '' ?>> Draft mode (only while testing an unpublished form)</label>
        <label class="wf-row">Flow id <input type="text" name="wa_flow_book_id" value="<?= $e($id) ?>" placeholder="filled by Publish — or paste one made in WhatsApp Manager"></label>
        <button class="btn" type="submit">Save</button>
      </form>
    </div></details>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel"><h2>What the customer sees</h2><div class="panel-body">
      <div class="wf-phone">
        <div class="bar">S Hari Global ✓</div>
        <div class="wf-msg"><?= nl2br($e(WaFlow::ui('0', 'ne', false)['body'])) ?><div class="cta">🎫 टिकट बुक</div></div>
        <div class="wf-sheet">
          <h4>Book bus ticket</h4><p><?= $e($data['intro']) ?></p>
          <div class="wf-f"><small>Name · नाम <b>*</b></small>&nbsp;</div>
          <div class="wf-f"><small>Mobile · मोबाइल <b>*</b></small>98XXXXXXXX <small>(prefilled: the sender's own number · <?= $e($data['phone_hint']) ?>)</small></div>
          <div class="wf-f"><small>Mobile code · मोबाइल कोड</small><?php foreach ($data['country_opts'] as $c): ?><?= $c['id'] === $data['country_init'] ? '◉' : '◯' ?> <?= $e($c['title']) ?><br><?php endforeach; ?></div>
          <div class="wf-f"><small>Travel date · यात्रा मिति</small>📅 ▾ <small><?= $e(implode(' · ', array_map(static fn(array $d): string => (string) $d['title'], array_slice($data['date_opts'], 0, 3)))) ?> … (<?= count($data['date_opts']) ?> days)</small></div>
          <div class="wf-f"><small>Pickup · चढ्ने ठाउँ</small><?= $e($data['pickups'][0]['title'] ?? '—') ?> ▾ <small><?= count($data['pickups']) ?> choices</small></div>
          <div class="wf-f"><small>Seats · सिट</small>1 ▾ (up to <?= count($data['seat_opts']) ?>)</div>
          <div class="wf-f"><small>Others · अरू नाम</small>&nbsp;</div>
          <div class="wf-f"><small>Payment · भुक्तानी</small><?php foreach ($data['pay_opts'] as $p): ?><?= $p['id'] === $data['pay_init'] ? '◉' : '◯' ?> <?= $e($p['title']) ?><br><?php endforeach; ?></div>
          <p style="margin:6px 0 0;font-size:11px;color:#777">* required. Everything else starts filled in; the day is always picked by the customer.</p>
          <div class="wf-btn">Book ticket · टिकट बुक</div>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php admin_footer();
