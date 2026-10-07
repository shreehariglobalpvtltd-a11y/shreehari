<?php
/**
 * admin/wa-compose.php — WhatsApp any message to any number (5 Oct 2026).
 *
 * Owner: "WhatsApp API bata jun number Nepali / India ko number ma j msg ni
 * lekhera 1-1 jana lai pathaun milne system". Type one or more India / Nepal
 * numbers and a message; each number gets its OWN message (never a group),
 * through AiSensy - the full text when that number wrote to us in the last
 * 24 h, else on the approved template (AiSensyAPI::sendAny). Every send is
 * a message_logs row (purpose manual_message) with a one-tap delivery check.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
// 7 Oct 2026 (owner: "hunx"): free text from the company number is a
// marketing act - only staff who may send campaigns may use it.
$admin = admin_boot('marketing.send');
require_once INCLUDE_PATH . '/notify.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';

if (Auth::isAgent()) {   // office tool: an agent never messages strangers from the company number
    http_response_code(403);
    exit('Forbidden');
}

const WAC_MAX_NUMBERS = 20;
const WAC_PURPOSE     = 'manual_message';

$e       = static fn($v): string => Security::e((string) $v);
$adminId = (int) ($admin['id'] ?? 0);
$flash   = null;
$results = [];
$form    = ['numbers' => '', 'name' => '', 'text' => '', 'cc' => 'IN'];
$driver  = Settings::getString('whatsapp_driver', 'click_to_chat');
$aisOn   = AiSensyAPI::enabled();

/** One typed line -> [intl digits or '', country hint]. +977 / 977 / +91 / 91 win over the default. */
$parseNumber = static function (string $raw, string $cc): array {
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    $d = ltrim($d, '0');
    if (strlen($d) === 13 && str_starts_with($d, '977')) {
        return [$d, 'NP'];
    }
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        return [$d, 'IN'];
    }
    if (strlen($d) === 10) {
        return [($cc === 'NP' ? '977' : '91') . $d, $cc];
    }
    return ['', $cc];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (($_POST['action'] ?? '') === 'check') {
        // Did WhatsApp really deliver it? (HTTP 200 only means "accepted".)
        $row = Database::fetch(
            "SELECT id, to_number, provider_ref FROM message_logs WHERE id = :i AND purpose = :p AND provider = 'aisensy'",
            ['i' => (int) ($_POST['id'] ?? 0), 'p' => WAC_PURPOSE]
        );
        if ($row === null || (string) $row['provider_ref'] === '') {
            $flash = ['bad', 'Nothing to check for that row.'];
        } else {
            $st = AiSensyAPI::messageStatus((string) $row['provider_ref'], (string) $row['to_number']);
            $s  = strtoupper((string) ($st['status'] ?? ''));
            if ($s === 'FAILED') {
                Database::run("UPDATE message_logs SET status = 'failed', error = :er WHERE id = :i",
                    ['er' => mb_substr('AiSensy: ' . (string) ($st['error'] ?? 'failed'), 0, 255), 'i' => (int) $row['id']]);
            }
            $flash = in_array($s, ['DELIVERED', 'READ'], true)
                ? ['ok', '+' . $row['to_number'] . ': ' . ($s === 'READ' ? '👀 Read · पढ्यो' : '✅ Delivered · पुग्यो')]
                : ($s === 'FAILED'
                    ? ['bad', '+' . $row['to_number'] . ': ❌ Failed — ' . (string) ($st['error'] ?? '')]
                    : ['ok', '+' . $row['to_number'] . ': 📤 ' . ($s !== '' ? $s : 'no answer yet') . ' — not delivered yet (phone off / no internet). Check again later.']);
        }
    } else {
        $form = [
            'numbers' => trim((string) ($_POST['numbers'] ?? '')),
            'name'    => trim(Security::clean((string) ($_POST['name'] ?? ''), 60)),
            'text'    => trim(str_replace("\r\n", "\n", (string) ($_POST['text'] ?? ''))),
            'cc'      => ($_POST['cc'] ?? 'IN') === 'NP' ? 'NP' : 'IN',
        ];
        $lines = array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', $form['numbers']) ?: []), static fn($l) => $l !== ''));
        if (!$aisOn) {
            $flash = ['bad', 'AiSensy is not configured (AISENSY_API_KEY).'];
        } elseif ($lines === []) {
            $flash = ['bad', 'Type at least one number · कम्तीमा एउटा नम्बर लेख्नुहोस्।'];
        } elseif (count($lines) > WAC_MAX_NUMBERS) {
            $flash = ['bad', 'At most ' . WAC_MAX_NUMBERS . ' numbers at a time.'];
        } elseif ($form['text'] === '') {
            $flash = ['bad', 'Write the message · सन्देश लेख्नुहोस्।'];
        } elseif (mb_strlen($form['text']) > 2000) {
            $flash = ['bad', 'The message is too long (2000 characters at most).'];
        } else {
            $seen = [];
            foreach ($lines as $line) {
                [$intl, $hint] = $parseNumber($line, $form['cc']);
                if ($intl === '' || AiSensyAPI::normalizePhone($intl) === '') {
                    $results[] = ['to' => $line, 'ok' => false, 'note' => 'Not an India / Nepal mobile number'];
                    continue;
                }
                if (isset($seen['n' . $intl])) {      // 'n' prefix: never key an array by phone digits
                    continue;
                }
                $seen['n' . $intl] = true;
                if (!Security::rateLimit('wa_compose', (string) $adminId, 150, 3600)) {
                    $results[] = ['to' => '+' . $intl, 'ok' => false, 'note' => 'Hourly limit reached (150) — try again later'];
                    continue;
                }
                $res = Notify::whatsapp($intl, $form['text'], null, $hint, [], null, [
                    'purpose'  => WAC_PURPOSE,
                    'admin_id' => $adminId,
                    'name'     => $form['name'],
                    'label'    => 'Message',
                ]);
                $j = Notify::whatsappJournal();
                $last = $j !== [] ? $j[count($j) - 1] : [];
                $results[] = [
                    'to'   => '+' . $intl,
                    'ok'   => $res === true,
                    'note' => $res === true
                        ? 'Accepted by WhatsApp · पठाइयो' . (($last['driver'] ?? '') !== 'aisensy' ? ' (' . (string) ($last['driver'] ?? $driver) . ')' : '')
                        : 'Not sent — see Messages log for the reason',
                ];
            }
            $okN = count(array_filter($results, static fn($r) => $r['ok']));
            Logger::audit('wa.compose', 'message', WAC_PURPOSE, null,
                ['numbers' => count($lines), 'sent' => $okN, 'chars' => mb_strlen($form['text'])], 'manual WhatsApp by admin #' . $adminId);
            $flash = $okN > 0
                ? ['ok', $okN . ' of ' . count($results) . ' sent · पठाइयो. Use "Check" below to see delivery.']
                : ['bad', 'Nothing was sent.'];
            if ($okN === count($results)) {
                $form['numbers'] = '';   // ready for the next person; the message stays
            }
        }
    }
}

$recent = Database::fetchAll(
    "SELECT m.id, m.to_number, m.body, m.status, m.provider, m.provider_ref, m.error, m.created_at, a.full_name, a.username
       FROM message_logs m LEFT JOIN admins a ON a.id = m.admin_id
      WHERE m.purpose = :p ORDER BY m.id DESC LIMIT 50",
    ['p' => WAC_PURPOSE]
);

admin_header('WhatsApp message', 'wa-compose');
?>
<style>
.wc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:14px}
@media (max-width:860px){.wc-grid{grid-template-columns:minmax(0,1fr)}}
.wc-grid label{display:block;font-weight:700;margin:10px 0 4px}
.wc-grid textarea,.wc-grid input[type=text]{width:100%;box-sizing:border-box}
.wc-how{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.wc-count{font-size:12px;text-align:right}
.wc-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:800}
.wc-sent{background:#E4F6EC;color:#11703F}.wc-failed{background:#FDE7EB;color:#A50E2D}.wc-other{background:#EEF0F4;color:#5C6B85}
:root[data-theme="dark"] .wc-sent{background:#12321F;color:#5FD39A}
:root[data-theme="dark"] .wc-failed{background:#3D1219;color:#FF8FA1}
:root[data-theme="dark"] .wc-other{background:#1B2638;color:#93A4BE}
.wc-body{max-width:420px;white-space:pre-wrap;word-break:break-word;font-size:12.5px}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="wc-how">
  <b>Any message to any India / Nepal number · जुनसुकै नम्बरमा जुनसुकै सन्देश</b><br>
  Each number gets its own message from our WhatsApp <b>+<?= $e(AiSensyAPI::config('AISENSY_WA_NUMBER', 'aisensy_wa_number', '919157001507')) ?></b> (AiSensy) — nobody sees the other numbers.
  If that person wrote to us in the last 24 hours the message goes exactly as typed; otherwise it goes inside our approved message
  (<i>"Namaste &lt;name&gt; … your message …"</i>), so it reaches every number. Lines are joined with “·” in that case and the message is cut at <?= (int) AiSensyAPI::MESSAGE_MAX ?> characters.<br>
  हरेक नम्बरलाई छुट्टै सन्देश जान्छ। २४ घण्टाभित्र हामीलाई लेखेको नम्बरमा सन्देश जस्ताको तस्तै, नत्र स्वीकृत टेम्प्लेटभित्र जान्छ।
  <?php if ($driver !== 'aisensy'): ?><br><b style="color:#A50E2D">⚠️ whatsapp_driver is "<?= $e($driver) ?>", not "aisensy" — set it in Settings so these go through AiSensy.</b><?php endif; ?>
</div>

<div class="wc-grid">
  <div class="panel">
    <h2>✉️ New message · नयाँ सन्देश</h2>
    <form method="post" data-no-guard>
      <?= Security::csrfField() ?>
      <label for="wcNumbers">Number(s) · नम्बर <small class="muted">— one per line, up to <?= WAC_MAX_NUMBERS ?></small></label>
      <textarea id="wcNumbers" name="numbers" rows="4" inputmode="tel" placeholder="9812345678&#10;+919876543210" required><?= $e($form['numbers']) ?></textarea>
      <div style="margin-top:6px;font-size:13px">
        10-digit numbers are ·
        <label style="display:inline;font-weight:600"><input type="radio" name="cc" value="IN" <?= $form['cc'] === 'IN' ? 'checked' : '' ?>> 🇮🇳 India (+91)</label>
        &nbsp;<label style="display:inline;font-weight:600"><input type="radio" name="cc" value="NP" <?= $form['cc'] === 'NP' ? 'checked' : '' ?>> 🇳🇵 Nepal (+977)</label>
        <div class="muted" style="font-size:12px">A number typed with +91 / +977 always uses that country.</div>
      </div>
      <label for="wcName">Name · नाम <small class="muted">(optional — "Namaste &lt;name&gt;")</small></label>
      <input type="text" id="wcName" name="name" maxlength="60" value="<?= $e($form['name']) ?>" placeholder="Ram ji">
      <label for="wcText">Message · सन्देश</label>
      <textarea id="wcText" name="text" rows="7" maxlength="2000" required placeholder="नमस्ते! …"><?= $e($form['text']) ?></textarea>
      <div class="wc-count muted"><span id="wcCount">0</span> / <?= (int) AiSensyAPI::MESSAGE_MAX ?></div>
      <button class="btn" type="submit" style="margin-top:8px" <?= $aisOn ? '' : 'disabled' ?>>📤 Send on WhatsApp · पठाउनुहोस्</button>
    </form>
    <?php if ($results !== []): ?>
      <table style="margin-top:14px">
        <thead><tr><th>To</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
          <tr><td class="mono"><?= $e($r['to']) ?></td>
              <td><span class="wc-pill <?= $r['ok'] ? 'wc-sent' : 'wc-failed' ?>"><?= $r['ok'] ? 'sent' : 'not sent' ?></span> <small class="muted"><?= $e($r['note']) ?></small></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Last 50 sent · पछिल्ला सन्देश</h2>
    <table>
      <thead><tr><th>When</th><th>To</th><th>Message</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if ($recent === []): ?><tr><td colspan="5" class="muted">Nothing sent from here yet.</td></tr><?php endif; ?>
      <?php foreach ($recent as $m): $st = (string) $m['status']; ?>
        <tr>
          <td style="white-space:nowrap"><?= $e(date('j M · g:i A', (int) strtotime((string) $m['created_at']))) ?>
            <div class="muted" style="font-size:11.5px"><?= $e($m['full_name'] ?: $m['username']) ?></div></td>
          <td class="mono">+<?= $e($m['to_number']) ?></td>
          <td><div class="wc-body"><?= $e(mb_substr((string) $m['body'], 0, 220)) ?></div></td>
          <td><span class="wc-pill wc-<?= in_array($st, ['sent', 'failed'], true) ? $e($st) : 'other' ?>"><?= $e($st) ?></span>
            <small class="muted"><?= $e($m['provider']) ?></small>
            <?php if ((string) ($m['error'] ?? '') !== ''): ?><div class="muted" style="font-size:11.5px"><?= $e(mb_substr((string) $m['error'], 0, 110)) ?></div><?php endif; ?></td>
          <td>
            <?php if ($m['provider'] === 'aisensy' && (string) $m['provider_ref'] !== '' && $st === 'sent'): ?>
              <form method="post" data-no-guard style="display:inline"><?= Security::csrfField() ?>
                <input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn ghost sm" type="submit">Check</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
(function () {
  var t = document.getElementById('wcText'), c = document.getElementById('wcCount');
  if (!t || !c) { return; }
  var max = <?= (int) AiSensyAPI::MESSAGE_MAX ?>;
  function upd() { c.textContent = t.value.length; c.style.color = t.value.length > max ? '#A50E2D' : ''; }
  t.addEventListener('input', upd); upd();
})();
</script>
<?php admin_footer();
