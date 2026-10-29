<?php
/**
 * admin/wa-inbox.php — the WhatsApp inbox, contacts and groups (29 Oct 2026).
 *
 * Owner's "AI System Prompt (Opus 5.5 Max)" 6.3 + 7: every message people
 * send the office number, grouped by sender, with the bot's answers beside
 * them; a person can reply (inside the free 24-hour window only) and pause
 * the bot for that number while they handle it; who wrote (passenger /
 * agent / office / unknown, opted out or not); and S HARI AI's office
 * groups — which group it may answer in, the quiet hours, what it said.
 *
 * Reads wa_inbound / message_logs / wa_group_log (includes/wainbox.php,
 * includes/wagroup.php). Seeing it: customers.view. Replying and pausing:
 * customers.view too (the desk's own job). Group switches: settings.manage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/wagroup.php';

$admin   = admin_boot('customers.view');
$adminId = (int) $admin['id'];
$canSet  = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$tab     = in_array($_GET['tab'] ?? '', ['inbox', 'contacts', 'groups'], true) ? (string) $_GET['tab'] : 'inbox';
$open    = preg_replace('/\D/', '', (string) ($_GET['p'] ?? '')) ?? '';
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        $act = (string) ($_POST['action'] ?? '');
        $who = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '';
        if ($act === 'reply') {
            $r = WaInbox::reply($who, (string) ($_POST['text'] ?? ''), $adminId);
            $flash = $r['ok'] ? ['ok', 'Sent. / पठाइयो।'] : ['bad', $r['why']];
            $open = $who;
        } elseif ($act === 'pause') {
            $h = (int) ($_POST['hours'] ?? 0);
            WaInbox::pause($who, $h, $adminId);
            $flash = ['ok', $h > 0 ? 'The bot is quiet for this number for ' . $h . ' h. / बोट रोकियो।' : 'The bot answers this number again. / बोट फेरि चालु।'];
            $open = $who;
        } elseif ($act === 'groups') {
            if (!$canSet) {
                throw new RuntimeException('Group switches need the Settings permission.');
            }
            $before = ['on' => WaGroup::replyOn(), 'correct' => WaGroup::correctOn(), 'groups' => WaGroup::groups()];
            $groups = [];
            foreach ((array) ($_POST['g_id'] ?? []) as $i => $gid) {
                $gid = WaGroup::cleanId((string) $gid);
                if ($gid === '') {
                    continue;
                }
                $groups[$gid] = [
                    'name'  => Security::clean((string) ($_POST['g_name'][$i] ?? ''), 60),
                    'reply' => (string) ($_POST['g_reply'][$i] ?? '0') === '1',
                ];
            }
            $newId = WaGroup::cleanId((string) ($_POST['new_id'] ?? ''));
            if ($newId !== '' && !isset($groups[$newId])) {
                $groups[$newId] = ['name' => Security::clean((string) ($_POST['new_name'] ?? ''), 60), 'reply' => false];
            }
            foreach (['wa_group_quiet_from', 'wa_group_quiet_to'] as $k) {
                $v = trim((string) ($_POST[$k] ?? ''));
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                    throw new RuntimeException('Quiet hours are HH:MM, e.g. 22:00.');
                }
                Settings::set($k, $v, 'string', 'whatsapp');
            }
            Settings::set('wa_groups', $groups, 'json', 'whatsapp');
            Settings::set('wa_group_reply_on', (string) ($_POST['wa_group_reply_on'] ?? '0') === '1' ? '1' : '0', 'bool', 'whatsapp');
            Settings::set('wa_group_correct_on', (string) ($_POST['wa_group_correct_on'] ?? '0') === '1' ? '1' : '0', 'bool', 'whatsapp');
            Settings::set('wa_group_mention_words', Security::clean((string) ($_POST['wa_group_mention_words'] ?? ''), 200), 'string', 'whatsapp');
            Logger::audit('wa.groups', 'settings', 'whatsapp', $before, ['on' => WaGroup::replyOn(), 'correct' => WaGroup::correctOn(), 'groups' => $groups], 'WhatsApp groups saved by admin #' . $adminId);
            $flash = ['ok', 'Group settings saved. / सेभ भयो।'];
            $tab = 'groups';
        } elseif ($act === 'switches') {
            if (!$canSet) {
                throw new RuntimeException('These switches need the Settings permission.');
            }
            $before = [];
            $after  = [];
            foreach (['wa_pay_ocr_on' => 'whatsapp', 'wa_onboard_on' => 'whatsapp', 'data_retention_on' => 'general'] as $k => $grp) {
                $before[$k] = Settings::getBool($k, false) ? 1 : 0;
                $after[$k]  = (string) ($_POST[$k] ?? '0') === '1' ? 1 : 0;
                Settings::set($k, $after[$k] ? '1' : '0', 'bool', $grp);
            }
            Logger::audit('wa.switches', 'settings', 'whatsapp', $before, $after, 'S HARI AI switches saved by admin #' . $adminId);
            $flash = ['ok', 'Saved. / सेभ भयो।'];
            $tab = 'groups';
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$haveInbound = true;
try {
    Database::scalar('SELECT 1 FROM wa_inbound LIMIT 1', [], null);
} catch (Throwable $ex) {
    $haveInbound = false;
}

admin_header('WhatsApp Inbox', 'wa-inbox');
admin_page_head('Every WhatsApp message to the office number, with what the bot said — reply, or pause the bot while you handle someone. Plus contacts and S HARI AI in the office groups.');
?>
<style>
.wi-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
.wi-tabs a{padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:700;text-decoration:none;color:inherit}
.wi-tabs a.on{background:var(--blue);border-color:var(--blue);color:#fff}
.wi-grid{display:grid;grid-template-columns:minmax(260px,360px) 1fr;gap:14px;align-items:start}
@media (max-width:820px){.wi-grid{grid-template-columns:1fr}}
.wi-list{display:grid;gap:6px;max-height:70vh;overflow:auto}
.wi-row{display:grid;gap:2px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--card);text-decoration:none;color:inherit}
.wi-row.on{border-color:var(--blue);box-shadow:0 0 0 2px rgba(31,79,224,.15)}
.wi-row b{display:flex;justify-content:space-between;gap:8px}
.wi-row span{font-size:12.5px;color:var(--mut);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.wi-badge{background:#dc2626;color:#fff;border-radius:999px;padding:0 7px;font-size:11.5px;line-height:18px}
.wi-chat{display:grid;gap:8px;max-height:60vh;overflow:auto;padding:4px}
.wi-msg{max-width:min(520px,88%);padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13.5px}
.wi-msg.in{background:var(--soft);border:1px solid var(--line);justify-self:start}
.wi-msg.out{background:#dcfce7;color:#14532d;justify-self:end}
.wi-msg small{display:block;margin-top:4px;font-size:11px;opacity:.75}
.wi-reply{display:grid;gap:8px;margin-top:10px}
.wi-reply textarea{width:100%;min-height:70px}
.wi-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.wi-g input[type=text]{width:100%}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<nav class="wi-tabs" aria-label="Inbox sections">
  <?php foreach (['inbox' => '💬 Inbox', 'contacts' => '👥 Contacts', 'groups' => '🫂 Office groups (S HARI AI)'] as $k => $l): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $e($l) ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$haveInbound && $tab !== 'groups'): ?>
  <div class="panel"><div class="panel-body"><p class="muted" style="margin:0">No WhatsApp messages are recorded on this server yet (table <code>wa_inbound</code> is missing). Messages appear here once the Meta number receives them.</p></div></div>
<?php elseif ($tab === 'inbox'):
    $threads = WaInbox::threads($adminId);
    $unread  = array_sum(array_column($threads, 'unread'));
    if ($open === '' && $threads !== []) {
        $open = $threads[0]['digits'];
    }
    $chat     = $open !== '' ? WaInbox::thread($open) : [];
    $pausedTo = $open !== '' ? WaInbox::pausedUntil($open) : 0;
    WaInbox::markSeen($adminId);
?>
  <div class="wi-grid">
    <div class="panel"><h2>Last 7 days<?= $unread > 0 ? ' · <span class="wi-badge">' . (int) $unread . ' new</span>' : '' ?></h2>
      <div class="panel-body wi-list">
        <?php if ($threads === []): ?><p class="muted" style="margin:0">Nobody wrote in the last 7 days.</p><?php endif; ?>
        <?php foreach ($threads as $t): ?>
          <a class="wi-row <?= $t['digits'] === $open ? 'on' : '' ?>" href="?tab=inbox&amp;p=<?= $e($t['digits']) ?>">
            <b><span style="color:inherit;font-size:14px">+<?= $e($t['digits']) ?><?= $t['paused'] ? ' ⏸' : '' ?></span>
              <?php if ($t['unread'] > 0): ?><span class="wi-badge"><?= (int) $t['unread'] ?></span><?php endif; ?></b>
            <span><?= $e($t['last_text'] !== '' ? $t['last_text'] : '—') ?></span>
            <span><?= $e(substr($t['last_at'], 0, 16)) ?> · <?= (int) $t['msgs'] ?> msg</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel">
      <?php if ($open === ''): ?>
        <div class="panel-body"><p class="muted" style="margin:0">Choose a conversation.</p></div>
      <?php else: ?>
        <h2>+<?= $e($open) ?> <a class="btn btn-ghost btn-sm" href="customers.php?q=<?= $e(substr($open, -10)) ?>">Customer 360</a></h2>
        <div class="panel-body">
          <div class="wi-chat" id="wiChat">
            <?php if ($chat === []): ?><p class="muted">No messages in the last 7 days.</p><?php endif; ?>
            <?php foreach ($chat as $m): ?>
              <div class="wi-msg <?= $m['dir'] ?>"><?= $e(trim($m['text'] !== '' ? $m['text'] : '[' . $m['kind'] . ']')) ?><small><?= $e(substr($m['at'], 0, 16)) ?> · <?= $m['dir'] === 'out' ? $e(($m['kind'] !== '' ? $m['kind'] : 'message') . ' · ' . $m['status']) : $e($m['kind']) ?></small></div>
            <?php endforeach; ?>
          </div>
          <form method="post" class="wi-reply">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="reply"><input type="hidden" name="phone" value="<?= $e($open) ?>">
            <label>Reply as the office (free inside 24 h of their last message)
              <textarea name="text" maxlength="1000" placeholder="नमस्ते, ..."></textarea></label>
            <p><button class="btn" type="submit">Send on WhatsApp</button></p>
          </form>
          <form method="post" class="wi-inline">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="pause"><input type="hidden" name="phone" value="<?= $e($open) ?>">
            <?php if ($pausedTo > time()): ?>
              <span class="pill st-warn">Bot paused until <?= $e(date('d M H:i', $pausedTo)) ?></span>
              <button class="btn btn-ghost" type="submit" name="hours" value="0">Resume the bot</button>
            <?php else: ?>
              <span class="muted">Handling them yourself?</span>
              <button class="btn btn-ghost" type="submit" name="hours" value="2">Pause bot 2 h</button>
              <button class="btn btn-ghost" type="submit" name="hours" value="24">Pause 24 h</button>
            <?php endif; ?>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <script>(function(){var c=document.getElementById('wiChat');if(c){c.scrollTop=c.scrollHeight;}})();</script>

<?php elseif ($tab === 'contacts'):
    $contacts = WaInbox::contacts();
    $roleLbl = ['admin' => ['Office', 'st-ok'], 'agent' => ['Agent', 'st-ok'], 'staff' => ['Staff', 'st-ok'], 'customer' => ['Passenger', ''], 'unknown' => ['Unknown', 'st-muted']];
?>
  <div class="panel"><h2>Who wrote in the last 30 days (<?= count($contacts) ?>)</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Number</th><th>Role</th><th>Name</th><th>Bookings</th><th>Messages</th><th>Last message</th><th>Marketing</th><th></th></tr></thead>
      <tbody>
      <?php if ($contacts === []): ?><tr><td colspan="8" class="muted">Nobody yet.</td></tr><?php endif; ?>
      <?php foreach ($contacts as $c): $rl = $roleLbl[$c['role']] ?? [$c['role'], '']; ?>
        <tr>
          <td data-label="Number">+<?= $e($c['digits']) ?></td>
          <td data-label="Role"><span class="pill <?= $rl[1] ?>"><?= $e($rl[0]) ?></span></td>
          <td data-label="Name"><?= $e($c['name']) ?></td>
          <td data-label="Bookings"><?= (int) $c['bookings'] ?></td>
          <td data-label="Messages"><?= (int) $c['msgs'] ?></td>
          <td data-label="Last message"><?= $e(substr($c['last_at'], 0, 16)) ?></td>
          <td data-label="Marketing"><?= $c['optout'] ? '<span class="pill st-warn">opted out</span>' : 'ok' ?></td>
          <td data-label=""><a class="btn btn-sm btn-ghost" href="?tab=inbox&amp;p=<?= $e($c['digits']) ?>">Open chat</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

<?php else:
    $groups = WaGroup::groups();
    $seen   = WaGroup::seen();
    foreach ($seen as $s) {
        $gid = (string) $s['group_id'];
        if (!isset($groups[$gid])) {
            $groups[$gid] = ['name' => '', 'reply' => false];
        }
    }
    $recent = WaGroup::recent(40);
    $names  = array_map(static fn(array $g): string => $g['name'], $groups);
?>
  <div class="panel"><h2>S HARI AI in the office groups</h2>
    <div class="panel-body ab-uses">
      <p class="muted" style="margin:0">The AI reads every message in a group the office number is part of (groups made with Meta's Groups API), and answers <b>only</b>
        when someone calls it (<code>@S Hari AI</code>, <code>AI बताउ</code>), asks a timetable / seats / office / refund-rule question, or — if you allow it —
        states a pickup time the timetable contradicts. Never greetings, jokes or personal talk; never anyone's booking, number or money.
        Status: <?= WaGroup::replyOn() ? '<span class="pill st-ok">replies ON</span>' : '<span class="pill st-muted">replies OFF — listening only</span>' ?></p>
      <?php if ($canSet): ?>
      <form method="post" class="wi-g" style="display:grid;gap:10px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="groups">
        <label class="wi-inline"><input type="checkbox" name="wa_group_reply_on" value="1" <?= WaGroup::replyOn() ? 'checked' : '' ?>> Let S HARI AI reply in the groups ticked below</label>
        <label class="wi-inline"><input type="checkbox" name="wa_group_correct_on" value="1" <?= WaGroup::correctOn() ? 'checked' : '' ?>> Correct a wrong pickup time ("⚠️ सच्याउँछु: Surat pickup 2 baje → 13:00")</label>
        <div class="wi-inline">Quiet hours — no replies from
          <input type="text" name="wa_group_quiet_from" value="<?= $e(Settings::getString('wa_group_quiet_from', '22:00')) ?>" style="width:80px" pattern="([01]\d|2[0-3]):[0-5]\d">
          to <input type="text" name="wa_group_quiet_to" value="<?= $e(Settings::getString('wa_group_quiet_to', '06:00')) ?>" style="width:80px" pattern="([01]\d|2[0-3]):[0-5]\d"></div>
        <label>Extra words that call the AI (comma separated, optional)
          <input type="text" name="wa_group_mention_words" value="<?= $e(Settings::getString('wa_group_mention_words', '')) ?>" placeholder="sahayak, hari bot"></label>
        <div class="dt-wrap"><table class="dt card-table">
          <thead><tr><th>Group id</th><th>Name</th><th>Reply here</th></tr></thead>
          <tbody>
          <?php if ($groups === []): ?><tr><td colspan="3" class="muted">No group has written yet. A group appears here by itself after its first message, or add its id below.</td></tr><?php endif; ?>
          <?php $i = 0; foreach ($groups as $gid => $g): ?>
            <tr>
              <td data-label="Group id"><code><?= $e($gid) ?></code><input type="hidden" name="g_id[<?= $i ?>]" value="<?= $e($gid) ?>"></td>
              <td data-label="Name"><input type="text" name="g_name[<?= $i ?>]" value="<?= $e($g['name']) ?>" maxlength="60" placeholder="S Hari Office"></td>
              <td data-label="Reply here"><select name="g_reply[<?= $i ?>]"><option value="0">Listen only</option><option value="1" <?= $g['reply'] ? 'selected' : '' ?>>Reply</option></select></td>
            </tr>
          <?php $i++; endforeach; ?>
          </tbody>
        </table></div>
        <div class="wi-inline"><input type="text" name="new_id" placeholder="Add a group id" style="max-width:260px"><input type="text" name="new_name" placeholder="Name" style="max-width:200px"></div>
        <p><button class="btn" type="submit">Save</button></p>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canSet): ?>
  <div class="panel"><h2>Other S HARI AI switches</h2>
    <div class="panel-body">
      <form method="post" style="display:grid;gap:10px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="switches">
        <label class="wi-inline"><input type="checkbox" name="wa_pay_ocr_on" value="1" <?= Settings::getBool('wa_pay_ocr_on', false) ? 'checked' : '' ?>>
          Read payment screenshots on WhatsApp — UTR + amount go to <b>Verify Payments</b> for a person to confirm (needs the Gemini key; never confirms by itself)</label>
        <label class="wi-inline"><input type="checkbox" name="wa_onboard_on" value="1" <?= Settings::getBool('wa_onboard_on', false) ? 'checked' : '' ?>>
          Send new agents one WhatsApp lesson a day for 7 days (free messages only; needs the cron line in docs/UPGRADE-2026-10-29-shari-ai-master.md)</label>
        <label class="wi-inline"><input type="checkbox" name="data_retention_on" value="1" <?= Settings::getBool('data_retention_on', false) ? 'checked' : '' ?>>
          Delete old logs: AI tool log after <?= max(30, Settings::getInt('ai_log_keep_days', 90)) ?> days, WhatsApp message logs after <?= max(90, Settings::getInt('wa_log_keep_days', 365)) ?> days (bookings are never touched)</label>
        <p><button class="btn" type="submit">Save</button></p>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <div class="panel"><h2>Latest group messages</h2>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>When</th><th>Group</th><th>From</th><th>Message</th><th>S HARI AI</th></tr></thead>
      <tbody>
      <?php if ($recent === []): ?><tr><td colspan="5" class="muted">Nothing yet.</td></tr><?php endif; ?>
      <?php foreach ($recent as $r): $gid = (string) $r['group_id']; ?>
        <tr>
          <td data-label="When"><?= $e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td data-label="Group"><?= $e(($names[$gid] ?? '') !== '' ? $names[$gid] : $gid) ?></td>
          <td data-label="From">+<?= $e((string) $r['from_digits']) ?></td>
          <td data-label="Message"><?= $e(mb_substr((string) ($r['body'] ?? ''), 0, 160)) ?></td>
          <td data-label="S HARI AI"><?= (int) $r['replied'] === 1 ? '✅ ' . $e((string) $r['reply_why']) . '<br><span class="muted">' . $e(mb_substr((string) ($r['reply_text'] ?? ''), 0, 160)) . '</span>' : ($r['reply_why'] ? $e((string) $r['reply_why']) : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
<?php
admin_footer();
