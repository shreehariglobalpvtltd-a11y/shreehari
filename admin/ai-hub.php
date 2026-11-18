<?php
/**
 * admin/ai-hub.php — AI Command Center · एआई कमाण्ड सेन्टर (19 Nov 2026).
 *
 * One live picture of the whole pipeline: WhatsApp / Facebook / website / desk come in,
 * S Hari AI routes each turn down one ladder (cloud brains first, the VPS model as the
 * last-resort backup), and the picture shows what came out (tickets, replies sent,
 * failed sends, hand-offs to a person). Engine health cards each have a live Test
 * button. A live feed (no passenger words, numbers masked) and the marketing tile
 * refresh every 15 s from ?json=1.
 *
 * Read-only apart from the Test buttons (Settings permission, CSRF, audited; a test is
 * a 5-token question or a Graph GET — nothing reaches a customer).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aihub.php';

$admin   = admin_boot('dashboard.view');
$canTest = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['act'] ?? '') === 'ping') {
    header('Content-Type: application/json; charset=utf-8');
    if (!Security::verifyCsrf()) {
        echo json_encode(['ok' => false, 'detail' => 'Session expired · फेरि खोल्नुहोस्']);
        exit;
    }
    if (!$canTest) {
        echo json_encode(['ok' => false, 'detail' => 'Settings permission चाहिन्छ · needs the Settings permission']);
        exit;
    }
    $engine = (string) ($_POST['engine'] ?? '');
    if (!in_array($engine, ['claude', 'gemini', 'local', 'whatsapp', 'facebook'], true)) {
        echo json_encode(['ok' => false, 'detail' => 'unknown engine']);
        exit;
    }
    $r = AiHub::ping($engine);
    Logger::audit('ai.hub_test', 'settings', $engine, null, ['ok' => $r['ok'], 'ms' => $r['ms']], 'AI Command Center test');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$hours = in_array((int) ($_GET['h'] ?? 24), [1, 6, 24, 168, 720], true) ? (int) ($_GET['h'] ?? 24) : 24;

if ((string) ($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['at' => date('H:i:s'), 'status' => AiHub::status(), 'flow' => AiHub::flow($hours), 'feed' => AiHub::feed(30),
        'mk' => AiHub::marketing(5)], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = AiHub::status();
$flow   = AiHub::flow($hours);
$feed   = AiHub::feed(30);
$mk     = AiHub::marketing(5);

admin_header('AI Command Center · एआई कमाण्ड सेन्टर', 'ai-hub');
?>
<style>
.hub{--hb-bg:#07122b;--hb-card:#0d1d42;--hb-line:#1d3366;--hb-ink:#e8eefc;--hb-mute:#8fa3cf;--hb-ok:#2ee59d;--hb-bad:#ff5d6c;--hb-warn:#ffc23d;--hb-acc:#f07a1e;--hb-blue:#4da3ff;
  background:radial-gradient(1200px 500px at 50% -10%,#163a85 0%,var(--hb-bg) 60%);color:var(--hb-ink);border-radius:18px;padding:18px;margin:0 0 18px;overflow:hidden}
.hub h2{margin:0 0 4px;font-size:1.25rem;letter-spacing:.3px}.hub .sub{color:var(--hb-mute);font-size:.85rem;margin:0 0 12px}
.hub-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}
.hub-range a{display:inline-block;padding:5px 10px;border-radius:999px;border:1px solid var(--hb-line);color:var(--hb-mute);text-decoration:none;font-size:.8rem;margin:2px}
.hub-range a.on{background:var(--hb-acc);border-color:var(--hb-acc);color:#fff}
.hub-live{font-size:.78rem;color:var(--hb-mute)}.hub-live i{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--hb-ok);margin-right:6px;animation:hbp 1.6s infinite}
@keyframes hbp{0%{box-shadow:0 0 0 0 rgba(46,229,157,.7)}100%{box-shadow:0 0 0 10px rgba(46,229,157,0)}}
.hub-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:6px -6px 4px;padding:0 6px}
.hub-svg{width:100%;min-width:720px;height:auto;display:block}
.hub-swipe{display:none;font-size:.72rem;color:var(--hb-mute)}@media (max-width:760px){.hub-swipe{display:block}}
.hub-svg .lnk{fill:none;stroke-linecap:round;opacity:.9}
.hub-svg .flowing{stroke-dasharray:6 14;animation:hbf 1.4s linear infinite}
@keyframes hbf{to{stroke-dashoffset:-40}}
.hub-svg .node rect{fill:var(--hb-card);stroke:var(--hb-line);stroke-width:1.5}
.hub-svg .node text{fill:var(--hb-ink);font:600 13px Poppins,system-ui,sans-serif}
.hub-svg .node .n{font:700 18px Poppins,system-ui,sans-serif;fill:#fff}
.hub-svg .node .s{font:500 10.5px Poppins,system-ui,sans-serif;fill:var(--hb-mute)}
.hub-svg .core circle.ring{fill:none;stroke:var(--hb-acc);stroke-width:2;animation:hbr 3s ease-out infinite;transform-origin:center;transform-box:fill-box}
@keyframes hbr{0%{opacity:.9;transform:scale(1)}100%{opacity:0;transform:scale(1.5)}}
.hub-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;margin-top:10px}
.hub-card{background:var(--hb-card);border:1px solid var(--hb-line);border-radius:14px;padding:12px}
.hub-card h3{margin:0;font-size:.95rem;display:flex;align-items:center;gap:8px}
.hub-card .dot{width:10px;height:10px;border-radius:50%;background:var(--hb-mute);flex:none}
.hub-card .dot.ok{background:var(--hb-ok);box-shadow:0 0 10px var(--hb-ok)}.hub-card .dot.bad{background:var(--hb-bad);box-shadow:0 0 10px var(--hb-bad)}
.hub-card .meta{color:var(--hb-mute);font-size:.78rem;margin:4px 0 8px;min-height:2.2em}
.hub-card .big{font-size:1.05rem;font-weight:700}.hub-card .hint{font-size:.76rem;color:var(--hb-warn);margin-top:6px}
.hub-card button{background:transparent;border:1px solid var(--hb-blue);color:var(--hb-blue);border-radius:8px;padding:5px 10px;font-size:.78rem;cursor:pointer;min-height:32px}
.hub-card button:disabled{opacity:.5;cursor:wait}.hub-card .res{font-size:.76rem;margin-top:6px;word-break:break-word}
.hub-grid{display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-top:12px}
@media (max-width:860px){.hub-grid{grid-template-columns:1fr}}
.hub-feed{max-height:420px;overflow:auto;background:var(--hb-card);border:1px solid var(--hb-line);border-radius:14px;padding:6px 10px}
.hub-feed div{display:flex;gap:8px;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.8rem;animation:hbin .5s ease}
.hub-feed time{color:var(--hb-mute);flex:none;width:44px}.hub-feed .x{color:var(--hb-bad)}
@keyframes hbin{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.hub-links a{display:block;background:var(--hb-card);border:1px solid var(--hb-line);border-radius:12px;padding:10px 12px;color:var(--hb-ink);text-decoration:none;margin-bottom:8px;font-size:.86rem}
.hub-links a:hover{border-color:var(--hb-acc)}
.hub-mk td,.hub-mk th{padding:4px 6px;font-size:.78rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.06)}.hub-mk{width:100%;border-collapse:collapse}
@media (prefers-reduced-motion:reduce){.hub *{animation:none!important}}
</style>

<div class="hub" id="hub">
  <div class="hub-top">
    <div>
      <h2>🛰 एआई कमाण्ड सेन्टर · AI Command Center</h2>
      <p class="sub">सबै च्यानल → एउटै S Hari AI → Claude · Gemini · Local AI → टिकट / जवाफ · All channels, one pipeline, live</p>
    </div>
    <div>
      <div class="hub-range"><?php foreach ([1 => '1 घण्टा · 1h', 6 => '6h', 24 => 'आज · 24h', 168 => '7 दिन', 720 => '30 दिन'] as $h => $l): ?><a href="?h=<?= $h ?>" class="<?= $h === $hours ? 'on' : '' ?>"><?= $e($l) ?></a><?php endforeach; ?></div>
      <div class="hub-live"><i></i>Live · <span id="hubAt"><?= $e(date('H:i:s')) ?></span> · हरेक 15 s</div>
    </div>
  </div>

  <div class="hub-swipe">↔ चित्र सार्नुहोस् · swipe the picture</div>
  <div class="hub-wrap"><svg class="hub-svg" id="hubSvg" viewBox="0 0 1000 430" role="img" aria-label="AI pipeline flow"></svg></div>

  <div class="hub-cards" id="hubCards"></div>
</div>

<div class="hub-grid">
  <div>
    <h3 style="margin:0 0 8px">⚡ लाइभ गतिविधि · Live activity</h3>
    <div class="hub-feed" id="hubFeed"></div>
  </div>
  <div>
    <h3 style="margin:0 0 8px">🧭 एकै ठाउँबाट · One place</h3>
    <div class="hub-links">
      <a href="ai-copilot.php">🗣 S Hari AI सँग कुरा · Talk to the AI (staff)</a>
      <a href="wa-inbox.php">💬 WhatsApp इनबक्स · WhatsApp inbox</a>
      <a href="messenger-inbox.php">📘 मेसेन्जर इनबक्स · Messenger inbox</a>
      <a href="campaigns.php">📣 मार्केटिङ · Campaigns</a>
      <a href="wa-templates.php">🧾 Meta templates</a>
      <a href="ai-local.php">🖥 Local AI (VPS)</a>
      <a href="ai-center.php">💰 AI खर्च · AI spend &amp; limits</a>
    </div>
    <h3 style="margin:12px 0 6px">📣 मार्केटिङ · Marketing</h3>
    <table class="hub-mk" id="hubMk"></table>
  </div>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(Security::csrfToken()) ?>, CAN = <?= $canTest ? 'true' : 'false' ?>, H = <?= (int) $hours ?>;
  var data = <?= json_encode(['status' => $status, 'flow' => $flow, 'feed' => $feed, 'mk' => $mk], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var NS = 'http://www.w3.org/2000/svg';
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function el(tag, attrs, parent) { var n = document.createElementNS(NS, tag); for (var k in attrs) n.setAttribute(k, attrs[k]); if (parent) parent.appendChild(n); return n; }
  function txt(parent, x, y, s, cls, anchor) { var t = el('text', {x: x, y: y, 'class': cls || '', 'text-anchor': anchor || 'start'}, parent); t.textContent = s; return t; }
  function w(n) { return n > 0 ? Math.min(14, 2 + Math.log(1 + n) * 2.2) : 1; }
  function col(ok) { return ok === true ? '#2ee59d' : (ok === false ? '#ff5d6c' : '#8fa3cf'); }

  function draw() {
    var svg = document.getElementById('hubSvg'); while (svg.firstChild) svg.removeChild(svg.firstChild);
    var f = data.flow, st = data.status, c = f.channels || {}, b = f.brains || {}, o = f.outcomes || {};
    var defs = el('defs', {}, svg), g = el('radialGradient', {id: 'hbCore'}, defs);
    el('stop', {offset: '0%', 'stop-color': '#f07a1e'}, g); el('stop', {offset: '100%', 'stop-color': '#7a2d00'}, g);
    var CX = 500, CY = 215;
    var left = [['whatsapp', '💬 WhatsApp', c.whatsapp, 'सन्देश आए · in'], ['facebook', '📘 Facebook', c.facebook, 'Messenger in'],
                ['web', '🌐 Website', c.web, 'app + AI chat'], ['desk', '🏪 Counter / Agent', c.desk, 'टिकट काटे · sold']];
    var brains = [['anthropic', '🧠 Claude', st.claude], ['gemini', '✨ Gemini', st.gemini], ['local', '🖥 Local AI', st.local]];
    var outs = [['🎫 टिकट · Tickets', o.tickets, true], ['✅ पठाइयो · Sent', o.sent, true], ['⛔ असफल · Failed', o.failed, false], ['🙋 मान्छे · To a person', o.human, null]];
    function node(x, y, wd, title, n, sub, ok) {
      var gr = el('g', {'class': 'node'}, svg);
      el('rect', {x: x, y: y, width: wd, height: 62, rx: 14}, gr);
      el('circle', {cx: x + 14, cy: y + 15, r: 5, fill: col(ok)}, gr);
      txt(gr, x + 26, y + 20, title); txt(gr, x + 14, y + 46, n == null ? '—' : String(n), 'n'); txt(gr, x + wd - 10, y + 46, sub || '', 's', 'end');
      return gr;
    }
    function link(x1, y1, x2, y2, n, color) {
      var mx = (x1 + x2) / 2, d = 'M' + x1 + ',' + y1 + ' C' + mx + ',' + y1 + ' ' + mx + ',' + y2 + ' ' + x2 + ',' + y2;
      el('path', {d: d, 'class': 'lnk', stroke: color, 'stroke-opacity': .22, 'stroke-width': w(n)}, svg);
      if (n > 0) el('path', {d: d, 'class': 'lnk flowing', stroke: color, 'stroke-width': Math.max(2, w(n) / 2)}, svg);
    }
    left.forEach(function (r, i) { var y = 22 + i * 102; link(212, y + 31, CX - 70, CY, r[2] || 0, '#4da3ff'); });
    brains.forEach(function (r, i) { var y = 10 + i * 74, bb = b[r[0]] || {n: 0, ok: 0}; link(CX + 70, CY, 760, y + 31, bb.n, r[2] && r[2].ok === false ? '#ff5d6c' : '#f07a1e'); });
    outs.forEach(function (r, i) { var y = 238 + i * 48; link(CX + 70, CY, 760, y + 22, r[1] || 0, r[2] === false ? '#ff5d6c' : '#2ee59d'); });
    left.forEach(function (r, i) { node(12, 22 + i * 102, 200, r[1], r[2] || 0, r[3], (r[0] === 'whatsapp' ? st.whatsapp.ok : (r[0] === 'facebook' ? st.facebook.ok : null))); });
    brains.forEach(function (r, i) { var bb = b[r[0]] || {n: 0, ok: 0}; node(760, 10 + i * 74, 228, r[1], bb.n, (r[2] && r[2].model) ? r[2].model : '', r[2] ? r[2].ok : null); });
    outs.forEach(function (r, i) {
      var y = 238 + i * 48, gr = el('g', {'class': 'node'}, svg);
      el('rect', {x: 760, y: y, width: 228, height: 44, rx: 12}, gr); txt(gr, 774, y + 27, r[0]); txt(gr, 976, y + 29, String(r[1] || 0), 'n', 'end');
    });
    var core = el('g', {'class': 'core'}, svg);
    el('circle', {cx: CX, cy: CY, r: 74, 'class': 'ring'}, core);
    el('circle', {cx: CX, cy: CY, r: 70, fill: 'url(#hbCore)', stroke: '#ffb36b', 'stroke-width': 2}, core);
    var t1 = txt(core, CX, CY - 8, 'S HARI AI', '', 'middle'); t1.setAttribute('style', 'font:800 17px Poppins,system-ui;fill:#fff;letter-spacing:1px');
    var t2 = txt(core, CX, CY + 14, 'एउटै pipeline · one line', '', 'middle'); t2.setAttribute('style', 'font:500 10.5px Poppins,system-ui;fill:#ffe3cc');
    var turns = 0; for (var k in (f.routers || {})) turns += f.routers[k];
    var t3 = txt(core, CX, CY + 34, turns + ' AI turns', '', 'middle'); t3.setAttribute('style', 'font:700 12px Poppins,system-ui;fill:#fff');
  }

  function cards() {
    var st = data.status, box = document.getElementById('hubCards'), html = '';
    var order = [['claude', function (s) { return s.calls + ' calls · ' + s.ms + ' ms · $' + (s.usd || 0).toFixed(3); }],
                 ['gemini', function (s) { return s.calls + ' calls · ' + s.ms + ' ms'; }],
                 ['local', function (s) { return (s.model || 'no model') + ' · ' + (s.backup ? 'backup ON' : 'backup OFF') + (s.replace ? ' · replaces cloud' : '') + (s.error ? ' · ' + s.error : ''); }],
                 ['whatsapp', function (s) { return 'पठाइयो ' + s.sent + ' · असफल ' + s.failed + ' · in ' + s.inbound; }],
                 ['facebook', function (s) { return 'in ' + s.inbound + ' · sent ' + s.sent + ' · failed ' + s.failed + (s.on ? '' : ' · OFF'); }],
                 ['aisensy', function (s) { return 'mode ' + s.mode + ' · 24h hits ' + s.calls + ' · thread blocks ' + s.threads; }]];
    order.forEach(function (o) {
      var s = st[o[0]] || {}, dot = s.ok === true ? 'ok' : (s.ok === false ? 'bad' : '');
      html += '<div class="hub-card"><h3><span class="dot ' + dot + '"></span>' + esc(s.name) + ' <small style="color:#8fa3cf;font-weight:500">' + esc(s.sub || '') + '</small></h3>'
        + '<div class="meta">' + esc(o[1](s)) + '</div>'
        + (s.hint ? '<div class="hint">⚠ ' + esc(s.hint) + (s.topN ? ' (' + s.topN + ')' : '') + '</div>' : '')
        + (o[0] === 'aisensy' && s.threads > 0 ? '<div class="hint">⚠ Meta Business → WhatsApp account → Partners/Apps मा AiSensy हटाउनुहोस् · remove AiSensy from the WABA</div>' : '')
        + (CAN && o[0] !== 'aisensy' ? '<button type="button" data-eng="' + o[0] + '">▶ जाँच · Test</button><div class="res" id="res-' + o[0] + '"></div>' : '')
        + '</div>';
    });
    box.innerHTML = html;
    Array.prototype.forEach.call(box.querySelectorAll('button[data-eng]'), function (bt) {
      bt.addEventListener('click', function () {
        var eng = bt.getAttribute('data-eng'), res = document.getElementById('res-' + eng);
        bt.disabled = true; res.textContent = '… ' + (eng === 'local' ? 'local model (10-60 s)' : 'testing');
        var fd = new FormData(); fd.append('act', 'ping'); fd.append('engine', eng); fd.append(<?= json_encode(CSRF_TOKEN_NAME) ?>, CSRF);
        fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': CSRF}})
          .then(function (r) { return r.json(); })
          .then(function (j) { res.innerHTML = (j.ok ? '<span style="color:#2ee59d">✔ OK</span> ' : '<span style="color:#ff5d6c">✖</span> ') + esc(j.detail || '') + (j.ms ? ' · ' + j.ms + ' ms' : ''); })
          .catch(function () { res.textContent = '✖ network'; })
          .then(function () { bt.disabled = false; });
      });
    });
  }

  function feed() {
    var box = document.getElementById('hubFeed'), html = '';
    (data.feed || []).forEach(function (ev) {
      html += '<div><time>' + esc(String(ev.at).slice(11, 16)) + '</time><span>' + ev.icon + '</span><span class="' + (ev.ok ? '' : 'x') + '">' + esc(ev.text) + '</span></div>';
    });
    box.innerHTML = html || '<div>—</div>';
    var mk = data.mk || [], t = '<tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Read</th></tr>';
    mk.forEach(function (m) { t += '<tr><td>' + esc(m.name) + '</td><td>' + esc(m.status) + '</td><td>' + esc(m.sent) + '/' + esc(m.total) + '</td><td>' + esc(m.read_n) + '</td></tr>'; });
    document.getElementById('hubMk').innerHTML = mk.length ? t : '<tr><td>अहिले कुनै campaign छैन · no campaigns yet</td></tr>';
  }

  function render() { draw(); cards(); feed(); }
  render();
  setInterval(function () {
    if (document.hidden) return;
    fetch('?json=1&h=' + H, {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
      data = j; document.getElementById('hubAt').textContent = j.at; draw(); feed();
      var keep = {}; Array.prototype.forEach.call(document.querySelectorAll('.hub-card .res'), function (r) { keep[r.id] = r.innerHTML; });
      cards(); for (var id in keep) { var n = document.getElementById(id); if (n) n.innerHTML = keep[id]; }
    }).catch(function () {});
  }, 15000);
})();
</script>
<?php admin_footer(); ?>
