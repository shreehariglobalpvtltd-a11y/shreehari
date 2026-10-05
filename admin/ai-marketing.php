<?php
/**
 * admin/ai-marketing.php — 🤖 AI Marketing (6 Oct 2026).
 *
 * Owner: "WhatsApp full advance — AI afai sikne bujhne, message automatic
 * jaos, AI le marketing gari rakhos."
 *
 * One screen over includes/mkautopilot.php + includes/ailearn.php:
 *   - the switch (Off / Draft / Auto) and its limits;
 *   - the money, honestly: AiSensy wallet, what tickets need, what is
 *     left for marketing, the Meta price of one message;
 *   - today's plan (who, how many, the AI's line) — Preview / Run now;
 *   - what it has learned: bookings per group, the lines that sold;
 *   - what the chat assistant learned from questions (drafts to publish).
 *
 * customers.view to look; superadmin / marketing.send to change anything.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/mkautopilot.php';
require_once INCLUDE_PATH . '/ailearn.php';

$admin = admin_boot('customers.view');
if (Auth::isCounterAgent()) {
    admin_header('AI Marketing', 'ai-marketing');
    echo '<div class="flash bad">AI Marketing is an office page.</div>';
    admin_footer();
    exit;
}
$me     = (int) $admin['id'];
$mayAct = Auth::isSuperadmin() || Auth::can('marketing.send');
$e      = static fn ($v): string => Security::e((string) $v);
$flash  = null;
$plan   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayAct) {
        $flash = ['bad', 'Only the owner or a manager (marketing.send) can change AI marketing.'];
    } else {
        try {
            if ($act === 'save') {
                $mode = in_array($_POST['mk_auto_mode'] ?? '', ['off', 'draft', 'auto'], true) ? (string) $_POST['mk_auto_mode'] : 'off';
                Settings::set('mk_auto_mode', $mode, 'string', 'marketing');
                Settings::set('mk_auto_daily', max(0, min(1000, (int) ($_POST['mk_auto_daily'] ?? 25))), 'int', 'marketing');
                Settings::set('mk_auto_gap_days', max(3, min(90, (int) ($_POST['mk_auto_gap_days'] ?? 10))), 'int', 'marketing');
                Settings::set('mk_auto_reserve', max(0, min(100000, (int) ($_POST['mk_auto_reserve'] ?? 25))), 'int', 'marketing');
                Settings::set('mk_auto_hour', max(8, min(20, (int) ($_POST['mk_auto_hour'] ?? 10))), 'int', 'marketing');
                Settings::set('mk_auto_nepal', isset($_POST['mk_auto_nepal']), 'bool', 'marketing');
                Settings::set('mk_auto_ai_copy', isset($_POST['mk_auto_ai_copy']), 'bool', 'marketing');
                Settings::set('mk_auto_lang', ($_POST['mk_auto_lang'] ?? '') === 'hi' ? 'hi' : 'ne', 'string', 'marketing');
                Settings::set('mk_auto_offer', mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST['mk_auto_offer'] ?? '')) ?? ''), 0, 160), 'string', 'marketing');
                $link = trim((string) ($_POST['mk_auto_link'] ?? ''));
                Settings::set('mk_auto_link', preg_match('#^https?://\S+$#i', $link) === 1 ? mb_substr($link, 0, 500) : '', 'string', 'marketing');
                $fest = [];
                foreach (preg_split('/\R/', (string) ($_POST['mk_auto_festivals'] ?? '')) ?: [] as $line) {
                    if (preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s*[|,]\s*(.{2,60}?)\s*$/u', $line, $m) === 1 && strtotime($m[1]) !== false) {
                        $fest[] = $m[1] . ' | ' . Security::clean($m[2], 60);
                    }
                }
                Settings::set('mk_auto_festivals', implode("\n", $fest), 'string', 'marketing');
                Settings::set('ai_learn_on', isset($_POST['ai_learn_on']), 'bool', 'ai');
                Settings::set('ai_learn_autopublish', isset($_POST['ai_learn_autopublish']), 'bool', 'ai');
                Logger::audit('marketing.autopilot_settings', 'settings', 'mk_auto', null, ['mode' => $mode], 'by admin #' . $me);
                $flash = ['ok', 'Saved. Mode: ' . strtoupper($mode) . '.'];
            } elseif ($act === 'preview') {
                $plan = MkAutopilot::run(true, $me);
                $flash = ['ok', 'Preview only — nothing was queued or sent.'];
            } elseif ($act === 'run') {
                if (MkAutopilot::mode() === 'off') {
                    throw new RuntimeException('Switch the autopilot to Draft or Auto first.');
                }
                $plan = MkAutopilot::run(false, $me);
                MkAutopilot::kvSet('day', date('Y-m-d'));
                $n = array_sum(array_column($plan['campaigns'], 'people'));
                $flash = ['ok', $n > 0
                    ? ($plan['mode'] === 'auto' ? $n . ' people queued — the worker sends them now (outside quiet hours).' : $n . ' people put in DRAFT campaigns — press Launch on 📣 Campaigns to send.')
                    : 'Nothing queued: ' . ($plan['note'] ?: 'no one to send to.')];
            } elseif ($act === 'stop') {
                $n = (int) Database::run("UPDATE mk_campaigns SET status = 'paused' WHERE audience LIKE 'autopilot=%' AND status IN ('scheduled','running')");
                Settings::set('mk_auto_mode', 'off', 'string', 'marketing');
                Logger::audit('marketing.autopilot_stop', 'mk_campaign', '*', null, ['paused' => $n], 'by admin #' . $me);
                $flash = ['ok', 'Autopilot OFF and ' . $n . ' AI campaign(s) paused.'];
            } elseif ($act === 'learn') {
                $r = AiLearn::learn(6);
                $flash = ['ok', 'AI studied the open questions: ' . $r['drafted'] . ' drafted, ' . $r['published'] . ' published, ' . $r['needs_owner'] . ' need your answer, ' . $r['skipped'] . ' skipped.'];
            } elseif ($act === 'publish' || $act === 'archive') {
                $aid = (int) ($_POST['aid'] ?? 0);
                $row = Database::fetch("SELECT id FROM ai_kb_articles WHERE id = :id AND source_type = 'ai_learned'", ['id' => $aid]);
                if ($row === null) {
                    throw new RuntimeException('Article not found.');
                }
                AiKb::setState($aid, $act === 'publish' ? 'published' : 'archived', $me);
                if ($act === 'publish') {
                    Database::run("UPDATE ai_unanswered_questions SET status = 'answered' WHERE article_id = :a", ['a' => $aid]);
                }
                Logger::audit('ai.learned_' . $act, 'ai_kb_article', (string) $aid, null, null, 'by admin #' . $me);
                $flash = ['ok', $act === 'publish' ? 'Published — the assistant uses it from now on.' : 'Archived.'];
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$ready   = Campaigns::ready();
$mode    = MkAutopilot::mode();
$budget  = $ready ? MkAutopilot::budget() : [];
$scores  = $ready ? MkAutopilot::scores() : [];
$lessons = $ready ? MkAutopilot::lessons() : ['good' => [], 'bad' => []];
$fest    = MkAutopilot::festivalNow();
$hist    = MkAutopilot::history();
$lc      = AiLearn::counts();
$lhist   = AiLearn::history();
$autoCamps = $ready ? Database::fetchAll("SELECT * FROM mk_campaigns WHERE audience LIKE 'autopilot=%' ORDER BY id DESC LIMIT 20") : [];
$learned = [];
$needs   = [];
try {
    $learned = Database::fetchAll("SELECT id, canonical_title, canonical_answer, nepali_content, publication_status, source_reference, created_at
                                     FROM ai_kb_articles WHERE source_type = 'ai_learned' ORDER BY publication_status = 'draft' DESC, id DESC LIMIT 20");
    $needs = Database::fetchAll("SELECT normalized_question, frequency, updated_at FROM ai_unanswered_questions
                                  WHERE status = 'needs_owner' ORDER BY frequency DESC, updated_at DESC LIMIT 12");
} catch (Throwable $ignored) {
}
$festText = Settings::getString('mk_auto_festivals', '');
if ($festText === '') {
    $festText = implode("\n", array_map(static fn ($f) => $f['date'] . ' | ' . $f['name'], MkAutopilot::FESTIVALS));
}
$money = static fn ($v): string => $v === null ? '—' : '₹' . number_format((float) $v, 2);
$csrf  = Security::csrfField();

admin_header('AI Marketing', 'ai-marketing');
admin_page_head('The AI picks who to message (festival, return trip, long-gone customers, people who asked), writes the line, sends it on WhatsApp by itself within the money you have — and learns which messages bring bookings.');
?>
<style>
.am-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px;margin:0 0 16px}
.am-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:13px 15px;border-top:4px solid var(--line);min-width:0}
.am-card.good{border-top-color:#16a34a}.am-card.warn{border-top-color:#f59e0b}.am-card.bad{border-top-color:#dc2626}
.am-card h4{margin:0 0 6px;font-size:14px}
.am-card .big{font-size:24px;font-weight:800;line-height:1.15}
.am-card p{margin:6px 0 0;font-size:12.5px;color:var(--mut);line-height:1.45}
.am-card dl{margin:6px 0 0;display:grid;grid-template-columns:auto 1fr;gap:2px 10px;font-size:12.5px}
.am-card dt{color:var(--mut)}.am-card dd{margin:0;font-weight:600;min-width:0;overflow-wrap:anywhere}
.am-modes{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 10px}
.am-modes label{display:flex;gap:8px;align-items:flex-start;border:1.5px solid var(--line);border-radius:12px;padding:10px 12px;cursor:pointer;font-size:13px;flex:1 1 200px}
.am-modes label:has(input:checked){border-color:var(--ok,#16a34a);background:var(--ok-bg)}
.am-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:10px;margin:8px 0}
.am-form label,.am-full{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--mut)}
.am-form input,.am-form select,.am-full textarea,.am-full input{padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:40px;min-width:0}
.am-full{margin:8px 0}.am-full textarea{min-height:96px;font-family:inherit}
.am-check{display:flex!important;flex-direction:row!important;align-items:center;gap:8px;color:var(--ink)!important}
.am-check input{min-height:auto!important;width:18px;height:18px}
.am-t{width:100%;border-collapse:collapse;font-size:13px}
.am-t th,.am-t td{padding:7px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.am-t td.n,.am-t th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.am-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.am-line{white-space:pre-wrap;background:#E7F6EE;color:#0E3B24;border-radius:12px;padding:10px 12px;font-size:14px;max-width:520px;margin:4px 0}
.am-by{font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px;background:var(--soft);color:var(--mut)}
.am-note{font-size:13px;color:var(--mut);line-height:1.55;margin:0 0 10px}
.am-honest{border-left:4px solid #f59e0b;background:var(--card);border-radius:10px;padding:10px 14px;font-size:13px;line-height:1.6;margin:0 0 16px}
.am-honest b{color:var(--ink)}
.am-btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.am-q{font-size:13px;padding:6px 0;border-bottom:1px solid var(--line)}
.am-q small{color:var(--mut)}
</style>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php if (!$ready): ?>
  <div class="flash bad">Campaign tables are missing — apply database/upgrade-2026-10-05-campaigns.sql first.</div>
<?php else: ?>

<?php
  $w = $budget['wallet'] ?? null;
  $mTone = $mode === 'auto' ? 'good' : ($mode === 'draft' ? 'warn' : 'bad');
  $wTone = $w === null ? 'bad' : ((int) $budget['by_money'] > 0 ? 'good' : 'bad');
?>
<div class="am-grid">
  <div class="am-card <?= $mTone ?>">
    <h4>🤖 Autopilot</h4>
    <div class="big"><?= $e(['auto' => 'AUTO — sending', 'draft' => 'DRAFT — you launch', 'off' => 'OFF'][$mode]) ?></div>
    <p>Runs every day after <?= Settings::getInt('mk_auto_hour', 10) ?>:00. Quiet hours <?= Settings::getInt('mk_quiet_from', 21) ?>:00–<?= Settings::getInt('mk_quiet_to', 8) ?>:00.
      Last run: <?= $e($hist[0]['at'] ?? 'never') ?></p>
  </div>
  <div class="am-card <?= $wTone ?>">
    <h4>💰 AiSensy wallet</h4>
    <div class="big"><?= $e($money($w)) ?></div>
    <dl>
      <dt>Kept for tickets</dt><dd><?= $e($money($budget['reserve'] ?? 0)) ?></dd>
      <dt>Already queued</dt><dd><?= (int) ($budget['queued'] ?? 0) ?> msgs · <?= $e($money($budget['queued_cost'] ?? 0)) ?></dd>
      <dt>Left for marketing</dt><dd><?= $e($money(max(0, (float) ($budget['spare'] ?? 0)))) ?></dd>
      <dt>Plan</dt><dd><?= $e(($budget['plan'] ?? '') . (($budget['renewal'] ?? '') !== '' ? ' · renews ' . $budget['renewal'] : '')) ?></dd>
    </dl>
  </div>
  <div class="am-card <?= (int) ($budget['allowed'] ?? 0) > 0 ? 'good' : 'warn' ?>">
    <h4>📨 Today the AI may send</h4>
    <div class="big"><?= (int) ($budget['allowed'] ?? 0) ?> <small style="font-size:14px">messages</small></div>
    <dl>
      <dt>By money</dt><dd><?= (int) ($budget['by_money'] ?? 0) ?> (₹<?= $e(number_format((float) ($budget['rate'] ?? 0), 2)) ?> each, India)</dd>
      <dt>Daily limit</dt><dd><?= (int) ($budget['daily'] ?? 0) ?> · used <?= (int) ($budget['auto_today'] ?? 0) ?></dd>
      <dt>Number's 24 h</dt><dd><?= (int) ($budget['tier_used'] ?? 0) ?> / <?= (int) ($budget['tier_limit'] ?? 0) > 0 ? (int) $budget['tier_limit'] : '—' ?> people</dd>
    </dl>
    <?php if (($budget['reason'] ?? '') !== ''): ?><p style="color:var(--bad,#dc2626)"><?= $e($budget['reason']) ?></p><?php endif; ?>
  </div>
  <div class="am-card <?= $fest['go'] || $fest['back'] ? 'good' : '' ?>">
    <h4>🪔 Festival now</h4>
    <?php if ($fest['go']): ?><div class="big" style="font-size:18px"><?= $e($fest['go']['name']) ?></div><p>in <?= (int) $fest['go']['days'] ?> days (<?= $e($fest['go']['date']) ?>) — the AI is taking people HOME first.</p>
    <?php elseif ($fest['back']): ?><div class="big" style="font-size:18px"><?= $e($fest['back']['name']) ?> is over</div><p>The AI is bringing people BACK to Gujarat.</p>
    <?php else: ?><p>No festival in the next 20 days — return-trip, long-gone and enquiry groups get the budget.</p><?php endif; ?>
  </div>
</div>

<div class="am-honest">
  <b>पैसा बारे साँचो कुरा:</b> AiSensy को subscription (<?= $e($budget['plan'] ?: 'PRO') ?>) ले platform मात्र दिन्छ — <b>हरेक marketing message को Meta charge छुट्टै लाग्छ</b>
  (India ≈ ₹<?= $e(number_format((float) ($budget['rate'] ?? 0.88), 2)) ?>, Nepal ≈ ₹<?= $e(number_format((float) ($budget['rate_np'] ?? 6.45), 2)) ?> प्रति message), wallet बाट कट्छ। Unlimited होइन।
  त्यसैले AI ले wallet बाट पहिले ticket का लागि <?= $e($money($budget['reserve'] ?? 0)) ?> छुट्याउँछ, बाँकी पैसाले मात्र marketing गर्छ — wallet सकिएर ticket रोकिँदैन।
  उदाहरण: 1,000 customer लाई एक पटक = ≈ ₹<?= $e(number_format(1000 * (float) ($budget['rate'] ?? 0.88))) ?>।
  <br><b>Reply कहाँ आउँछ:</b> AiSensy number (+91 91570 01507) मा customer ले लेखेको reply हाम्रो system मा आउँदैन (webhook add-on किनिएको छैन) —
  AI chat, booking र STOP सबै <b>+91 87358 81507</b> मा चल्छ। AiSensy number मा STOP लेख्नेको अर्को message Meta/AiSensy ले रोकेर fail गराए
  system ले त्यो number आफैं block गर्छ; 📣 Campaigns बाट हातले पनि block गर्न सकिन्छ।
  <br><b>Customer को ticket र सूचना सधैं पहिला</b> — number को 24 घण्टा limit बाट 30 ठाउँ ticket का लागि राखिन्छ।
</div>

<div class="panel">
  <h2>⚙️ Switch &amp; limits</h2>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save">
    <fieldset <?= $mayAct ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
    <div class="am-modes">
      <label><input type="radio" name="mk_auto_mode" value="auto" <?= $mode === 'auto' ? 'checked' : '' ?>><span><b>Auto</b><br><small>AI chooses, writes and SENDS every day by itself.</small></span></label>
      <label><input type="radio" name="mk_auto_mode" value="draft" <?= $mode === 'draft' ? 'checked' : '' ?>><span><b>Draft</b><br><small>AI prepares the campaigns; you press Launch on 📣 Campaigns.</small></span></label>
      <label><input type="radio" name="mk_auto_mode" value="off" <?= $mode === 'off' ? 'checked' : '' ?>><span><b>Off</b><br><small>No AI marketing.</small></span></label>
    </div>
    <div class="am-form">
      <label>Max AI messages a day<input type="number" name="mk_auto_daily" min="0" max="1000" value="<?= Settings::getInt('mk_auto_daily', 25) ?>"></label>
      <label>Same person again after (days)<input type="number" name="mk_auto_gap_days" min="3" max="90" value="<?= Settings::getInt('mk_auto_gap_days', 10) ?>"></label>
      <label>Always keep in wallet for tickets (₹)<input type="number" name="mk_auto_reserve" min="0" value="<?= Settings::getInt('mk_auto_reserve', 25) ?>"></label>
      <label>Start sending at (hour)<input type="number" name="mk_auto_hour" min="8" max="20" value="<?= Settings::getInt('mk_auto_hour', 10) ?>"></label>
      <label>Language of the AI line<select name="mk_auto_lang"><option value="ne" <?= Settings::getString('mk_auto_lang', 'ne') !== 'hi' ? 'selected' : '' ?>>नेपाली</option><option value="hi" <?= Settings::getString('mk_auto_lang', 'ne') === 'hi' ? 'selected' : '' ?>>हिन्दी</option></select></label>
      <label>Link in the message (blank = website)<input name="mk_auto_link" value="<?= $e(Settings::getString('mk_auto_link', '')) ?>" placeholder="<?= $e(appUrl('/')) ?>"></label>
    </div>
    <label class="am-full">Your real offer (AI may mention ONLY this — leave blank if there is no offer)
      <input name="mk_auto_offer" maxlength="160" value="<?= $e(Settings::getString('mk_auto_offer', '')) ?>" placeholder="e.g. दशैंमा ४ जना सँगै बुक गरे ₹200 छुट"></label>
    <label class="am-full">Festivals (one per line: YYYY-MM-DD | name) — AI starts "go home" 20 days before, "come back" 2 days after
      <textarea name="mk_auto_festivals"><?= $e($festText) ?></textarea></label>
    <div class="am-form">
      <label class="am-check"><input type="checkbox" name="mk_auto_ai_copy" <?= Settings::getBool('mk_auto_ai_copy', true) ? 'checked' : '' ?>> AI writes the line (off = fixed line)</label>
      <label class="am-check"><input type="checkbox" name="mk_auto_nepal" <?= Settings::getBool('mk_auto_nepal', false) ? 'checked' : '' ?>> Also Nepal (+977) numbers (₹<?= $e(number_format((float) ($budget['rate_np'] ?? 6.45), 2)) ?> each)</label>
      <label class="am-check"><input type="checkbox" name="ai_learn_on" <?= Settings::getBool('ai_learn_on', true) ? 'checked' : '' ?>> AI learns from customer questions</label>
      <label class="am-check"><input type="checkbox" name="ai_learn_autopublish" <?= Settings::getBool('ai_learn_autopublish', false) ? 'checked' : '' ?>> Publish learned answers without my OK (asked 3+ times)</label>
    </div>
    <div class="am-btns"><button class="btn" type="submit">Save</button></div>
    </fieldset>
  </form>
  <?php if ($mayAct): ?>
  <div class="am-btns">
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="preview"><button class="btn ghost" type="submit">👀 Preview today's plan</button></form>
    <form method="post" onsubmit="return confirm('Queue today\'s AI marketing now?<?= $mode === 'auto' ? ' It will start SENDING (money from the AiSensy wallet).' : '' ?>')"><?= $csrf ?><input type="hidden" name="action" value="run"><button class="btn" type="submit">▶ Run now</button></form>
    <form method="post" onsubmit="return confirm('Switch the autopilot OFF and pause every AI campaign?')"><?= $csrf ?><input type="hidden" name="action" value="stop"><button class="btn ghost" type="submit" style="color:var(--bad,#dc2626)">⏹ Stop everything</button></form>
  </div>
  <?php endif; ?>
</div>

<?php if ($plan !== null): ?>
<div class="panel">
  <h2><?= $plan['dry'] ? '👀 Today\'s plan (preview)' : '✅ Queued now' ?></h2>
  <?php if ($plan['note'] !== ''): ?><p class="am-note" style="color:var(--bad,#dc2626)"><?= $e($plan['note']) ?></p><?php endif; ?>
  <div class="am-wrap"><table class="am-t">
    <tr><th>Group</th><th class="n">People who fit</th><th class="n">Will get it today</th><th class="n">Booking rate learned</th></tr>
    <?php foreach ($plan['groups'] as $g): ?>
      <tr><td><?= $e($g['label']) ?></td><td class="n"><?= (int) $g['people'] ?></td><td class="n"><b><?= (int) $g['send'] ?></b></td>
          <td class="n"><?= $e(number_format($g['score']['score'] * 100, 1)) ?>%<?= $g['score']['sent'] === 0 ? ' <small>(new)</small>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php foreach ($plan['campaigns'] as $c): ?>
    <h3 style="margin:14px 0 4px;font-size:14px"><?= $e(MkAutopilot::SEGMENTS[$c['segment']]) ?><?= $c['dir'] !== '' ? ' · ' . $e($c['dir'] === 'toNepal' ? 'घर जाने' : 'फर्कने') : '' ?> — <?= (int) $c['people'] ?> people
      <span class="am-by"><?= $e($c['by'] === 'fixed' ? 'fixed line' . ($c['why'] !== '' ? ' — ' . $c['why'] : '') : 'written by ' . $c['by']) ?></span>
      <?php if ($c['id'] > 0): ?> · <a href="/admin/campaigns.php?id=<?= (int) $c['id'] ?>">campaign #<?= (int) $c['id'] ?></a><?php endif; ?></h3>
    <div class="am-line">Namaste <?= $e(explode(' ', (string) ($c['sample'][0]['name'] ?? 'ग्राहक'))[0] ?: 'ग्राहक') ?>,
<?= $e($c['text']) ?>

Book your seat: <?= $e(appUrl('/')) ?>…

S Hari Global Pvt Ltd. Reply STOP to stop these messages.</div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2>🧠 What the AI has learned (last 90 days)</h2>
  <p class="am-note">Every AI message is matched with bookings made by the same number within 7 days. Groups that bring bookings get more of tomorrow's budget; the lines that sold are shown to the AI as examples.</p>
  <div class="am-wrap"><table class="am-t">
    <tr><th>Group</th><th class="n">Sent</th><th class="n">Reached</th><th class="n">Read</th><th class="n">Booked ≤7 d</th><th class="n">Score</th></tr>
    <?php foreach (MkAutopilot::SEGMENTS as $k => $lbl): $s = $scores[$k] ?? ['sent' => 0, 'reached' => 0, 'read' => 0, 'booked' => 0, 'score' => 0]; ?>
      <tr><td><?= $e($lbl) ?></td><td class="n"><?= (int) $s['sent'] ?></td><td class="n"><?= (int) $s['reached'] ?></td><td class="n"><?= (int) $s['read'] ?></td>
          <td class="n"><b><?= (int) $s['booked'] ?></b></td><td class="n"><?= $e(number_format((float) $s['score'] * 100, 1)) ?>%</td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($lessons['good'] !== []): ?><p class="am-note" style="margin-top:10px"><b>Lines that sold:</b></p><?php foreach ($lessons['good'] as $l): ?><div class="am-line"><?= $e($l) ?></div><?php endforeach; ?><?php endif; ?>
</div>

<div class="panel">
  <h2>📋 AI campaigns</h2>
  <?php if ($autoCamps === []): ?><p class="am-note">None yet — the first one is made at the next daily run (or press Run now).</p><?php else: ?>
  <div class="am-wrap"><table class="am-t">
    <tr><th>#</th><th>Campaign</th><th>Status</th><th class="n">People</th><th class="n">Sent</th><th class="n">Delivered</th><th class="n">Read</th><th class="n">Failed</th></tr>
    <?php foreach ($autoCamps as $c): ?>
      <tr><td><a href="/admin/campaigns.php?id=<?= (int) $c['id'] ?>"><?= (int) $c['id'] ?></a></td><td><?= $e($c['name']) ?><br><small style="color:var(--mut)"><?= $e(mb_substr((string) $c['message'], 0, 120)) ?></small></td>
          <td><?= $e($c['status']) ?></td><td class="n"><?= (int) $c['total'] ?></td><td class="n"><?= (int) $c['sent'] ?></td><td class="n"><?= (int) $c['delivered'] ?></td><td class="n"><?= (int) $c['read_n'] ?></td><td class="n"><?= (int) $c['failed'] ?></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
  <?php if ($hist !== []): ?>
    <p class="am-note" style="margin-top:12px"><b>Daily runs</b></p>
    <?php foreach (array_slice($hist, 0, 7) as $h): ?>
      <div class="am-q"><?= $e($h['at']) ?> · <?= $e(strtoupper((string) $h['mode'])) ?> · wallet <?= $e($money($h['wallet'] ?? null)) ?> · allowed <?= (int) $h['allowed'] ?> ·
        <?= (int) array_sum(array_column($h['campaigns'] ?? [], 'people')) ?> queued<?= ($h['note'] ?? '') !== '' ? ' — <small>' . $e($h['note']) . '</small>' : '' ?></div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>📚 AI ले सिकेको (questions → answers)</h2>
  <p class="am-note">Questions customers asked on WhatsApp / the website that had no written answer are collected. Every night after <?= Settings::getInt('ai_learn_hour', 2) ?>:00 the AI writes an answer from the company facts only (no fares or times — those stay live).
    Open: <b><?= $lc['open'] ?></b> · drafts waiting: <b><?= $lc['learned_draft'] ?></b> · learned &amp; live: <b><?= $lc['learned_live'] ?></b> · need your answer: <b><?= $lc['needs_owner'] ?></b>
    <?php if (($lhist[0]['at'] ?? '') !== ''): ?> · last study <?= $e($lhist[0]['at']) ?><?php endif; ?></p>
  <?php if ($mayAct): ?><form method="post" style="margin:0 0 10px"><?= $csrf ?><input type="hidden" name="action" value="learn"><button class="btn ghost" type="submit">🧠 Study the open questions now</button></form><?php endif; ?>
  <?php foreach ($learned as $a): ?>
    <div class="am-q">
      <b><?= $e($a['canonical_title']) ?></b> <span class="am-by"><?= $e($a['publication_status']) ?></span>
      <div class="am-line" style="font-size:13px"><?= $e($a['nepali_content'] !== '' ? $a['nepali_content'] : $a['canonical_answer']) ?></div>
      <small><?= $e($a['source_reference']) ?></small>
      <?php if ($mayAct): ?><div class="am-btns" style="margin-top:6px">
        <?php if ($a['publication_status'] !== 'published'): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="publish"><input type="hidden" name="aid" value="<?= (int) $a['id'] ?>"><button class="btn" type="submit">✅ Publish</button></form><?php endif; ?>
        <?php if ($a['publication_status'] !== 'archived'): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="archive"><input type="hidden" name="aid" value="<?= (int) $a['id'] ?>"><button class="btn ghost" type="submit">Archive</button></form><?php endif; ?>
        <a class="btn ghost" href="/admin/ai-knowledge.php?edit=<?= (int) $a['id'] ?>">Edit</a>
      </div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($needs !== []): ?>
    <p class="am-note" style="margin-top:12px"><b>The AI could not answer these from the facts — write the answer on <a href="/admin/ai-knowledge.php">📚 AI Knowledge</a>:</b></p>
    <?php foreach ($needs as $q): ?><div class="am-q"><?= $e($q['normalized_question']) ?> <small>× <?= (int) $q['frequency'] ?></small></div><?php endforeach; ?>
  <?php endif; ?>
</div>

<?php endif; ?>
<?php admin_footer(); ?>
