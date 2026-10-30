<?php
/**
 * admin/wa-outbox.php — the WhatsApp outbox on the official Cloud API
 * (30 Oct 2026, includes/wasend.php).
 *
 * Owner: "AiSensy chaldaina bhane company ko number (+91 87358 81507) bata
 * official API le pathaos; office le contact save garna, manual ticket /
 * message pathauna, status herna, wa.me bata pani pathauna milos".
 *
 *   - Is AiSensy configured? Is the Cloud API configured? (yes / no only)
 *   - Contacts: save a name + number, search, message them.
 *   - Compose a message, or send a booking's ticket by PNR.
 *   - Every queued / sent message with its WhatsApp status (queued, waiting,
 *     sent, delivered, read, failed), Retry / Cancel, and a wa.me link for a
 *     person to send it by hand.
 *
 * Who: super-admin / manager - anyone with marketing.send (the same people
 * who may message any number from admin/wa-compose.php). Never an agent.
 * The official API only: nothing here drives WhatsApp Web or the app.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('marketing.send');
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/wasend.php';

if (Auth::isAgent() || Auth::isCounterAgent()) {   // office tool: an agent never messages strangers from the company number
    http_response_code(403);
    exit('Forbidden');
}

$e        = static fn($v): string => Security::e((string) $v);
$adminId  = (int) ($admin['id'] ?? 0);
$canSet   = Auth::isSuperadmin() || Auth::can('settings.manage');
$flash    = null;
$ready    = WaSend::ensure();
$compose  = [
    'to'   => Security::clean((string) ($_GET['to'] ?? ''), 20),
    'name' => Security::clean((string) ($_GET['name'] ?? ''), 120),
    'text' => '',
    'cc'   => 'IN',
];
$stateLabel = static function (string $s): string {
    return match ($s) {
        'queued'    => '⏳ queued · लाइनमा',
        'sending'   => '📤 sending',
        'waiting'   => '🕒 waiting for reply / template',
        'sent'      => '✓ sent · पठाइयो',
        'delivered' => '✓✓ delivered · पुग्यो',
        'read'      => '👀 read · पढ्यो',
        'failed'    => '❌ failed',
        'unknown'   => '❔ unknown - check',
        'cancelled' => 'cancelled',
        default     => $s,
    };
};
// A code / PIN / password is never shown or put in a wa.me link - by purpose or by what the text says.
$hiddenBody = static fn(array $r): bool => WaSend::isSecret((string) $r['purpose'], (string) ($r['body'] ?? ''))
    || WaSend::secretText((string) ($r['tpl_vars'] ?? ''));

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!Security::rateLimit('wa_outbox', 'admin:' . $adminId, 40, 60)) {
        $flash = ['bad', 'Too many actions in a minute — wait a moment.'];
    } elseif ($action === 'contact_save') {
        $r = WaSend::saveContact((string) ($_POST['name'] ?? ''), (string) ($_POST['phone'] ?? ''),
            ($_POST['cc'] ?? 'IN') === 'NP' ? 'NP' : 'IN', (string) ($_POST['note'] ?? ''), $adminId);
        if ($r['ok']) {
            Logger::audit('wa_outbox.contact_save', 'wa_contact', (string) $r['id'], null, [], 'contact saved by admin #' . $adminId);
        }
        $flash = $r['ok'] ? ['ok', 'Contact saved · सम्पर्क सुरक्षित भयो।'] : ['bad', $r['error']];
    } elseif ($action === 'contact_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $ok = WaSend::deleteContact($id);
        if ($ok) {
            Logger::audit('wa_outbox.contact_delete', 'wa_contact', (string) $id, null, [], 'contact removed by admin #' . $adminId);
        }
        $flash = $ok ? ['ok', 'Contact removed.'] : ['bad', 'Not found.'];
    } elseif ($action === 'send_text') {
        $compose = [
            'to'   => trim(Security::clean((string) ($_POST['to'] ?? ''), 24)),
            'name' => trim(Security::clean((string) ($_POST['name'] ?? ''), 120)),
            'text' => trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? ''))),
            'cc'   => ($_POST['cc'] ?? 'IN') === 'NP' ? 'NP' : 'IN',
        ];
        $to = WaSend::intl($compose['to'], $compose['cc']);
        if ($to === '') {
            $flash = ['bad', 'Not a usable India / Nepal WhatsApp number.'];
        } elseif ($compose['text'] === '' || mb_strlen($compose['text']) > 3000) {
            $flash = ['bad', 'Write the message (3000 characters at most).'];
        } else {
            $q = WaSend::queue([
                'to' => $to, 'name' => $compose['name'], 'body' => $compose['text'], 'purpose' => 'manual_message',
                'label' => 'Message', 'admin_id' => $adminId, 'source' => 'outbox',
                // a double click never sends twice; the same text again later is a new message
                'dedupe' => $to . '|outbox|' . sha1($compose['text']) . '|' . intdiv(time(), 120),
            ]);
            $res = ['state' => $q['state'], 'error' => $q['error']];
            if ($q['id'] > 0 && !$q['dupe'] && $q['state'] === 'queued' && WaSend::mayGoNow('manual_message')) {
                $res = WaSend::process($q['id']);
            }
            Logger::audit('wa_outbox.send', 'wa_outbox', (string) $q['id'], null,
                ['to' => maskPhone($to), 'state' => $res['state'], 'chars' => mb_strlen($compose['text'])], 'manual WhatsApp (Cloud API outbox) by admin #' . $adminId);
            $flash = match ($res['state']) {
                'sent'    => ['ok', 'Sent to +' . $to . ' on the official Cloud API · पठाइयो।'],
                'queued'  => ['ok', 'Queued for +' . $to . ' — the outbox sends one at a time (' . WaSend::gapSeconds() . ' s apart).'],
                'waiting' => ['bad', 'It waits for +' . $to . ' (' . ($res['error'] !== '' ? $res['error'] : 'no 24 h window and no approved template') . '). Use the wa.me link below to send it from a phone now.'],
                default   => ['bad', 'Not sent: ' . ($res['error'] !== '' ? $res['error'] : $res['state'])],
            };
            if ($res['state'] === 'sent' || $res['state'] === 'queued') {
                $compose['text'] = '';
            }
        }
    } elseif ($action === 'send_ticket') {
        $pnr = strtoupper(trim(Security::clean((string) ($_POST['pnr'] ?? ''), 40)));
        $override = trim(Security::clean((string) ($_POST['to'] ?? ''), 24));
        if ($pnr === '' || preg_match('/^[A-Z0-9-]{4,40}$/', $pnr) !== 1) {
            $flash = ['bad', 'Type the PNR (e.g. SHG-2026-00123).'];
        } else {
            $res = WaSend::ticket($pnr, ['to' => $override, 'hint' => ($_POST['cc'] ?? 'IN') === 'NP' ? 'NP' : 'IN',
                'admin_id' => $adminId, 'source' => 'outbox', 'purpose' => 'ticket_resend']);
            Logger::audit('wa_outbox.ticket', 'booking', $pnr, null,
                ['to' => maskPhone((string) $res['to']), 'state' => $res['state']], 'ticket by Cloud API outbox, admin #' . $adminId);
            $flash = match ($res['state']) {
                'sent'    => ['ok', 'Ticket ' . $pnr . ' sent to +' . $res['to'] . ' · टिकट पठाइयो।' . ($res['how'] !== '' ? ' (' . $res['how'] . ')' : '')],
                'queued'  => ['ok', 'Ticket ' . $pnr . ' queued for +' . $res['to'] . '.'],
                'waiting' => ['bad', 'The ticket waits for +' . $res['to'] . ' (' . ($res['error'] !== '' ? $res['error'] : 'no 24 h window and no ticket template') . '). Use its wa.me link below.'],
                default   => ['bad', 'Ticket not sent: ' . ($res['error'] !== '' ? $res['error'] : $res['state'])],
            };
        }
    } elseif ($action === 'retry' || $action === 'cancel') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'retry') {
            $ok = WaSend::retry($id);
            $state = $ok && WaSend::mayGoNow('manual_message') ? WaSend::process($id)['state'] : ($ok ? 'queued' : '');
            $flash = $ok ? ['ok', 'Retry: ' . $stateLabel($state)] : ['bad', 'Only a failed / unknown / waiting message can be retried (never one already delivered).'];
        } else {
            $ok = WaSend::cancel($id);
            $flash = $ok ? ['ok', 'Cancelled.'] : ['bad', 'This message cannot be cancelled any more.'];
        }
        if ($ok) {
            Logger::audit('wa_outbox.' . $action, 'wa_outbox', (string) $id, null, [], 'by admin #' . $adminId);
        }
    } elseif ($action === 'drain') {
        $d = WaSend::drain(3, 20);
        $flash = $d['locked'] ? ['bad', 'The outbox is already sending (cron) — try again in a minute.']
            : ['ok', 'Sent ' . $d['sent'] . ', failed ' . $d['failed'] . ', waiting ' . $d['waiting'] . ', still queued ' . $d['queued'] . '.'];
    } elseif ($action === 'settings' && $canSet) {
        $gap = max(1, min(60, (int) ($_POST['gap'] ?? 4)));
        $cap = max(0, min(5000, (int) ($_POST['cap'] ?? 300)));
        $tpl = trim(Security::clean((string) ($_POST['tpl'] ?? ''), 128));
        $lang = trim(Security::clean((string) ($_POST['tpl_lang'] ?? 'en'), 12)) ?: 'en';
        if ($tpl !== '' && preg_match('/^[a-z0-9_]{1,128}$/', $tpl) !== 1) {
            $flash = ['bad', 'A template name is lower-case letters, digits and _ only.'];
        } else {
            Settings::set('wa_cloud_fallback_on', !empty($_POST['on']) ? '1' : '0', 'bool', 'whatsapp', false);
            Settings::set('wa_cloud_gap_sec', (string) $gap, 'int', 'whatsapp', false);
            Settings::set('wa_cloud_daily_cap', (string) $cap, 'int', 'whatsapp', false);
            Settings::set('wa_cloud_tpl_message', $tpl, 'string', 'whatsapp', false);
            Settings::set('wa_cloud_tpl_lang', $lang, 'string', 'whatsapp', false);
            Settings::set('wa_cloud_tpl_nvars', '2', 'int', 'whatsapp', false);   // always {{1}} title · {{2}} message
            Settings::flush();
            Logger::audit('wa_outbox.settings', 'settings', 'wa_cloud', null, ['gap' => $gap, 'cap' => $cap, 'tpl' => $tpl], 'outbox settings by admin #' . $adminId);
            $flash = ['ok', 'Outbox settings saved.'];
        }
    }
}

// What WhatsApp said since the last look (delivered / read / failed from the webhook).
if ($ready) {
    WaSend::refresh(200);
}

$q       = Security::clean((string) ($_GET['q'] ?? ''), 60);
$stF     = Security::clean((string) ($_GET['st'] ?? ''), 12);
$contacts = $ready ? WaSend::contacts($q, 200) : [];
$counts  = $ready ? WaSend::counts() : [];
$rows    = [];
if ($ready) {
    $where = ['o.created_at >= NOW() - INTERVAL 14 DAY'];
    $params = [];
    if (in_array($stF, WaSend::STATES, true)) {
        $where[] = 'o.state = :st';
        $params['st'] = $stF;
    }
    $rows = Database::fetchAll(
        'SELECT o.*, a.full_name, a.username FROM wa_outbox o LEFT JOIN admins a ON a.id = o.admin_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 150',
        $params
    );
}
$ais    = WaSend::aisensyConfigured();
$cloud  = WaSend::cloudConfigured();
$fbOn   = WaSend::fallbackOn();
$gen    = WaSend::genericTemplate();
$tick   = WaSend::ticketTemplate();
$paid   = WaRoute::paidRefusal();
$botNo  = '';
try {
    require_once INCLUDE_PATH . '/sitefacts.php';
    $botNo = SiteFacts::chatDigits();
} catch (Throwable $ex) {
    $botNo = '';
}

admin_header('📤 WhatsApp outbox', 'wa-outbox');
?>
<style>
.wo-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.wo-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.wo-card b{display:block;font-size:12px;color:var(--muted);font-weight:700;margin-bottom:4px}
.wo-card span{font-size:18px;font-weight:800}
.wo-yes{color:#11703F}.wo-no{color:#A50E2D}
:root[data-theme="dark"] .wo-yes{color:#5FD39A}:root[data-theme="dark"] .wo-no{color:#FF8FA1}
.wo-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;margin-bottom:14px}
@media (max-width:860px){.wo-grid{grid-template-columns:minmax(0,1fr)}}
.wo-grid label{display:block;font-weight:700;margin:10px 0 4px}
.wo-grid textarea,.wo-grid input[type=text],.wo-grid input[type=number],.wo-grid select{width:100%;box-sizing:border-box}
.wo-how{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.wo-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800;white-space:nowrap}
.wo-queued,.wo-sending,.wo-waiting{background:#FFF4DE;color:#8A5300}.wo-sent{background:#EEF4FC;color:#003C90}
.wo-delivered,.wo-read{background:#E4F6EC;color:#11703F}.wo-failed,.wo-unknown{background:#FDE7EB;color:#A50E2D}.wo-cancelled{background:#EEF0F4;color:#5C6B85}
:root[data-theme="dark"] .wo-queued,:root[data-theme="dark"] .wo-sending,:root[data-theme="dark"] .wo-waiting{background:#3B2610;color:#FFC870}
:root[data-theme="dark"] .wo-sent{background:#152645;color:#8FBFFF}
:root[data-theme="dark"] .wo-delivered,:root[data-theme="dark"] .wo-read{background:#12321F;color:#5FD39A}
:root[data-theme="dark"] .wo-failed,:root[data-theme="dark"] .wo-unknown{background:#3D1219;color:#FF8FA1}
:root[data-theme="dark"] .wo-cancelled{background:#1B2638;color:#93A4BE}
.wo-body{max-width:380px;white-space:pre-wrap;word-break:break-word;font-size:12.5px}
.wo-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.wo-filter a{margin-right:8px;font-size:13px}
.wo-actions form{display:inline}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="flash bad">The outbox tables could not be created on this database — see the WhatsApp log.</div><?php endif; ?>

<div class="wo-cards">
  <div class="wo-card"><b>AiSensy configured</b><span class="<?= $ais ? 'wo-yes' : 'wo-no' ?>"><?= $ais ? 'Yes' : 'No' ?></span></div>
  <div class="wo-card"><b>Cloud API configured</b><span class="<?= $cloud ? 'wo-yes' : 'wo-no' ?>"><?= $cloud ? 'Yes' : 'No' ?></span></div>
  <div class="wo-card"><b>Cloud fallback</b><span class="<?= $fbOn ? 'wo-yes' : 'wo-no' ?>"><?= $fbOn ? 'On' : 'Off' ?></span></div>
  <div class="wo-card"><b>Sent today / cap</b><span><?= (int) WaSend::sentToday() ?> / <?= WaSend::dailyCap() === 0 ? 'paused' : (int) WaSend::dailyCap() ?></span></div>
  <div class="wo-card"><b>Queued · waiting</b><span><?= (int) (($counts['queued'] ?? 0) + ($counts['sending'] ?? 0)) ?> · <?= (int) ($counts['waiting'] ?? 0) ?></span></div>
  <div class="wo-card"><b>Failed (14 days)</b><span><?= (int) (($counts['failed'] ?? 0) + ($counts['unknown'] ?? 0)) ?></span></div>
</div>

<div class="wo-how">
  <b>How it goes · कसरी जान्छ</b><br>
  From the company bot number<?= $botNo !== '' ? ' <b>+' . $e($botNo) . '</b>' : '' ?> on Meta's <b>official Cloud API</b>:
  if that person wrote to us in the last 24 h the message goes free, exactly as typed (a ticket as its picture);
  otherwise only an <b>approved template</b> can reach them —
  tickets use <b><?= $tick['name'] !== '' ? $e($tick['name']) : 'none set (whatsapp_template_name)' ?></b>,
  office alerts <b><?= $gen['name'] !== '' ? $e($gen['name']) : 'none set' ?></b>;
  a login code goes only inside the 24 h window (else SMS), and other messages wait.
  With no template it <b>waits</b> until they write (ask them to send "hi"), and the 💬 wa.me link lets a person send it from a phone now.
  One message at a time, <?= WaSend::gapSeconds() ?> s apart — never a burst.
  <?php if ($paid !== ''): ?><br><b class="wo-no">Templates are held: <?= $e($paid) ?></b><?php endif; ?>
  <?php if ($ais): ?><br><span class="muted">AiSensy is configured, so it is tried first; this outbox carries what AiSensy cannot send.</span><?php endif; ?>
  <?php if (!$cloud): ?><br><b class="wo-no">The Cloud API token / phone number id is not set — nothing can go automatically; use the wa.me links.</b><?php endif; ?>
</div>

<div class="wo-grid">
  <div class="panel">
    <h2>✉️ Message · सन्देश</h2>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?><input type="hidden" name="action" value="send_text">
      <label for="woTo">Number · नम्बर</label>
      <input type="text" id="woTo" name="to" inputmode="tel" maxlength="24" required value="<?= $e($compose['to']) ?>" placeholder="9812345678 or +919876543210" list="woContacts">
      <div style="margin-top:6px;font-size:13px">10 digits are
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="IN" <?= $compose['cc'] === 'IN' ? 'checked' : '' ?>> 🇮🇳 +91</label>
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="NP" <?= $compose['cc'] === 'NP' ? 'checked' : '' ?>> 🇳🇵 +977</label></div>
      <label for="woName">Name <small class="muted">(optional)</small></label>
      <input type="text" id="woName" name="name" maxlength="120" value="<?= $e($compose['name']) ?>">
      <label for="woText">Message</label>
      <textarea id="woText" name="text" rows="6" maxlength="3000" required><?= $e($compose['text']) ?></textarea>
      <button class="btn" type="submit" style="margin-top:8px">📤 Send · पठाउनुहोस्</button>
    </form>
    <datalist id="woContacts">
      <?php foreach ($contacts as $c): ?><option value="<?= $e($c['phone']) ?>"><?= $e($c['name']) ?></option><?php endforeach; ?>
    </datalist>
  </div>
  <div class="panel">
    <h2>🎫 Send a ticket · टिकट पठाउनुहोस्</h2>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?><input type="hidden" name="action" value="send_ticket">
      <label for="woPnr">PNR</label>
      <input type="text" id="woPnr" name="pnr" maxlength="40" required placeholder="SHG-2026-00123" autocapitalize="characters">
      <label for="woTTo">To another number <small class="muted">(optional — blank = the booking's own number)</small></label>
      <input type="text" id="woTTo" name="to" inputmode="tel" maxlength="24" list="woContacts">
      <div style="margin-top:6px;font-size:13px">10 digits are
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="IN" checked> 🇮🇳 +91</label>
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="NP"> 🇳🇵 +977</label></div>
      <button class="btn" type="submit" style="margin-top:8px">🎫 Send ticket</button>
      <p class="muted" style="font-size:12px;margin-top:8px">Only a confirmed ticket. Inside the 24 h window the ticket picture + PDF link go free; outside it the approved ticket template.</p>
    </form>
  </div>
</div>

<div class="wo-grid">
  <div class="panel">
    <h2>👥 Contacts · सम्पर्क</h2>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?><input type="hidden" name="action" value="contact_save">
      <label for="woCName">Name</label>
      <input type="text" id="woCName" name="name" maxlength="120" required>
      <label for="woCPhone">Number</label>
      <input type="text" id="woCPhone" name="phone" inputmode="tel" maxlength="24" required>
      <div style="margin-top:6px;font-size:13px">10 digits are
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="IN" checked> 🇮🇳 +91</label>
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="NP"> 🇳🇵 +977</label></div>
      <label for="woCNote">Note <small class="muted">(optional)</small></label>
      <input type="text" id="woCNote" name="note" maxlength="255">
      <button class="btn" type="submit" style="margin-top:8px">💾 Save contact</button>
    </form>
    <form method="get" style="margin-top:14px;display:flex;gap:6px">
      <input type="text" name="q" value="<?= $e($q) ?>" placeholder="Search name / number" style="flex:1">
      <button class="btn ghost sm" type="submit">Search</button>
    </form>
    <div class="wo-scroll">
    <table style="margin-top:8px">
      <thead><tr><th>Name</th><th>Number</th><th></th></tr></thead>
      <tbody>
      <?php if ($contacts === []): ?><tr><td colspan="3" class="muted">No contacts<?= $q !== '' ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
      <?php foreach ($contacts as $c): ?>
        <tr>
          <td><?= $e($c['name']) ?><?php if ((string) ($c['note'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e($c['note']) ?></div><?php endif; ?></td>
          <td class="mono">+<?= $e($c['phone']) ?><?= WaSend::windowOpen((string) $c['phone']) ? ' <span class="wo-pill wo-delivered" title="wrote to us in the last 24 h">24 h open</span>' : '' ?></td>
          <td class="wo-actions" style="white-space:nowrap">
            <a class="btn ghost sm" href="?to=<?= rawurlencode((string) $c['phone']) ?>&amp;name=<?= rawurlencode((string) $c['name']) ?>#woTo">✉️</a>
            <a class="btn ghost sm" href="<?= $e(WaSend::waMe((string) $c['phone'])) ?>" target="_blank" rel="noopener" title="Open in WhatsApp">💬</a>
            <form method="post" data-no-guard onsubmit="return confirm('Remove this contact?')"><?= Security::csrfField() ?>
              <input type="hidden" name="action" value="contact_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="btn ghost sm" type="submit" title="Remove">🗑</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <div class="panel">
    <h2>⚙️ Sending rules</h2>
    <?php if ($canSet): ?>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?><input type="hidden" name="action" value="settings">
      <label style="font-weight:600"><input type="checkbox" name="on" value="1" <?= $fbOn ? 'checked' : '' ?>> Use the Cloud API when AiSensy cannot send</label>
      <label for="woGap">Seconds between two sends (1–60)</label>
      <input type="number" id="woGap" name="gap" min="1" max="60" value="<?= WaSend::gapSeconds() ?>">
      <label for="woCap">Most sends a day (0 = paused)</label>
      <input type="number" id="woCap" name="cap" min="0" max="5000" value="<?= WaSend::dailyCap() ?>">
      <label for="woTpl">Approved office-alert template outside 24 h <small class="muted">(blank = they wait)</small></label>
      <input type="text" id="woTpl" name="tpl" maxlength="128" value="<?= $e($gen['name']) ?>" placeholder="shg_office_alert">
      <p class="muted" style="font-size:12px;margin:4px 0 0">Variables: {{1}} title · {{2}} message. Office alerts only (notes, reports, payment / booking alerts) — never login codes, passwords, bot / support replies or typed messages; those wait for the person's reply.</p>
      <label for="woLang">Template language</label>
      <input type="text" id="woLang" name="tpl_lang" maxlength="12" value="<?= $e($gen['lang']) ?>">
      <button class="btn" type="submit" style="margin-top:8px">Save rules</button>
    </form>
    <?php else: ?>
      <p class="muted">Seconds between sends: <?= WaSend::gapSeconds() ?> · daily cap: <?= WaSend::dailyCap() ?> · template: <?= $e($gen['name'] !== '' ? $gen['name'] : 'none') ?>. A manager / super-admin changes these.</p>
    <?php endif; ?>
    <form method="post" data-no-guard style="margin-top:14px"><?= Security::csrfField() ?><input type="hidden" name="action" value="drain">
      <button class="btn ghost" type="submit" <?= $cloud ? '' : 'disabled' ?>>▶️ Send queued now (up to 3)</button></form>
  </div>
</div>

<div class="panel">
  <h2>📋 Outbox · last 14 days</h2>
  <div class="wo-filter">
    <a href="?">All</a>
    <?php foreach (['queued', 'waiting', 'sent', 'delivered', 'read', 'failed', 'unknown', 'cancelled'] as $s): ?>
      <a href="?st=<?= $e($s) ?>"<?= $stF === $s ? ' style="font-weight:800"' : '' ?>><?= $e($s) ?> (<?= (int) ($counts[$s] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>
  <div class="wo-scroll">
  <table style="margin-top:8px">
    <thead><tr><th>When</th><th>To</th><th>Message</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="5" class="muted">Nothing in the outbox<?= $stF !== '' ? ' with that status' : '' ?>.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $st = (string) $r['state'];
        $hide = $hiddenBody($r);
        $body = $hide ? '[hidden: carries a one-time code]' : (string) ($r['body'] ?? ('Template ' . (string) $r['tpl_name']));
        $link = $hide ? '' : WaSend::waMe((string) $r['to_number'], trim((string) ($r['body'] ?? '') . (((string) ($r['media_url'] ?? '') !== '' && !str_contains((string) ($r['body'] ?? ''), (string) $r['media_url'])) ? "\n" . $r['media_url'] : '')));
    ?>
      <tr>
        <td style="white-space:nowrap"><?= $e(date('j M · g:i A', (int) strtotime((string) $r['created_at']))) ?>
          <div class="muted" style="font-size:11.5px"><?= $e($r['full_name'] ?: ($r['username'] ?: $r['source'])) ?></div></td>
        <td class="mono">+<?= $e($r['to_number']) ?><?php if ((string) ($r['to_name'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e($r['to_name']) ?></div><?php endif; ?></td>
        <td><div class="muted" style="font-size:11.5px"><?= $e($r['purpose']) ?><?= (string) ($r['pnr'] ?? '') !== '' ? ' · ' . $e($r['pnr']) : '' ?><?= (string) ($r['route'] ?? '') !== '' ? ' · ' . $e($r['route'] === 'cloud_free' ? 'free (24 h)' : 'template') : '' ?></div>
          <div class="wo-body"><?= $e(mb_substr($body, 0, 220)) ?></div></td>
        <td><span class="wo-pill wo-<?= $e(in_array($st, WaSend::STATES, true) ? $st : 'cancelled') ?>"><?= $e($stateLabel($st)) ?></span>
          <?php if ((int) $r['attempts'] > 0): ?><div class="muted" style="font-size:11.5px"><?= (int) $r['attempts'] ?> attempt(s)</div><?php endif; ?>
          <?php if ((string) ($r['error'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e(mb_substr((string) $r['error'], 0, 140)) ?></div><?php endif; ?></td>
        <td class="wo-actions" style="white-space:nowrap">
          <?php if (in_array($st, ['failed', 'unknown', 'waiting'], true)): ?>
            <form method="post" data-no-guard<?= $st === 'unknown' ? ' onsubmit="return confirm(\'Meta may already have this message. Send it again?\')"' : '' ?>><?= Security::csrfField() ?>
              <input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn ghost sm" type="submit">↻ Retry</button></form>
          <?php endif; ?>
          <?php if (in_array($st, ['queued', 'waiting', 'failed', 'unknown'], true)): ?>
            <form method="post" data-no-guard><?= Security::csrfField() ?>
              <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn ghost sm" type="submit">✕</button></form>
          <?php endif; ?>
          <?php if ($link !== '' && !in_array($st, ['delivered', 'read'], true)): ?>
            <a class="btn ghost sm" href="<?= $e($link) ?>" target="_blank" rel="noopener" title="Send it yourself from WhatsApp">💬 wa.me</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php admin_footer();
