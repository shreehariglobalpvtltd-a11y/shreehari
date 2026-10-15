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
require_once ROOT_PATH . '/whatsapp/config.php';

$admin   = admin_boot('reports.view');
$canEdit = Auth::canManageSettings();
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
        if ($act === 'publish') {
            $r = WaFlow::publish();
            Logger::audit('wa.flow_publish', 'settings', 'whatsapp', null, $r, 'WhatsApp booking form published by admin #' . (int) $admin['id']);
            $flash = $r['ok']
                ? ['ok', 'Form published on Meta (id ' . $r['id'] . '). "ticket" on WhatsApp now opens it. / फारम चालु भयो।']
                : ['bad', 'Meta said: ' . $r['error'] . ($r['errors'] !== [] ? ' — ' . implode(' · ', $r['errors']) : '')];
        } elseif ($act === 'template') {
            $r = WaFlow::createTemplate();
            $flash = $r['ok'] ? ['ok', 'Template ' . WaFlow::TEMPLATE . ' sent to Meta for review (usually a few minutes).'] : ['bad', 'Meta said: ' . $r['error']];
        } elseif ($act === 'save') {
            $id = preg_replace('/\D/', '', (string) ($_POST['wa_flow_book_id'] ?? '')) ?? '';
            Settings::set('wa_flow_book_id', $id, 'string', 'whatsapp');
            Settings::set('wa_flow_on', !empty($_POST['wa_flow_on']) ? '1' : '0', 'bool', 'whatsapp');
            Settings::set('wa_flow_draft', !empty($_POST['wa_flow_draft']) ? '1' : '0', 'bool', 'whatsapp');
            Logger::audit('wa.flow_settings', 'settings', 'whatsapp', null, ['id' => $id, 'on' => !empty($_POST['wa_flow_on']), 'draft' => !empty($_POST['wa_flow_draft'])], 'WhatsApp form settings');
            $flash = ['ok', 'Saved. / सेभ भयो।'];
        } elseif ($act === 'test') {
            $r = WaFlow::sendTo((string) ($_POST['phone'] ?? ''), (string) ($_POST['lang'] ?? 'ne'));
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
$on      = Settings::getBool('wa_flow_on', true);
$draft   = Settings::getBool('wa_flow_draft', false);
$metaOk  = META_ACCESS_TOKEN !== '' && META_WABA_ID !== '';
$status  = $id !== '' && $metaOk ? WaFlow::status() : ['status' => '', 'error' => ''];
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
      <p>Switched: <?= $on ? '<span class="wf-st ok">ON</span>' : '<span class="wf-st no">OFF</span>' ?>
        · Template <?= $e(WaFlow::TEMPLATE) ?>: <span class="wf-st wait"><?= $e(Settings::getString('wa_flow_template_status', 'not made')) ?></span>
        · Forms filled (30 days): <b><?= $sold ?></b></p>
      <ol class="wf-steps">
        <li><b>Publish the form</b> (once). Meta checks it and returns an id — stored here automatically.</li>
        <li>Customer writes <b>ticket</b> / taps 🎫 Book on <?= $e(Settings::bookingWhatsApp()) ?> → the form opens.</li>
        <li>They pick pickup + date + seats, type name + mobile, choose Cash / UPI → <b>Book</b>.</li>
        <li>The ticket PNG + PDF link arrive in the same chat at once (UPI: the QR). Agents / counters on file get the same form — the sale is theirs and the ticket goes to the customer's mobile.</li>
      </ol>
      <?php if ($canEdit): ?>
      <div class="wf-row">
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="publish">
          <button class="btn" type="submit" <?= $metaOk ? '' : 'disabled' ?>>🚀 <?= $id === '' ? 'Publish form on Meta' : 'Publish again (new version)' ?></button></form>
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="template">
          <button class="btn ghost" type="submit" <?= $id !== '' && $metaOk ? '' : 'disabled' ?>>📨 Make the template (reach anyone)</button></form>
        <a class="btn ghost" href="?json=1">⬇ Form JSON</a>
      </div>
      <?php endif; ?>
    </div></div>

    <div class="panel"><h2>Try it</h2><div class="panel-body">
      <p class="muted text-sm" style="margin-top:0">Sends the form to one number. Inside 24 h of their last message it is free; otherwise the template is used (once Meta approves it).</p>
      <form method="post" class="wf-row"><?= Security::csrfField() ?><input type="hidden" name="action" value="test">
        <input type="tel" name="phone" placeholder="98XXXXXXXX or +977 98…" required>
        <select name="lang"><option value="ne">नेपाली</option><option value="hi">हिन्दी</option><option value="en">English</option><option value="gu">ગુજરાતી</option></select>
        <button class="btn" type="submit" <?= $canEdit && $id !== '' ? '' : 'disabled' ?>>Send form</button></form>
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
          <div class="wf-f"><small>Pickup · चढ्ने ठाउँ</small><?= $e($data['pickups'][0]['title'] ?? '—') ?> ▾ <small><?= count($data['pickups']) ?> choices</small></div>
          <div class="wf-f"><small>Travel date · यात्रा मिति</small>📅 <?= $e(formatDate($data['min_date'])) ?> … <?= $e(formatDate($data['max_date'])) ?></div>
          <div class="wf-f"><small>Seats · सिट</small>1 ▾ (up to <?= count($data['seat_opts']) ?>)</div>
          <div class="wf-f"><small>Name · नाम</small>&nbsp;</div>
          <div class="wf-f"><small>Mobile · मोबाइल</small><?= $e($data['phone_hint']) ?></div>
          <div class="wf-f"><small>Others · अरू नाम</small>&nbsp;</div>
          <div class="wf-f"><small>Payment · भुक्तानी</small><?php foreach ($data['pay_opts'] as $p): ?>◯ <?= $e($p['title']) ?><br><?php endforeach; ?></div>
          <div class="wf-btn">Book ticket · टिकट बुक</div>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php admin_footer();
