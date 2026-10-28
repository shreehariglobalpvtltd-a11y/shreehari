<?php
/** Shared helpers recovered from the existing reviewed admin implementation. */
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }

function admin_multi_bus_days(string $from, string $to): array
{
    $out = [];
    try {
        foreach (Database::fetchAll(
            "SELECT route_id, travel_date, COUNT(*) AS n FROM schedules
              WHERE travel_date >= :f AND travel_date <= :t AND status <> 'cancelled'
              GROUP BY route_id, travel_date HAVING COUNT(*) > 1",
            ['f' => $from, 't' => $to]
        ) as $r) {
            $out[(int) $r['route_id'] . '|' . (string) $r['travel_date']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

/**
 * "Bus 2 · GJ05AB1234" — which coach a ticket rides. The slot is named when
 * the day has several buses on the route (or it is an extra bus at all); the
 * number plate / name follows when the schedule has a bus. '' when there is
 * nothing worth saying (a plain one-bus day with no bus assigned).
 */
function admin_bus_tag(int $slot, string $busNumber, string $busName = '', bool $multi = false): string
{
    $bits = [];
    if ($multi || $slot > 1) {
        $bits[] = 'Bus ' . max(1, $slot);
    }
    $plate = trim($busNumber) !== '' ? trim($busNumber) : trim($busName);
    if ($plate !== '') {
        $bits[] = $plate;
    }
    return implode(' · ', $bits);
}

/**
 * Page header with an optional subtitle, breadcrumb trail and action
 * buttons. Call right after admin_header() — it sits under the <h1>.
 *   admin_page_head('Everything about this agent', ['Agents' => '/admin/agents.php'], '<a class="btn">…</a>');
 */
function admin_page_head(string $subtitle = '', array $crumbs = [], string $actionsHtml = ''): void
{
    echo '<div class="page-head"><div class="ph-txt">';
    if ($crumbs !== []) {
        echo '<div class="ph-crumbs">';
        $i = 0;
        foreach ($crumbs as $label => $href) {
            if ($i++ > 0) { echo '<svg class="a-ic sm" style="opacity:.5"><use href="#a-chevron"/></svg>'; }
            echo $href !== '' ? '<a href="' . Security::e((string) $href) . '">' . Security::e((string) $label) . '</a>' : '<span>' . Security::e((string) $label) . '</span>';
        }
        echo '</div>';
    }
    if ($subtitle !== '') { echo '<p class="ph-sub">' . Security::e($subtitle) . '</p>'; }
    echo '</div>';
    if ($actionsHtml !== '') { echo '<div class="ph-actions">' . $actionsHtml . '</div>'; }
    echo '</div>';
}

/**
 * One KPI tile. $tone: blue (default) | green | orange | red | navy | teal | violet | wa.
 * $delta: ['up'|'down'|'flat', '+12%'] optional. $href wraps the whole tile.
 */
function admin_kpi(string $label, string $value, string $sub = '', string $icon = 'chart', string $tone = 'blue', ?array $delta = null, string $href = ''): string
{
    $d = '';
    if ($delta !== null && isset($delta[0], $delta[1])) {
        $d = '<span class="kd ' . Security::e((string) $delta[0]) . '">' . Security::e((string) $delta[1]) . '</span>';
    }
    return '<div class="kpi tone-' . Security::e($tone) . '">'
         . '<span class="ki"><svg class="a-ic"><use href="#a-' . Security::e($icon) . '"/></svg></span>'
         . '<div class="kt"><div class="kk">' . Security::e($label) . '</div>'
         . '<div class="kv">' . Security::e($value) . $d . '</div>'
         . ($sub !== '' ? '<div class="ks">' . Security::e($sub) . '</div>' : '')
         . '</div>'
         . ($href !== '' ? '<a class="kl" href="' . Security::e($href) . '" aria-label="' . Security::e($label) . '"></a>' : '')
         . '</div>';
}

/** Empty-state block for a panel or table cell. */
function admin_empty(string $title, string $hint = '', string $icon = '🗂️', string $actionHtml = ''): string
{
    return '<div class="empty"><span class="em-ic">' . $icon . '</span><b>' . Security::e($title) . '</b>'
         . ($hint !== '' ? '<div>' . Security::e($hint) . '</div>' : '')
         . ($actionHtml !== '' ? '<div style="margin-top:12px">' . $actionHtml . '</div>' : '')
         . '</div>';
}

/** Initials avatar (or photo when a public URL is given). */
function admin_avatar(string $name, string $photoUrl = '', string $size = ''): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
        if ($part !== '' && mb_strlen($initials) < 2) { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
    }
    $cls = 'avatar' . ($size !== '' ? ' ' . Security::e($size) : '');
    if ($photoUrl !== '') {
        return '<span class="' . $cls . '"><img src="' . Security::e($photoUrl) . '" alt="' . Security::e($name) . '" loading="lazy"></span>';
    }
    return '<span class="' . $cls . '" aria-hidden="true">' . Security::e($initials ?: '?') . '</span>';
}

/**
 * "Send on WhatsApp" button (17 Sep 2026). Renders a button the shared sheet
 * (admin_wa_js) turns into preview -> send / open-on-phone, all logged.
 *
 *   admin_wa_button('agent_statement', ['agent' => 12, 'from' => '2026-09-01', 'to' => '2026-09-17']);
 *   admin_wa_button('booking_ticket',  ['pnr' => 'SHG-2026-00123'], 'Ticket on WhatsApp');
 *
 * $target keys: agent | pnr | from | to | on | ledger. $opts: class (default
 * 'btn ghost sm'), title, id, fallback (an href for a <noscript> anchor).
 * Nothing renders when the office switch wa_admin_tools_enabled is off or
 * the signed-in role may not send that purpose (WaTemplates::REGISTRY).
 */
function admin_wa_button(string $purpose, array $target, string $label = 'Send on WhatsApp', array $opts = []): string
{
    static $registry = null;
    if ($registry === null) {
        require_once INCLUDE_PATH . '/watemplates.php';
        $registry = WaTemplates::REGISTRY;
    }
    $reg = $registry[$purpose] ?? null;
    if ($reg === null || !Settings::getBool('wa_admin_tools_enabled', true)) {
        return '';
    }
    // 18 Sep 2026: an agent may send their OWN statement-type messages to
    // themselves (WaTemplates::OWN_AGENT_PURPOSES) without the office
    // permission those purposes carry. admin/api/wa-send.php applies the
    // identical rule, so a button never appears that the API would refuse.
    $scopeId = Auth::bookingScopeAdminId();
    $own     = $scopeId !== null && (string) ($reg['target'] ?? '') === 'agent'
        && (int) ($target['agent'] ?? 0) === $scopeId
        && in_array($purpose, WaTemplates::OWN_AGENT_PURPOSES, true);
    if (!$own && !Auth::can((string) $reg['perm'])) {
        return '';
    }
    $attrs = ' data-wa-purpose="' . Security::e($purpose) . '"';
    foreach (['agent' => 'agent', 'pnr' => 'pnr', 'from' => 'from', 'to' => 'to', 'on' => 'on', 'ledger' => 'ledger', 'sid' => 'sid', 'doc' => 'doc', 'page' => 'page', 'target' => 'target', 'phone' => 'phone', 'country' => 'country'] as $k => $attr) {
        if (isset($target[$k]) && (string) $target[$k] !== '') {
            $attrs .= ' data-wa-' . $attr . '="' . Security::e((string) $target[$k]) . '"';
        }
    }
    $cls   = Security::e((string) ($opts['class'] ?? 'btn ghost sm'));
    $title = Security::e((string) ($opts['title'] ?? ((string) $reg['hint'] . ' — opens a preview first')));
    $id    = isset($opts['id']) ? ' id="' . Security::e((string) $opts['id']) . '"' : '';
    $html  = '<button type="button" class="' . $cls . ' wa-send"' . $id . $attrs . ' title="' . $title . '">'
           . '<svg class="a-ic" style="color:var(--wa-600)"><use href="#a-whatsapp"/></svg>' . Security::e($label) . '</button>';
    if (!empty($opts['fallback'])) {
        $html .= '<noscript><a class="' . $cls . '" href="' . Security::e((string) $opts['fallback']) . '" target="_blank" rel="noopener">' . Security::e($label) . '</a></noscript>';
    }
    return $html;
}

/**
 * The WhatsApp sheet: preview the composed message, then send through the
 * configured API (logged) or open it on the staff phone (also logged).
 * One IIFE, delegated on .wa-send, no framework — like admin_search_js().
 */
function admin_wa_js(): string
{
    return <<<'JS'
(function () {
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var sheet = null, cur = null;
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function post(body){
    return fetch('/admin/api/wa-send.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)})
      .then(function(r){return r.json();}).catch(function(){return {ok:false,error:'Network error — try again.'};});
  }
  function toast(msg, bad){
    var t=document.createElement('div');t.className='toast';if(bad)t.style.background='var(--bad)';t.textContent=msg;document.body.appendChild(t);
    setTimeout(function(){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(function(){t.remove();},420);},3200);
  }
  function build(){
    sheet=document.createElement('div');sheet.className='wa-sheet';sheet.hidden=true;
    sheet.innerHTML='<div class="wa-card" role="dialog" aria-modal="true" aria-labelledby="waTitle">'
      +'<div class="wa-head"><span class="wa-ico"><svg class="a-ic"><use href="#a-whatsapp"/></svg></span><div><b id="waTitle"></b><div class="wa-to" id="waTo"></div></div><button type="button" class="wa-x" aria-label="Close">×</button></div>'
      +'<pre class="wa-text" id="waText"></pre>'
      +'<div class="wa-att" id="waAtt"></div>'
      +'<label class="wa-note">Add a note (optional)<input type="text" id="waNote" maxlength="300" placeholder="e.g. Please settle by Saturday"></label>'
      +'<div class="wa-status" id="waStatus"></div>'
      +'<div class="wa-acts"><button type="button" class="btn wa" id="waSend"><svg class="a-ic"><use href="#a-send"/></svg>Send via WhatsApp API</button>'
      +'<a class="btn ghost" id="waOpen" href="#" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg>Open in WhatsApp on this phone</a>'
      +'<button type="button" class="btn ghost" id="waCopy"><svg class="a-ic"><use href="#a-copy"/></svg>Copy text</button></div></div>';
    document.body.appendChild(sheet);
    sheet.addEventListener('click',function(e){if(e.target===sheet||e.target.closest('.wa-x'))close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!sheet.hidden)close();});
    sheet.querySelector('#waNote').addEventListener('change',function(){if(cur)preview(cur.btn,cur.body,true);});
    sheet.querySelector('#waSend').addEventListener('click',send);
    sheet.querySelector('#waOpen').addEventListener('click',function(){if(!cur)return;post(Object.assign({},cur.body,{action:'handoff',note:noteVal()}));mark(cur.btn,'Opened on phone');});
    sheet.querySelector('#waCopy').addEventListener('click',function(){var t=sheet.querySelector('#waText').textContent;try{navigator.clipboard.writeText(t);toast('Message copied');}catch(e){}});
  }
  function noteVal(){return (sheet.querySelector('#waNote').value||'').trim();}
  function close(){if(sheet){sheet.hidden=true;}cur=null;}
  function mark(btn,txt){if(!btn)return;btn.classList.add('wa-done');btn.innerHTML='<svg class="a-ic"><use href="#a-check-circle"/></svg>'+esc(txt);}
  function bodyFor(btn){
    var d=btn.dataset,b={purpose:d.waPurpose||''};
    if(d.waAgent)b.agent_id=parseInt(d.waAgent,10);if(d.waPnr)b.pnr=d.waPnr;if(d.waFrom)b.from=d.waFrom;if(d.waTo)b.to=d.waTo;if(d.waOn)b.on=d.waOn;if(d.waLedger)b.ledger_id=parseInt(d.waLedger,10);
    if(d.waSid)b.sid=parseInt(d.waSid,10);if(d.waDoc)b.doc=d.waDoc;if(d.waPage)b.page=parseInt(d.waPage,10);if(d.waTarget)b.target=d.waTarget;if(d.waPhone)b.phone=d.waPhone;if(d.waCountry)b.country=d.waCountry;
    return b;
  }
  function preview(btn,body,keep){
    if(!sheet)build();
    cur={btn:btn,body:body,res:null};
    sheet.hidden=false;
    var st=sheet.querySelector('#waStatus');st.className='wa-status';st.textContent='Preparing the message…';
    sheet.querySelector('#waTitle').textContent='WhatsApp';sheet.querySelector('#waTo').textContent='';
    if(!keep){sheet.querySelector('#waNote').value='';}
    sheet.querySelector('#waText').textContent='';sheet.querySelector('#waAtt').innerHTML='';
    post(Object.assign({},body,{action:'preview',note:noteVal()})).then(function(j){
      if(!cur||cur.btn!==btn)return;
      if(!j||!j.ok){st.className='wa-status bad';st.textContent=(j&&j.error)||'Could not prepare the message.';sheet.querySelector('#waSend').hidden=true;sheet.querySelector('#waOpen').hidden=true;return;}
      cur.res=j;
      sheet.querySelector('#waTitle').textContent=j.label||'WhatsApp';
      sheet.querySelector('#waTo').textContent=(j.recipient?j.recipient+' · ':'')+'+'+j.intl;
      sheet.querySelector('#waText').textContent=j.text||'';
      var att=sheet.querySelector('#waAtt');att.innerHTML='';
      (j.attachments||[]).forEach(function(u){var a=document.createElement('a');a.href=u;a.target='_blank';a.rel='noopener';a.className='chip';a.innerHTML='<svg class="a-ic"><use href="#a-'+(/\.pdf|statement/i.test(u)?'pdf':'image')+'"/></svg>Attachment';att.appendChild(a);});
      var send=sheet.querySelector('#waSend'),open=sheet.querySelector('#waOpen');
      send.hidden=!j.canAutoSend;open.hidden=false;open.href=j.link||'#';
      st.className='wa-status '+(j.canAutoSend?'ok':'warn');st.textContent=j.reason||'';
    });
  }
  function send(){
    if(!cur||!cur.res)return;
    var btn=sheet.querySelector('#waSend');btn.disabled=true;btn.textContent='Sending…';
    post(Object.assign({},cur.body,{action:'send',note:noteVal()})).then(function(j){
      btn.disabled=false;btn.innerHTML='<svg class="a-ic"><use href="#a-send"/></svg>Send via WhatsApp API';
      var st=sheet.querySelector('#waStatus');
      if(j&&j.ok){toast(j.message||'Sent on WhatsApp');mark(cur.btn,'Sent '+new Date().toTimeString().slice(0,5));close();return;}
      if(j&&j.handoff&&j.link){st.className='wa-status warn';st.textContent=(j.reason||'Send it from your phone instead.');var o=sheet.querySelector('#waOpen');o.href=j.link;o.hidden=false;btn.hidden=true;return;}
      st.className='wa-status bad';st.textContent=(j&&j.error)||'Send failed.';
    });
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('.wa-send');if(!b)return;e.preventDefault();
    preview(b,bodyFor(b),false);
  });
})();
JS;
}


