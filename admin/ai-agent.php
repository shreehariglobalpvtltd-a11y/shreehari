<?php
/**
 * admin/ai-agent.php — ask the office assistant, from the panel.
 *
 * The same assistant the office already has on WhatsApp, at the desk.
 * It reads the company's own live data through the tools in
 * includes/aitools.php — the day's sales, a booking, what needs
 * attention — and every button it presses is recorded in
 * `ai_agent_calls`, visible on AI Activity with channel 'panel'.
 *
 * This screen only carries messages. What the assistant MAY do is
 * decided entirely by AiTools::catalogue() from the signed-in account's
 * role, not by anything typed here, so a counter agent sees their own
 * book and a manager sees the office — exactly as on a phone.
 *
 * Office only (dashboard.view). The account must also survive
 * AiTools::whoIsAdmin(): active, past its temporary password, unlocked,
 * and one of the four roles the assistant has always accepted.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aiagent.php';
// AiAgent only pulls AiTools in when it answers; this page asks whoIsAdmin()
// before a single message is sent, so it loads the class itself.
require_once INCLUDE_PATH . '/aitools.php';
$admin = admin_boot('dashboard.view');

$adminId = (int) ($admin['id'] ?? 0);
$on      = AiAgent::panelEnabled();
$me      = AiTools::whoIsAdmin($adminId);
$allowed = $me['role'] !== 'customer';

admin_header('AI Assistant', 'ai-agent');
?>
<style>
  .ag-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .ag-wrap{display:flex;flex-direction:column;height:min(62vh,620px)}
  .ag-msgs{flex:1;overflow-y:auto;padding:12px;border:1px solid var(--line);border-radius:12px;
           background:var(--card);display:flex;flex-direction:column;gap:10px}
  .ag-b{max-width:86%;padding:9px 13px;border-radius:14px;font-size:14px;line-height:1.55;
        white-space:pre-wrap;word-wrap:break-word}
  .ag-me{align-self:flex-end;background:var(--acc,#2563eb);color:#fff;border-bottom-right-radius:4px}
  .ag-ai{align-self:flex-start;border:1px solid var(--line);border-bottom-left-radius:4px}
  .ag-err{align-self:flex-start;border:1px solid var(--bad);color:var(--bad);border-radius:10px}
  .ag-b img{display:block;max-width:100%;border-radius:10px;margin-top:8px}
  .ag-form{display:flex;gap:8px;margin-top:10px}
  .ag-form textarea{flex:1;min-height:46px;max-height:140px;resize:vertical;padding:11px 13px;
                    border:1px solid var(--line);border-radius:10px;background:var(--card);
                    color:inherit;font:inherit}
  .ag-chips{display:flex;gap:7px;flex-wrap:wrap;margin:10px 0 0}
  .ag-chip{font-size:12.5px;padding:6px 11px;border:1px solid var(--line);border-radius:999px;
           background:var(--card);cursor:pointer}
  .ag-chip:hover{border-color:var(--acc,#2563eb)}
  .ag-ms{font-size:11.5px;color:var(--mut);margin-top:3px}
</style>

<?php if (!$allowed): ?>
  <div class="panel">
    <p class="ag-note" style="color:var(--bad)">Your account may sign in to the panel, but it is not one the
      assistant accepts — it is either suspended, still on its first temporary password, locked, or holds a role
      outside agent / counter / manager / superadmin. Change your password or ask a superadmin to check the account,
      then come back.</p>
  </div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<?php if (!$on): ?>
  <div class="panel">
    <p class="ag-note">The AI assistant is switched <b>off</b>. Turn it on in
      <a href="/admin/settings.php">Settings → Ai</a>:</p>
    <ul class="ag-note">
      <li><code>ai_panel_on</code> — the master switch for this page.</li>
      <li><code>anthropic_api_key</code> or <code>gemini_api_key</code> — at least one must be filled in,
        otherwise there is no brain to ask.</li>
    </ul>
    <p class="ag-note">Nothing else on the site changes while this is off.</p>
  </div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<div class="panel">
  <p class="ag-note">Ask about the day's sales, a booking, how full a bus is, or what needs attention. The assistant
    reads the company's own live data — it does not guess. Every action it takes is listed on
    <a href="/admin/ai-activity.php">AI Activity</a>. Type <b>reset</b> to start the conversation fresh.</p>

  <div class="ag-wrap">
    <div class="ag-msgs" id="agMsgs" aria-live="polite"></div>
    <form class="ag-form" id="agForm">
      <textarea id="agInput" placeholder="aaja kasto cha?" autocomplete="off"
                aria-label="Ask the assistant"></textarea>
      <button class="btn" type="submit" id="agSend">Ask</button>
      <button class="btn secondary" type="button" id="agReset" title="Forget this conversation">Clear</button>
    </form>
    <div class="ag-chips">
      <span class="ag-chip">aaja kasto cha?</span>
      <span class="ag-chip">Today's sales</span>
      <span class="ag-chip">What needs attention?</span>
    </div>
  </div>
</div>

<script>
(function () {
  var msgs  = document.getElementById('agMsgs');
  var form  = document.getElementById('agForm');
  var input = document.getElementById('agInput');
  var send  = document.getElementById('agSend');
  var csrf  = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var busy  = false;

  /* Text goes in as a TEXT NODE, never innerHTML: a tool result can carry
     a passenger's own name, and the panel must not be the one place that
     renders it as markup. CSS white-space:pre-wrap keeps the line breaks. */
  function bubble(text, cls, media, ms) {
    var d = document.createElement('div');
    d.className = 'ag-b ' + cls;
    d.appendChild(document.createTextNode(text));
    if (media && /^(https:\/\/|\/)/.test(media)) {
      var img = document.createElement('img');
      img.src = media;
      img.alt = 'chart';
      d.appendChild(img);
    }
    if (ms) {
      var s = document.createElement('div');
      s.className = 'ag-ms';
      s.appendChild(document.createTextNode(ms + ' ms'));
      d.appendChild(s);
    }
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  }

  function post(body) {
    return fetch('/admin/api/ai-agent.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function ask(text) {
    if (busy || !text) { return; }
    busy = true; send.disabled = true;
    bubble(text, 'ag-me');
    var wait = bubble('…', 'ag-ai');

    post({ message: text }).then(function (d) {
      wait.remove();
      if (d && d.ok) { bubble(d.text, 'ag-ai', d.media, d.ms); }
      else { bubble((d && d.error) || 'No answer — try again.', 'ag-err'); }
    }).catch(function () {
      wait.remove();
      bubble('Could not reach the assistant. Check your connection and try again.', 'ag-err');
    }).then(function () {
      busy = false; send.disabled = false; input.focus();
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = input.value.trim();
    if (!t) { return; }
    input.value = '';
    ask(t);
  });

  // Enter sends, Shift+Enter makes a new line — what everyone expects of a chat box.
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
  });

  document.getElementById('agReset').addEventListener('click', function () {
    if (busy) { return; }
    post({ action: 'reset' }).then(function () {
      msgs.innerHTML = '';
      bubble('Conversation cleared.', 'ag-ai');
      input.focus();
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.ag-chip'), function (c) {
    c.addEventListener('click', function () { ask(c.textContent.trim()); });
  });

  bubble('Namaste ' + <?= json_encode($me['name'] !== '' ? $me['name'] : 'ji', JSON_UNESCAPED_UNICODE) ?> +
         ' — ask me anything about the office.', 'ag-ai');
  input.focus();
})();
</script>

<?php admin_footer(); ?>
