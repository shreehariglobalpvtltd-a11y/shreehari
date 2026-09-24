<?php
/**
 * admin/ai-copilot.php — SHG Sahayak, full-screen, for the office and the
 * agents (24 Sep 2026).
 *
 * Owner ask: "AI le sabai kaam garna sakos — report, graph, real-time data,
 * kati customer le visit gare, kati ticket bikri bhayo; Agent / Admin bata
 * pani; app bata pani." The floating widget on the public site already
 * talks to the same agent (api/ai-chat.php); this is the same conversation
 * with room for charts, on the panel's own theme, one click from the nav.
 *
 * Nothing here decides what the signed-in person may see: the endpoint
 * reads the session (AiTools::whoIsWeb) — an office role gets the whole
 * company (sales_report, site_visitors, occupancy_report, agent_leaderboard,
 * office_day, office_alerts …), a counter agent their own book only, and
 * every tool call lands in ai_agent_calls (see AI Activity).
 *
 * bookings.view: the lowest staff permission, because the server scopes
 * every answer anyway. Never sends the API key to the browser.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

$isOffice = !Auth::isCounterAgent() && (Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager');
$first    = trim((string) ($admin['full_name'] ?? ($admin['username'] ?? '')));
$first    = $first !== '' ? explode(' ', $first)[0] : '';

$hasKey  = Settings::getString('anthropic_api_key', '') !== '' || Settings::getString('gemini_api_key', '') !== '';
$agentOn = Settings::getBool('ai_web_agent_on', true) && $hasKey && function_exists('curl_init');

/* Quick prompts — what the office and an agent actually ask, in the
   language they type. Sent verbatim to the agent as if typed. */
$prompts = $isOffice
    ? [
        ['📊', 'आजको बिक्री', 'sales report today'],
        ['📈', 'यो हप्ताको ग्राफ', 'sales report this week by day with a graph'],
        ['🗓️', 'यो महिना रुट अनुसार', 'sales this month by route'],
        ['💳', 'Payment method split', 'sales this month by payment method'],
        ['👥', 'Website visitors', 'website visitors last 7 days'],
        ['🔴', 'अहिले साइटमा को छ?', 'how many people are on the website right now'],
        ['🚌', '७ दिनको occupancy', 'occupancy next 7 days'],
        ['🏆', 'Top agents', 'agent leaderboard this month'],
        ['⚠️', 'आज के मा ध्यान दिने?', 'what needs attention today'],
        ['🔎', 'Booking खोज्ने', 'find booking for '],
    ]
    : [
        ['📊', 'आज मेरो बिक्री', 'my sales today'],
        ['📈', 'यो हप्ताको ग्राफ', 'my sales this week by day with a graph'],
        ['🧑‍🤝‍🧑', 'आज मेरा यात्रु', 'my passengers today'],
        ['💰', 'वालेट र कमिसन', 'my wallet and commission'],
        ['🚌', '७ दिनको occupancy', 'occupancy next 7 days'],
        ['🎫', 'भोलि १ सिटको भाडा', 'quote 1 seat for tomorrow'],
    ];

admin_header('AI Sahayak', 'ai-copilot');
?>
<style>
  .cp-wrap{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:18px;align-items:start}
  @media(max-width:980px){.cp-wrap{grid-template-columns:1fr}}
  .cp-chat{display:flex;flex-direction:column;min-height:calc(100vh - var(--tb-h) - 120px);max-height:calc(100vh - var(--tb-h) - 60px);background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--sh-2);overflow:hidden}
  .cp-head{display:flex;align-items:center;gap:12px;padding:14px 18px;color:#fff;
    background:linear-gradient(110deg,#0C306C 0%,#0054A8 62%,#F07800 62.3%,#FFB703 100%)}
  .cp-head .cp-ava{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.18);display:inline-flex;align-items:center;justify-content:center;font-size:20px}
  .cp-head b{display:block;font-size:15.5px;line-height:1.2}
  .cp-head small{font-size:11.5px;opacity:.9}
  .cp-role{margin-left:auto;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
  .cp-msgs{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px;background:var(--soft)}
  :root[data-theme="dark"] .cp-msgs{background:#0B1628}
  .cp-msg{max-width:min(760px,92%);padding:11px 15px;border-radius:16px;font-size:14px;line-height:1.55;word-break:break-word;white-space:normal}
  .cp-msg.bot{align-self:flex-start;background:var(--card);border:1px solid var(--line);border-bottom-left-radius:5px;box-shadow:var(--sh-1)}
  .cp-msg.user{align-self:flex-end;background:linear-gradient(135deg,#0054A8,#0C306C);color:#fff;border-bottom-right-radius:5px}
  .cp-msg a{color:var(--orange-600);font-weight:700}
  .cp-msg ul{margin:6px 0 2px 18px;padding:0}
  .cp-msg.chart{width:min(760px,92%);padding:10px 12px 8px}
  .cp-chart-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;font-size:13px}
  .cp-chart-box{position:relative;height:300px}
  .cp-dl{background:var(--blue-50);border:1px solid var(--blue-100);color:var(--blue-600);padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}
  .cp-dl:hover{background:var(--blue-100)}
  .cp-acts{display:flex;flex-wrap:wrap;gap:8px}
  .cp-act{display:inline-flex;align-items:center;gap:6px;padding:8px 13px;border-radius:10px;background:linear-gradient(135deg,var(--orange),#FFB703);color:#fff!important;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 4px 12px rgba(240,120,0,.28)}
  .cp-media img{max-width:100%;border-radius:10px;border:1px solid var(--line)}
  .cp-typing{align-self:flex-start;display:flex;gap:5px;padding:10px 14px}
  .cp-typing span{width:8px;height:8px;border-radius:50%;background:var(--blue-100);animation:cpB 1.2s infinite}
  .cp-typing span:nth-child(2){animation-delay:.2s}.cp-typing span:nth-child(3){animation-delay:.4s}
  @keyframes cpB{0%,80%,100%{transform:translateY(0);opacity:.5}40%{transform:translateY(-5px);opacity:1}}
  .cp-in{display:flex;gap:8px;padding:12px 14px;border-top:1px solid var(--line);background:var(--card)}
  .cp-in textarea{flex:1;min-height:44px;max-height:140px;resize:vertical;border:1.5px solid var(--line);border-radius:12px;padding:10px 13px;font:inherit;font-size:14px;background:var(--soft);color:var(--ink)}
  .cp-in textarea:focus{outline:none;border-color:var(--blue)}
  .cp-send{flex:0 0 auto;padding:0 18px;border-radius:12px;border:0;background:linear-gradient(135deg,var(--orange),#FFB703);color:#fff;font-weight:800;font-size:14px;cursor:pointer;box-shadow:0 4px 12px rgba(240,120,0,.3)}
  .cp-send[disabled]{opacity:.55;cursor:default}
  .cp-lang{border:1px solid var(--line);border-radius:10px;padding:0 8px;background:var(--card);color:var(--ink);font-weight:700;font-size:13px}
  .cp-side .panel{margin-bottom:14px}
  .cp-q{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:9px 11px;border:1px solid var(--line);border-radius:11px;background:var(--card);color:var(--ink);font:inherit;font-size:13px;font-weight:600;cursor:pointer;margin-bottom:6px}
  .cp-q:hover{background:var(--blue-50);border-color:var(--blue-100)}
  .cp-q i{font-style:normal;width:26px;height:26px;border-radius:8px;background:var(--orange-50);display:inline-flex;align-items:center;justify-content:center}
  .cp-note{font-size:12.5px;color:var(--mut);line-height:1.5}
  .cp-off{padding:14px 16px;border-radius:12px;background:var(--warn-bg);color:var(--ink);font-size:13.5px;line-height:1.5}
</style>

<?php if (!$agentOn): ?>
  <div class="panel"><div class="cp-off">
    <b>🤖 AI Sahayak is not switched on yet.</b><br>
    <?php if (!$hasKey): ?>
      Add an API key in <a href="/admin/settings.php">Settings → ai</a> (<code>anthropic_api_key</code> or <code>gemini_api_key</code>)
      — the assistant then answers here, on the website chat and on WhatsApp (when <code>wa_agent_on</code> is 1).
    <?php else: ?>
      <code>ai_web_agent_on</code> is 0 in <a href="/admin/settings.php">Settings → ai</a>. Set it to 1 to use the assistant here and in the website chat.
    <?php endif; ?>
    <br><span class="cp-note">Migration: <code>database/upgrade-2026-09-24-ai-sahayak-pro.sql</code>. Doc: <code>docs/UPGRADE-2026-09-24-ai-sahayak-pro.md</code>.</span>
  </div></div>
<?php endif; ?>

<div class="cp-wrap">
  <section class="cp-chat" id="cp">
    <div class="cp-head">
      <span class="cp-ava">🤖</span>
      <div><b>SHG Sahayak · AI</b><small><?= $isOffice ? 'Office copilot — reports, graphs, live traffic, any booking' : 'Your own sales, passengers, wallet and fare quotes' ?></small></div>
      <span class="cp-role"><?= $isOffice ? 'Office' : 'Agent' ?></span>
    </div>
    <div class="cp-msgs" id="cpMsgs"></div>
    <div class="cp-in">
      <select class="cp-lang" id="cpLang" aria-label="Language">
        <option value="ne">नेपाली</option><option value="hi">हिंदी</option><option value="en">EN</option>
      </select>
      <textarea id="cpIn" rows="1" placeholder="आजको बिक्री कति? · इस हफ्ते का ग्राफ · how many visitors today?" <?= $agentOn ? '' : 'disabled' ?>></textarea>
      <button type="button" class="cp-send" id="cpSend" <?= $agentOn ? '' : 'disabled' ?>>Send ➤</button>
    </div>
  </section>

  <aside class="cp-side">
    <div class="panel">
      <h2>Quick questions</h2>
      <?php foreach ($prompts as $q): ?>
        <button type="button" class="cp-q" data-q="<?= Security::e($q[2]) ?>"><i><?= $q[0] ?></i><?= Security::e($q[1]) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="panel">
      <h2>How it works</h2>
      <p class="cp-note">
        The assistant reads the live register through the same functions the desk uses — it never guesses a number.
        <?= $isOffice ? 'You see the whole company; a counter agent asking the same question sees only their own book.' : 'You see your own sales, passengers and wallet — nobody else\'s.' ?>
        Every action is logged in <a href="/admin/ai-activity.php">AI Activity</a>. Write Nepali, Hindi or English; charts appear under the answer and download as PNG.
        Type <code>reset</code> to start a fresh conversation.
      </p>
      <p class="cp-note">Same assistant on the website chat 🤖 and on WhatsApp (<code>wa_agent_on</code>).</p>
    </div>
  </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
(function () {
  var msgs = document.getElementById('cpMsgs'), input = document.getElementById('cpIn'), sendBtn = document.getElementById('cpSend'), langSel = document.getElementById('cpLang');
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var ON = <?= $agentOn ? 'true' : 'false' ?>;
  var COLORS = ['#0054A8', '#F07800', '#138808', '#DC143C', '#5B4FA8', '#FFB703'];
  try { var sl = localStorage.getItem('shg_admin_cp_lang'); if (sl) langSel.value = sl; } catch (e) {}
  langSel.addEventListener('change', function () { try { localStorage.setItem('shg_admin_cp_lang', langSel.value); } catch (e) {} });

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function linkify(e) {
    return e.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>')
            .replace(/(^|[\s(])(#\/[a-z][a-z0-9\/-]*)/gi, '$1<a href="/$2" target="_blank" rel="noopener">$2</a>');
  }
  function md(text) {
    var out = '', inList = false;
    String(text || '').split('\n').forEach(function (ln) {
      var e = linkify(esc(ln)).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
      var m = e.match(/^\s*(?:[-*•]|\d+[.)])\s+(.*)$/);
      if (m) { if (!inList) { out += '<ul>'; inList = true; } out += '<li>' + m[1] + '</li>'; }
      else { if (inList) { out += '</ul>'; inList = false; } out += (out && !/<\/ul>$/.test(out) ? '<br>' : '') + e; }
    });
    if (inList) out += '</ul>';
    return out.replace(/^(<br>)+/, '');
  }
  function add(html, who, cls) {
    var d = document.createElement('div');
    d.className = 'cp-msg ' + who + (cls ? ' ' + cls : '');
    if (who === 'user') d.textContent = html; else d.innerHTML = html;
    msgs.appendChild(d); msgs.scrollTop = msgs.scrollHeight;
    return d;
  }
  function fmt(v, f) {
    if (f === 'money') return '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    if (f === 'percent') return Math.round(Number(v)) + '%';
    return Number(v).toLocaleString('en-IN');
  }
  function chart(spec) {
    if (!spec || !spec.labels || !spec.series || !spec.series.length) return;
    var d = add('<div class="cp-chart-head"><b>📊 ' + esc(spec.title || 'Chart') + '</b><button type="button" class="cp-dl">⬇ PNG</button></div><div class="cp-chart-box"><canvas></canvas></div>', 'bot', 'chart');
    var tries = 0;
    (function draw() {
      if (typeof Chart === 'undefined') { if (tries++ < 50) return setTimeout(draw, 100); d.querySelector('.cp-chart-box').textContent = 'Chart library did not load.'; return; }
      var dark = document.documentElement.getAttribute('data-theme') === 'dark', ink = dark ? '#c7d2e5' : '#4a5568';
      var dough = spec.type === 'doughnut';
      var ds = spec.series.map(function (sr, i) {
        var c = COLORS[i % COLORS.length], line = spec.type === 'line' || sr.axis === 'y2';
        return { label: sr.name || ('Series ' + (i + 1)), data: (sr.data || []).map(Number), type: dough ? undefined : (line ? 'line' : 'bar'),
          backgroundColor: dough ? COLORS : (line ? c : c + 'CC'), borderColor: dough ? '#fff' : c, borderWidth: dough ? 2 : (line ? 3 : 0),
          borderRadius: line ? 0 : 5, maxBarThickness: 30, tension: .3, pointRadius: 3, yAxisID: sr.axis === 'y2' ? 'y2' : 'y', order: line ? 0 : 1, _f: sr.format || spec.format || 'count' };
      });
      var scales = dough ? {} : {
        x: { grid: { display: false }, ticks: { color: ink, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
        y: { beginAtZero: true, max: spec.max || undefined, ticks: { color: ink, callback: function (v) { return fmt(v, ds[0]._f); } }, grid: { color: dark ? '#22314A' : '#E2E9F4' } }
      };
      if (ds.some(function (x) { return x.yAxisID === 'y2'; })) scales.y2 = { position: 'right', beginAtZero: true, ticks: { color: ink, precision: 0 }, grid: { drawOnChartArea: false } };
      var ch = new Chart(d.querySelector('canvas'), { type: dough ? 'doughnut' : 'bar', data: { labels: spec.labels, datasets: ds },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: ds.length > 1 || dough, position: 'bottom', labels: { color: ink, boxWidth: 12 } },
          tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + fmt(dough ? c.parsed : c.parsed.y, c.dataset._f); } } } }, scales: scales, cutout: dough ? '58%' : undefined } });
      d.querySelector('.cp-dl').onclick = function () { var a = document.createElement('a'); a.download = 'SHG-' + String(spec.title || 'chart').replace(/[^\wऀ-ॿ]+/g, '-').slice(0, 60) + '.png'; a.href = ch.toBase64Image('image/png', 1); a.click(); };
    })();
  }
  function actions(list) {
    if (!list || !list.length) return;
    var html = list.slice(0, 4).map(function (a) {
      var h = String(a.href || '');
      if (!/^(#\/|https?:\/\/|\/admin\/|tel:)/i.test(h)) return '';
      if (h.charAt(0) === '#') h = '/' + h;
      return '<a class="cp-act" href="' + esc(h) + '" target="_blank" rel="noopener">' + esc(a.label || h) + '</a>';
    }).join('');
    if (html) add('<div class="cp-acts">' + html + '</div>', 'bot');
  }
  var busy = false;
  function typing() { var t = document.createElement('div'); t.className = 'cp-typing'; t.innerHTML = '<span></span><span></span><span></span>'; msgs.appendChild(t); msgs.scrollTop = msgs.scrollHeight; return t; }
  function send(text) {
    var q = String(text == null ? input.value : text).trim();
    if (!q || busy || !ON) return;
    input.value = ''; busy = true; sendBtn.disabled = true;
    add(q, 'user');
    var t = typing();
    fetch('/api/ai-chat.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ message: q, lang: langSel.value }) })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (x) {
        t.remove();
        var d = x.j && x.j.data;
        if (!x.j || !x.j.ok || !d) { add('⚠️ ' + esc((x.j && x.j.error) || ('HTTP ' + x.s)), 'bot'); return; }
        if (d.reset) { add('🙏 ठिक छ, नयाँ बाट सुरु गरौँ।', 'bot'); return; }
        add(md(d.text), 'bot');
        (d.charts || []).forEach(chart);
        if (d.media && /^https?:\/\//.test(d.media)) add('<a class="cp-media" href="' + esc(d.media) + '" target="_blank" rel="noopener"><img src="' + esc(d.media) + '" alt=""></a>', 'bot');
        actions(d.actions);
      })
      .catch(function () { t.remove(); add('⚠️ नेटवर्क समस्या — फेरि प्रयास गर्नुहोस्। · Network problem, try again.', 'bot'); })
      .then(function () { busy = false; sendBtn.disabled = false; input.focus(); });
  }
  sendBtn.addEventListener('click', function () { send(); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
  document.querySelectorAll('.cp-q').forEach(function (b) {
    b.addEventListener('click', function () {
      var q = b.getAttribute('data-q') || '';
      if (/\s$/.test(q)) { input.value = q; input.focus(); return; }   // needs a value typed after it
      send(q);
    });
  });
  add(<?= json_encode('🙏 नमस्ते' . ($first !== '' ? ' ' . $first : '') . '! ' . ($isOffice
        ? 'आजको बिक्री, हप्ताको ग्राफ, लाइभ भिजिटर, बस कति भरियो, टप एजेन्ट वा कुनै पनि बुकिङ — सोध्नुहोस्। Hindi / English पनि हुन्छ।'
        : 'आज तपाईंको बिक्री, तपाईंका यात्रु, वालेट, वा कुनै यात्रुको भाडा — सोध्नुहोस्। Hindi / English पनि हुन्छ।'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, 'bot');
  if (ON) input.focus();
})();
</script>
<?php admin_footer(); ?>
