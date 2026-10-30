<?php
/**
 * =====================================================================
 *  admin/staff-chat.php — 💬 Office chat (field staff) / 💬 Agent chat
 *  (office). 9 Oct 2026, owner ask 14.
 *
 *  One screen, WhatsApp-like: the conversation list and the open
 *  conversation side by side on a desk, one at a time on a phone (the
 *  conversation slides over the list, full screen, like any chat app).
 *  Text, quick replies, photos (resized on the phone first), PDFs, voice
 *  notes, read ticks, "online now", call + WhatsApp buttons for both sides,
 *  an urgent flag that rings the office bell, 📣 announcements from the
 *  office, phone push when the panel is closed.
 *
 *  Deep links: ?t=<thread>  ?pnr=<PNR> (find-or-create after the scope check)
 *  ?agent=<id> (office -> that person's chat). Everything goes through
 *  admin/api/staff-msg.php (JSON, CSRF, scoped); this page only draws.
 *  Polls the open thread every 4 s while the tab is visible, the list every
 *  12 s; the panel-wide 15 s poll keeps the badges right everywhere else.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/staffmsg.php';   // 31 Oct 2026: StaffMsg is no longer loaded by _guard.php (an agent's ticket page was a 500)
$admin = admin_boot('bookings.view');

if (!StaffMsg::available()) {
    admin_header('Office chat', 'staff-chat');
    echo '<div class="flash warn">Office chat is not installed on this server yet — run <code>database/upgrade-2026-10-09-staff-chat.sql</code>. / अफिस च्याट अझै सेटअप भएको छैन।</div>';
    admin_footer();
    exit;
}
if (!StaffMsg::canUse()) {
    http_response_code(403);
    admin_header('Office chat', 'staff-chat');
    echo '<div class="panel" style="max-width:560px;margin:32px auto;text-align:center;padding:32px 24px"><div style="font-size:44px">💬</div>'
       . '<h2>Office chat is for the office, agents and counters</h2><p class="muted">यो च्याट अफिस, एजेन्ट र काउन्टरका लागि हो।</p></div>';
    admin_footer();
    exit;
}

$me      = (int) $admin['id'];
$office  = StaffMsg::isOffice();
$field   = !$office;
$openId  = max(0, (int) ($_GET['t'] ?? 0));
$pnrQ    = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 40));
// 15 Oct 2026 (Quick Edit "Wrong …" chips): ?pnr=…&q=q_name opens the ticket's
// thread with that quick request already written in the box (never sent).
$quickQ  = in_array((string) ($_GET['q'] ?? ''), ['q_seat', 'q_name', 'q_phone', 'q_date', 'q_pickup', 'q_fare', 'q_paid', 'q_agent', 'q_cancel'], true)
    ? (string) $_GET['q'] : '';
$agentQ  = $office ? max(0, (int) ($_GET['agent'] ?? 0)) : 0;
$general = 0;
if ($field) {
    try {
        $general = (int) StaffMsg::threadFor('general')['id'];   // their office chat always exists
    } catch (Throwable $e) {
        $general = 0;
    }
}
$pushOn = false;
$pushKey = '';
try {
    require_once INCLUDE_PATH . '/webpush.php';
    $pushOn  = StaffMsg::pushReady() && WebPush::enabled() && Settings::getBool('staffmsg_push_on', true);
    $pushKey = $pushOn ? WebPush::publicKey() : '';
} catch (Throwable $e) {
    $pushOn = false;
}
$boot = [
    'me'        => $me,
    'name'      => (string) ($admin['full_name'] ?: $admin['username']),
    'office'    => $office,
    'role'      => StaffMsg::role(),
    'open'      => $openId,
    'pnr'       => $pnrQ,
    'quick'     => $pnrQ !== '' ? $quickQ : '',
    'agent'     => $agentQ,
    'general'   => $general,
    'broadcast' => StaffMsg::canBroadcast(),
    'attach'    => Settings::getBool('staffmsg_attach_on', true),
    'push'      => ['on' => $pushOn, 'key' => $pushKey],
    'voiceMax'  => StaffMsg::VOICE_MAX,
    'fileMax'   => StaffMsg::FILE_MAX,
];

admin_header($office ? 'Agent chat' : 'Office chat', 'staff-chat');
?>
<style>
/* ===== Office chat — phone-first, token-only colours (light / dark / AMOLED) ===== */
.sc-app{--sc-r:20px;--sc-me:var(--royal);--sc-on-me:var(--on-royal,#fff);display:grid;grid-template-columns:minmax(300px,360px) minmax(0,1fr);gap:0;
  height:calc(100dvh - 150px);min-height:540px;border:1px solid var(--line);border-radius:var(--sc-r);overflow:hidden;background:var(--surface);box-shadow:var(--shadow-1,0 1px 3px rgba(0,0,0,.08))}
.sc-list{display:flex;flex-direction:column;min-width:0;border-right:1px solid var(--line);background:var(--surface)}
.sc-lh{padding:14px 14px 10px;display:grid;gap:10px;border-bottom:1px solid var(--line)}
.sc-lh-top{display:flex;align-items:center;gap:8px}
.sc-lh-top h2{margin:0;font-size:18px;font-weight:800;flex:1;display:flex;align-items:center;gap:8px;letter-spacing:-.01em}
.sc-ic-btn{min-width:44px;min-height:44px;border-radius:14px;border:1px solid var(--line);background:var(--surface);color:var(--ink);display:inline-grid;place-items:center;cursor:pointer;font:inherit;font-weight:700;padding:0 10px;text-decoration:none}
.sc-ic-btn:hover{border-color:var(--royal);color:var(--royal)}
.sc-new{background:var(--royal);color:var(--on-royal,#fff);border-color:transparent;padding:0 16px;gap:6px;display:inline-flex;align-items:center}
.sc-new:hover{color:var(--on-royal,#fff);filter:brightness(1.08)}
.sc-search{display:flex;align-items:center;gap:8px;background:var(--surface-sunken,var(--soft));border-radius:14px;padding:0 12px;min-height:44px}
.sc-search input{flex:1;min-width:0;border:0;background:transparent;color:var(--ink);font:inherit;font-size:15px;outline:none;padding:10px 0}
/* the pill is the field: beat depth.css's inset well (an id outranks its long :not() chain) */
#scApp .sc-search input{background:transparent!important;border-color:transparent!important;box-shadow:none!important;min-height:44px}
#scApp .sc-search:focus-within{box-shadow:0 0 0 2px var(--royal)}
.sc-filters{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin:0 -2px;padding:0 2px}
.sc-filters::-webkit-scrollbar{display:none}
.sc-chip{flex:0 0 auto;min-height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.sc-chip.on{background:var(--royal-soft,var(--royal-tint));color:var(--royal-strong,var(--royal));border-color:transparent}
.sc-push{margin:10px 12px 0;padding:10px 12px;border-radius:16px;background:var(--royal-tint,var(--soft));display:flex;align-items:center;gap:10px;font-size:13px;line-height:1.35}
.sc-push b{display:block}
.sc-push .sc-ic-btn{min-height:38px;border-radius:12px;font-size:13px}
.sc-threads{flex:1;overflow-y:auto;padding:6px 0 12px;overscroll-behavior:contain}
.sc-t{display:flex;gap:12px;align-items:center;padding:10px 14px;cursor:pointer;border:0;background:transparent;width:100%;text-align:left;color:var(--ink);font:inherit;min-height:68px;position:relative}
.sc-t:hover{background:var(--surface-hover,var(--soft))}
.sc-t.on{background:var(--royal-tint,var(--soft))}
.sc-t.on::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:4px;border-radius:0 4px 4px 0;background:var(--royal)}
.sc-av{flex:0 0 auto;width:46px;height:46px;border-radius:16px;display:grid;place-items:center;font-weight:800;font-size:15px;color:#fff;position:relative;letter-spacing:.02em}
.sc-av.k-general{background:linear-gradient(135deg,var(--royal),var(--royal-strong,var(--royal)))}
.sc-av.k-booking{background:linear-gradient(135deg,#0E7C66,#0B5D4C)}
.sc-av.k-settlement,.sc-av.k-payout{background:linear-gradient(135deg,#B45309,#92400E)}
.sc-av.k-verify{background:linear-gradient(135deg,#B42318,#7A1A12)}
.sc-av .sc-on{position:absolute;right:-2px;bottom:-2px;width:13px;height:13px;border-radius:50%;background:var(--success,#16a34a);box-shadow:0 0 0 3px var(--surface)}
.sc-tm{flex:1;min-width:0;display:grid;gap:3px}
.sc-tr{display:flex;align-items:center;gap:6px;min-width:0}
.sc-tn{font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.sc-tt{font-size:11.5px;color:var(--muted);white-space:nowrap}
.sc-t.unread .sc-tt{color:var(--royal);font-weight:800}
.sc-tp{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.sc-t.unread .sc-tp{color:var(--ink);font-weight:600}
.sc-un{min-width:22px;height:22px;padding:0 7px;border-radius:999px;background:var(--royal);color:var(--on-royal,#fff);font-size:11.5px;font-weight:800;display:inline-grid;place-items:center}
.sc-tag{font-size:10.5px;font-weight:800;padding:1px 7px;border-radius:999px;background:var(--surface-sunken,var(--soft));color:var(--muted);white-space:nowrap;letter-spacing:.02em}
.sc-tag.urgent{background:var(--danger-soft,#fde8e8);color:var(--danger,#b42318)}
.sc-tag.closed{opacity:.8}
.sc-empty{padding:40px 22px;text-align:center;color:var(--muted);font-size:14px;line-height:1.5}
.sc-empty .sc-big{width:92px;height:92px;border-radius:30px;margin:0 auto 14px;display:grid;place-items:center;font-size:44px;background:linear-gradient(135deg,var(--royal-tint,var(--soft)),var(--surface-sunken,var(--soft)));box-shadow:inset 0 0 0 1px var(--line)}
.sc-empty b{display:block;color:var(--ink);font-size:16px;margin-bottom:4px}

.sc-conv{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--page);position:relative}
.sc-thread{display:contents}
.sc-thread[hidden],.sc-pick[hidden]{display:none}
/* this stylesheet exists only on this page: the list header carries the title, the page h1 stays for screen readers */
.wrap > h1{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.sc-ch{display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid var(--line);background:var(--surface);min-height:64px}
.sc-back{display:none}
.sc-chm{flex:1;min-width:0}
.sc-chm b{display:block;font-size:15.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-pres{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-pres.on{color:var(--success,#16a34a);font-weight:700}
.sc-pres.on::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor;flex:0 0 auto}
.sc-acts{display:flex;gap:6px;align-items:center}
.sc-acts a,.sc-acts button{min-width:44px;min-height:44px;border-radius:14px;display:inline-grid;place-items:center;border:1px solid var(--line);background:var(--surface);color:var(--ink);cursor:pointer;text-decoration:none;font-size:18px;padding:0}
.sc-acts .wa{color:var(--success,#16a34a)}
.sc-acts .tel{color:var(--royal)}
.sc-pnr{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;padding:3px 10px;border-radius:999px;background:var(--royal-tint,var(--soft));color:var(--royal-strong,var(--royal));text-decoration:none;font-family:var(--font-mono,monospace);white-space:nowrap}
.sc-pnr:hover{filter:brightness(.97)}
/* every [hidden] in the chat really hides (.sc-push / .sc-row / .sc-pnr / buttons set their own display) */
#scApp [hidden],#scDlg [hidden]{display:none!important}
.sc-menu{position:absolute;right:12px;top:62px;z-index:5;background:var(--surface);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow-3,0 12px 32px rgba(0,0,0,.18));padding:6px;min-width:220px;display:grid}
.sc-menu[hidden]{display:none}
.sc-menu button{min-height:44px;border:0;background:transparent;color:var(--ink);font:inherit;font-weight:600;text-align:left;padding:0 12px;border-radius:10px;cursor:pointer}
.sc-menu button:hover{background:var(--surface-hover,var(--soft))}
.sc-banner{margin:8px 12px 0;padding:8px 12px;border-radius:12px;font-size:12.5px;background:var(--warning-soft,#fff4d1);color:var(--ink)}
.sc-msgs{flex:1;overflow-y:auto;padding:14px 12px 10px;display:flex;flex-direction:column;gap:3px;overscroll-behavior:contain;
  background-color:var(--page);background-image:radial-gradient(var(--line) 1px,transparent 1.2px);background-size:22px 22px}
.sc-day{align-self:center;margin:12px 0 8px;font-size:11.5px;font-weight:800;color:var(--muted);background:var(--surface);border:1px solid var(--line);padding:3px 12px;border-radius:999px;letter-spacing:.03em}
.sc-who{font-size:11.5px;font-weight:800;color:var(--royal);margin:8px 10px 1px}
.sc-who.r{align-self:flex-end;color:var(--muted)}
.sc-b{max-width:min(78%,520px);padding:8px 12px 6px;border-radius:20px;background:var(--surface);color:var(--ink);border:1px solid var(--line);align-self:flex-start;
  position:relative;word-wrap:break-word;overflow-wrap:anywhere;box-shadow:0 1px 1px rgba(0,0,0,.04);animation:scIn .22s var(--md-ease-emph-decel,cubic-bezier(.05,.7,.1,1))}
.sc-b.me{align-self:flex-end;background:var(--sc-me);color:var(--sc-on-me);border-color:transparent;border-bottom-right-radius:6px}
.sc-b.them{border-bottom-left-radius:6px}
.sc-b.urgent{box-shadow:inset 3px 0 0 var(--danger,#b42318)}
.sc-b .tx{white-space:pre-wrap;font-size:14.5px;line-height:1.42}
.sc-b .mt{display:flex;justify-content:flex-end;align-items:center;gap:4px;font-size:10.5px;opacity:.75;margin-top:2px;white-space:nowrap}
.sc-b .tk{font-weight:800;letter-spacing:-2px}
.sc-b .tk.seen{color:#7DD3FC;opacity:1}
.sc-b:not(.me) .tk.seen{color:var(--royal)}
.sc-b.pending{opacity:.7}
.sc-b.failed{background:var(--danger-soft,#fde8e8);color:var(--ink);cursor:pointer}
.sc-b .ur{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--danger,#b42318);display:block;margin-bottom:2px}
.sc-b.me .ur{color:#FFD7D2}
.sc-b a.pn{color:inherit;font-weight:800;text-decoration:underline;text-underline-offset:2px;font-family:var(--font-mono,monospace);font-size:.95em}
.sc-b img.ph{display:block;max-width:100%;width:260px;max-height:320px;object-fit:cover;border-radius:14px;margin:2px 0 4px;background:var(--surface-sunken,var(--soft))}
.sc-doc{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:14px;background:rgba(0,0,0,.06);color:inherit;text-decoration:none;margin:2px 0 4px;min-height:44px}
.sc-doc span{font-size:24px}
.sc-sys{align-self:center;max-width:88%;margin:6px 0;font-size:12px;line-height:1.4;color:var(--muted);background:var(--surface);border:1px dashed var(--line);padding:5px 12px;border-radius:12px;text-align:center}
.sc-ann{align-self:stretch;margin:8px 4px;padding:12px 14px;border-radius:18px;background:var(--surface);border:1px solid var(--line);box-shadow:inset 4px 0 0 var(--warning,#d97706)}
.sc-ann .ah{font-size:11.5px;font-weight:800;color:var(--warning,#b45309);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
.sc-ann .tx{white-space:pre-wrap;font-size:14.5px;line-height:1.45}
.sc-voice{display:flex;align-items:center;gap:10px;min-width:210px;padding:2px 0}
.sc-play{width:40px;height:40px;border-radius:50%;border:0;display:grid;place-items:center;cursor:pointer;font-size:15px;background:rgba(255,255,255,.22);color:inherit;flex:0 0 auto}
.sc-b:not(.me) .sc-play{background:var(--royal);color:var(--on-royal,#fff)}
.sc-wave{flex:1;height:26px;position:relative;cursor:pointer;background:repeating-linear-gradient(90deg,currentColor 0 3px,transparent 3px 6px);opacity:.35;border-radius:4px;
  -webkit-mask:linear-gradient(0deg,transparent 30%,#000 30% 70%,transparent 70%);mask:linear-gradient(0deg,transparent 30%,#000 30% 70%,transparent 70%)}
.sc-wave-w{flex:1;position:relative;height:26px}
.sc-wave-w .sc-wave{position:absolute;inset:0}
.sc-wave-w .sc-prog{position:absolute;inset:0;width:0;overflow:hidden}
.sc-wave-w .sc-prog .sc-wave{opacity:1;width:var(--w,200px)}
.sc-dur{font-size:11.5px;font-variant-numeric:tabular-nums;opacity:.85;min-width:34px}
.sc-earlier{align-self:center;margin:4px 0 10px;min-height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--line);background:var(--surface);color:var(--ink);font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}

.sc-comp{border-top:1px solid var(--line);background:var(--surface);padding:8px 10px calc(8px + env(safe-area-inset-bottom,0px));display:grid;gap:8px}
.sc-quick{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none}
.sc-quick::-webkit-scrollbar{display:none}
.sc-quick button{flex:0 0 auto;min-height:36px;padding:0 12px;border-radius:999px;border:1px solid var(--line);background:var(--surface-sunken,var(--soft));color:var(--ink);font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.sc-quick button.urg.on{background:var(--danger,#b42318);color:#fff;border-color:transparent}
.sc-row{display:flex;align-items:flex-end;gap:8px}
.sc-row textarea{flex:1;min-width:0;resize:none;border:1px solid var(--line);border-radius:22px;background:var(--surface-sunken,var(--soft));color:var(--ink);font:inherit;font-size:16px;line-height:1.35;
  padding:11px 16px;min-height:46px;max-height:140px;outline:none}
.sc-row textarea:focus{border-color:var(--royal);box-shadow:0 0 0 3px var(--royal-tint,rgba(0,0,0,.06))}
.sc-round{width:46px;height:46px;border-radius:50%;border:0;display:grid;place-items:center;cursor:pointer;flex:0 0 auto;font-size:18px;background:var(--surface-sunken,var(--soft));color:var(--ink)}
.sc-round.go{background:var(--royal);color:var(--on-royal,#fff);box-shadow:var(--shadow-2,0 4px 12px rgba(0,0,0,.18))}
.sc-round.rec{background:var(--danger,#b42318);color:#fff}
.sc-round svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sc-attach-menu{display:flex;gap:8px}
.sc-attach-menu[hidden]{display:none}
.sc-attach-menu label{min-height:40px;padding:0 14px;border-radius:999px;border:1px solid var(--line);display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer;background:var(--surface)}
.sc-prev{display:flex;align-items:center;gap:10px;padding:6px;border-radius:14px;background:var(--surface-sunken,var(--soft))}
.sc-prev[hidden]{display:none}
.sc-prev img{width:54px;height:54px;object-fit:cover;border-radius:10px}
.sc-prev .nm{flex:1;min-width:0;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sc-recbar{display:flex;align-items:center;gap:10px;min-height:46px}
.sc-recbar[hidden]{display:none}
.sc-recdot{width:12px;height:12px;border-radius:50%;background:var(--danger,#b42318);animation:scPulse 1s infinite}
.sc-rect{font-variant-numeric:tabular-nums;font-weight:800;flex:1}
.sc-note{font-size:12px;color:var(--muted);line-height:1.35;padding:0 4px}
.sc-note[hidden]{display:none}
.sc-closed{font-size:12.5px;color:var(--muted);text-align:center;padding:2px 0}

.sc-pick{flex:1;display:grid;place-items:center;padding:24px}
.sc-toast{position:fixed;left:50%;bottom:calc(96px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);background:var(--ink);color:var(--surface);padding:10px 16px;border-radius:14px;font-size:13.5px;z-index:90;box-shadow:var(--shadow-3,0 8px 24px rgba(0,0,0,.25));max-width:calc(100vw - 32px)}
.sc-toast[hidden]{display:none}
.sc-lang{font-size:12px;min-width:44px}

/* new-conversation sheet */
.sc-dlg{border:0;padding:0;border-radius:24px;max-width:520px;width:calc(100vw - 24px);background:var(--surface);color:var(--ink);box-shadow:var(--shadow-3,0 18px 48px rgba(0,0,0,.3))}
.sc-dlg::backdrop{background:rgba(8,14,30,.55)}
.sc-dlg form{display:grid;gap:12px;padding:18px}
.sc-dlg h3{margin:0;font-size:18px;display:flex;align-items:center;gap:8px}
.sc-tabs{display:flex;gap:6px;flex-wrap:wrap}
.sc-dlg label.f{display:grid;gap:5px;font-size:12.5px;font-weight:700;color:var(--muted)}
.sc-dlg input,.sc-dlg textarea,.sc-dlg select{font:inherit;font-size:16px;color:var(--ink);background:var(--surface-sunken,var(--soft));border:1px solid var(--line);border-radius:14px;padding:10px 12px;min-height:46px;width:100%}
.sc-ppl{max-height:220px;overflow-y:auto;border:1px solid var(--line);border-radius:14px;display:grid}
.sc-ppl button{display:flex;gap:10px;align-items:center;min-height:48px;padding:6px 12px;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--ink);font:inherit;text-align:left;cursor:pointer}
.sc-ppl button:last-child{border-bottom:0}
.sc-ppl button.on{background:var(--royal-tint,var(--soft));font-weight:800}
.sc-ppl small{color:var(--muted);font-weight:600}
.sc-dlg .sc-foot{display:flex;gap:8px;justify-content:flex-end}
.sc-dlg .sc-err{color:var(--danger,#b42318);font-size:13px;font-weight:700}
.sc-dlg .sc-err:empty{display:none}

@keyframes scIn{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}
@keyframes scPulse{50%{opacity:.25}}
@keyframes scSlide{from{transform:translateX(100%)}to{transform:none}}
@media (prefers-reduced-motion:reduce){.sc-b,.sc-conv{animation:none!important}.sc-recdot{animation:none}}
html[data-motion="reduced"] .sc-b,html[data-motion="reduced"] .sc-conv{animation:none!important}

/* phone: list fills the page, a conversation slides over everything */
@media (max-width:820px){
  .sc-app{grid-template-columns:1fr;height:auto;min-height:calc(100dvh - 220px);border-radius:20px}
  .sc-list{border-right:0}
  .sc-threads{max-height:none}
  .sc-conv{display:none}
  body.sc-open .sc-conv{display:flex;position:fixed;inset:0;z-index:70;animation:scSlide .28s var(--md-ease-emph-decel,cubic-bezier(.05,.7,.1,1))}
  body.sc-open{overflow:hidden}
  body.sc-open .mnav{display:none!important}
  .sc-back{display:inline-grid}
  .sc-ch{padding-top:calc(8px + env(safe-area-inset-top,0px))}
  .sc-b{max-width:84%}
  .sc-pnr{display:none}
  .sc-pnr.sm{display:inline-flex}
}
@media (max-width:420px){ .sc-acts a.wa{display:none} }
</style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="sc-mic" viewBox="0 0 24 24"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></symbol>
  <symbol id="sc-send" viewBox="0 0 24 24"><path d="M4 12l16-8-6 16-2.5-6.5z"/><path d="M11.5 13.5L20 4"/></symbol>
  <symbol id="sc-clip" viewBox="0 0 24 24"><path d="M21 11.5l-8.6 8.6a5.5 5.5 0 0 1-7.8-7.8l8.6-8.6a3.7 3.7 0 0 1 5.2 5.2l-8.6 8.6a1.8 1.8 0 0 1-2.6-2.6l7.9-7.9"/></symbol>
  <symbol id="sc-bolt" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></symbol>
  <symbol id="sc-trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></symbol>
</svg>

<div class="sc-app" id="scApp">
  <aside class="sc-list" aria-label="Conversations">
    <div class="sc-lh">
      <div class="sc-lh-top">
        <h2><span aria-hidden="true">💬</span><span data-t="<?= $office ? 'titleOffice' : 'titleField' ?>"><?= $office ? 'Agent chat' : 'Office chat' ?></span></h2>
        <button type="button" class="sc-ic-btn sc-lang" id="scLang" aria-label="Language">ने</button>
        <button type="button" class="sc-ic-btn sc-new" id="scNewBtn"><span aria-hidden="true">＋</span><span data-t="new">New</span></button>
      </div>
      <label class="sc-search"><svg class="a-ic"><use href="#a-search"/></svg><input type="search" id="scQ" autocomplete="off" data-ph="search" placeholder="Search name, PNR" data-sync-ignore></label>
      <?php if ($office): ?>
      <div class="sc-filters" id="scFilters" role="tablist">
        <button type="button" class="sc-chip on" data-f="open" data-t="fOpen">Open</button>
        <button type="button" class="sc-chip" data-f="waiting" data-t="fWaiting">Waiting</button>
        <button type="button" class="sc-chip" data-f="urgent" data-t="fUrgent">Urgent</button>
        <button type="button" class="sc-chip" data-f="mine" data-t="fMine">Mine</button>
        <button type="button" class="sc-chip" data-f="all" data-t="fAll">All</button>
        <button type="button" class="sc-chip" data-f="closed" data-t="fClosed">Closed</button>
      </div>
      <?php endif; ?>
    </div>
    <div class="sc-push" id="scPush" hidden>
      <span style="font-size:22px" aria-hidden="true">🔔</span>
      <div style="flex:1"><b data-t="pushAsk">Get a phone alert for new messages</b><span class="muted" id="scPushSub" data-t="pushSub">Works even when the panel is closed.</span></div>
      <button type="button" class="sc-ic-btn" id="scPushOn" data-t="pushOn">Turn on</button>
      <button type="button" class="sc-ic-btn" id="scPushLater" aria-label="Later">✕</button>
    </div>
    <div class="sc-threads" id="scThreads" aria-live="polite"><div class="sc-empty" data-t="loading">Loading…</div></div>
  </aside>

  <section class="sc-conv" id="scConv" aria-label="Conversation">
    <div class="sc-pick" id="scPick">
      <div class="sc-empty"><div class="sc-big" aria-hidden="true">💬</div><b data-t="pickT">Pick a conversation</b><span data-t="pickS">Tickets, settlements, payouts — everything with the office in one place.</span></div>
    </div>
    <div id="scThread" class="sc-thread" hidden>
      <header class="sc-ch">
        <button type="button" class="sc-ic-btn sc-back" id="scBack" aria-label="Back">←</button>
        <div class="sc-av k-general" id="scAv" aria-hidden="true">💬</div>
        <div class="sc-chm"><b id="scTitle"></b><div class="sc-pres" id="scPres"></div></div>
        <a class="sc-pnr" id="scPnr" hidden href="#"></a>
        <div class="sc-acts" id="scActs"></div>
      </header>
      <div class="sc-menu" id="scMenu" hidden role="menu"></div>
      <div class="sc-msgs" id="scMsgs" role="log" aria-live="polite" aria-relevant="additions"></div>
      <div class="sc-comp" id="scComp">
        <div class="sc-closed" id="scClosed" hidden data-t="closedNote">This conversation is closed — writing reopens it.</div>
        <div class="sc-quick" id="scQuick"></div>
        <div class="sc-note" id="scNote" hidden></div>
        <div class="sc-attach-menu" id="scAttachMenu" hidden>
          <label><input type="file" id="scFileImg" accept="image/*" hidden>📷 <span data-t="photo">Photo</span></label>
          <label><input type="file" id="scFilePdf" accept="application/pdf" hidden>📄 PDF</label>
        </div>
        <div class="sc-prev" id="scPrev" hidden><img id="scPrevImg" alt="" hidden><span class="nm" id="scPrevName"></span><button type="button" class="sc-ic-btn" id="scPrevX" aria-label="Remove">✕</button></div>
        <div class="sc-recbar" id="scRecBar" hidden>
          <button type="button" class="sc-round" id="scRecX" aria-label="Cancel recording"><svg><use href="#sc-trash"/></svg></button>
          <span class="sc-recdot" aria-hidden="true"></span><span class="sc-rect" id="scRecT">0:00</span>
          <button type="button" class="sc-round go" id="scRecSend" aria-label="Send voice note"><svg><use href="#sc-send"/></svg></button>
        </div>
        <div class="sc-row" id="scRow">
          <button type="button" class="sc-round" id="scAttach" aria-label="Attach photo or PDF"><svg><use href="#sc-clip"/></svg></button>
          <textarea id="scText" rows="1" maxlength="2000" data-ph="type" placeholder="Type a message…" data-sync-ignore></textarea>
          <button type="button" class="sc-round go" id="scSend" aria-label="Send"><svg><use href="#sc-mic"/></svg></button>
        </div>
      </div>
    </div>
  </section>
</div>

<dialog class="sc-dlg" id="scDlg">
  <form method="dialog" id="scDlgForm">
    <h3><span aria-hidden="true">✍️</span><span data-t="newTitle">New message</span></h3>
    <div class="sc-tabs" id="scDlgTabs"></div>
    <div id="scDlgBody"></div>
    <div class="sc-err" id="scDlgErr" role="alert"></div>
    <div class="sc-foot">
      <button type="button" class="sc-ic-btn" id="scDlgCancel" data-t="cancel">Cancel</button>
      <button type="submit" class="sc-ic-btn sc-new" id="scDlgGo" data-t="start">Start</button>
    </div>
  </form>
</dialog>
<div class="sc-toast" id="scToast" hidden role="status"></div>

<script>window.SC_BOOT = <?= Security::jsonForHtml($boot) ?>;</script>
<script>
(function () {
  'use strict';
  var B = window.SC_BOOT || {};
  var API = '/admin/api/staff-msg.php';
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  document.body.classList.add('sc-page');

  /* ---------- words (ne default · en · hi) ---------- */
  var W = {
    ne: { titleOffice: 'एजेन्ट च्याट', titleField: 'अफिस च्याट', new: 'नयाँ', search: 'खोज्नुहोस् — नाम, PNR', fOpen: 'खुला', fWaiting: 'जवाफ बाँकी', fUrgent: 'जरुरी', fMine: 'मेरो', fAll: 'सबै', fClosed: 'बन्द',
      loading: 'लोड हुँदैछ…', emptyOffice: 'अहिलेसम्म कुनै कुराकानी छैन। एजेन्ट/काउन्टरले आफ्नो प्यानलबाट लेख्छन् — वा ＋ नयाँ थिच्नुहोस्।', emptyFilter: 'यहाँ केही छैन।',
      pickT: 'कुराकानी छान्नुहोस्', pickS: 'टिकट, सेटलमेन्ट, भुक्तानी — अफिससँगको सबै कुरा एकै ठाउँमा।', type: 'सन्देश लेख्नुहोस्…', online: 'अनलाइन', officeOn: 'अफिस अनलाइन छ', officeOff: 'अफिस अफलाइन — फर्केपछि जवाफ दिन्छ',
      lastSeen: 'अन्तिम पटक', never: 'अहिलेसम्म अनलाइन भएको छैन', today: 'आज', yesterday: 'हिजो', you: 'तपाईं', office: 'अफिस', voice: 'भ्वाइस नोट', photo: 'फोटो', recording: 'रेकर्ड हुँदैछ',
      micDenied: 'रेकर्ड गर्न माइक्रोफोनको अनुमति दिनुहोस्।', noRec: 'यो ब्राउजरमा भ्वाइस रेकर्ड हुँदैन।', tooBig: 'फाइल धेरै ठूलो भयो', retry: 'पठाइएन — फेरि पठाउन थिच्नुहोस्', urgent: 'जरुरी', urgentOn: 'जरुरी — अफिसको घण्टी बज्छ', urgentOnO: 'जरुरी — यो कुराकानी जरुरीमा राखिन्छ',
      earlier: 'अघिल्ला सन्देश', take: '🙋 म हेर्छु', close: '✅ बन्द गर्नुहोस्', reopen: '🔄 फेरि खोल्नुहोस्', markUrg: '🔴 जरुरी चिन्ह', clearUrg: '⚪ जरुरी हटाउनुहोस्', openTicket: 'टिकट खोल्नुहोस्',
      closedNote: 'यो कुराकानी बन्द छ — लेख्नुभयो भने फेरि खुल्छ।', cancelNote: 'ℹ️ रद्द र फिर्ता अफिसले हातैले मिलाउँछ — यसले अनुरोध मात्र पठाउँछ।',
      pushAsk: 'नयाँ सन्देशमा फोनमा सूचना पाउनुहोस्', pushSub: 'प्यानल बन्द हुँदा पनि आउँछ।', pushOn: 'खोल्नुहोस्', pushDone: 'सूचना चालू भयो ✓', pushBlocked: 'ब्राउजर सेटिङमा सूचना रोकिएको छ।',
      newTitle: 'नयाँ सन्देश', tPerson: '👤 व्यक्ति', tTicket: '🎫 टिकट', tAnnounce: '📣 घोषणा', tReport: '🛡️ शंकास्पद टिकट', to: 'कसलाई', findPerson: 'नाम वा कोड खोज्नुहोस्', pnr: 'PNR (जस्तै SHG-1234)',
      message: 'सन्देश', audience: 'कसलाई पठाउने', audAll: 'सबै एजेन्ट र काउन्टर', audAgents: 'एजेन्ट मात्र', audCounters: 'काउन्टर मात्र', start: 'पठाउनुहोस्', cancel: 'रद्द',
      reached: '{n} जनालाई पठाइयो ✓', pickPerson: 'पहिले व्यक्ति छान्नुहोस्।', needPnr: 'PNR लेख्नुहोस्।', needMsg: 'सन्देश लेख्नुहोस्।', hello: 'नमस्ते भन्नुहोस् 👋', ann: 'घोषणा',
      handled: '{n} ले हेर्दै', agent: 'एजेन्ट', counter: 'काउन्टर', call: 'फोन', sendFail: 'पठाउन सकिएन', reportHint: 'नक्कली देखिने टिकटको PNR/कोड र के देख्नुभयो लेख्नुहोस्। अफिसलाई तुरुन्त थाहा हुन्छ।',
      q_seat: '🪑 सिट परिवर्तन', q_name: '✏️ नाम सच्याउने', q_paid: '💸 भुक्तानी प्रमाण', q_cancel: '❌ रद्द अनुरोध', q_pickup: '📍 चढ्ने ठाउँ', q_done: '✅ भयो',
      q_phone: '📞 नम्बर सच्याउने', q_date: '📅 मिति परिवर्तन', q_fare: '₹ भाडा सच्याउने', q_agent: '🧑‍💼 एजेन्ट परिवर्तन', q_check: '👀 हेर्दैछौं', q_call: '📞 फोन गर्दैछु', q_shot: '📎 स्क्रिनसट पठाउनुहोस्', q_wait: '🕐 १० मिनेट', q_cancelOk: '❌ रद्द अनुरोध मिल्यो' },
    en: { titleOffice: 'Agent chat', titleField: 'Office chat', new: 'New', search: 'Search name, PNR', fOpen: 'Open', fWaiting: 'Waiting', fUrgent: 'Urgent', fMine: 'Mine', fAll: 'All', fClosed: 'Closed',
      loading: 'Loading…', emptyOffice: 'No conversations yet. Agents and counters write from their panel — or tap ＋ New.', emptyFilter: 'Nothing here.',
      pickT: 'Pick a conversation', pickS: 'Tickets, settlements, payouts — everything with the office in one place.', type: 'Type a message…', online: 'online now', officeOn: 'Office is online', officeOff: 'Office offline — they reply when back',
      lastSeen: 'last seen', never: 'not online yet', today: 'Today', yesterday: 'Yesterday', you: 'You', office: 'Office', voice: 'Voice note', photo: 'Photo', recording: 'Recording',
      micDenied: 'Allow the microphone to record a voice note.', noRec: 'This browser cannot record voice notes.', tooBig: 'That file is too large', retry: 'Not sent — tap to retry', urgent: 'Urgent', urgentOn: 'Urgent — rings the office bell', urgentOnO: 'Urgent — this conversation is flagged urgent',
      earlier: 'Load earlier messages', take: "🙋 I'll handle it", close: '✅ Close', reopen: '🔄 Reopen', markUrg: '🔴 Mark urgent', clearUrg: '⚪ Clear urgent', openTicket: 'Open ticket',
      closedNote: 'This conversation is closed — writing reopens it.', cancelNote: 'ℹ️ The office settles cancellations and refunds by hand — this only sends the request.',
      pushAsk: 'Get a phone alert for new messages', pushSub: 'Works even when the panel is closed.', pushOn: 'Turn on', pushDone: 'Alerts are on ✓', pushBlocked: 'Notifications are blocked in the browser settings.',
      newTitle: 'New message', tPerson: '👤 Person', tTicket: '🎫 Ticket', tAnnounce: '📣 Announcement', tReport: '🛡️ Suspicious ticket', to: 'To', findPerson: 'Search a name or code', pnr: 'PNR (e.g. SHG-1234)',
      message: 'Message', audience: 'Send to', audAll: 'Every agent and counter', audAgents: 'Agents only', audCounters: 'Counters only', start: 'Send', cancel: 'Cancel',
      reached: 'Sent to {n} people ✓', pickPerson: 'Pick a person first.', needPnr: 'Type the PNR.', needMsg: 'Type a message.', hello: 'Say hello 👋', ann: 'Announcement',
      handled: 'Handled by {n}', agent: 'Agent', counter: 'Counter', call: 'Call', sendFail: 'Could not send', reportHint: 'Type the PNR / code of the ticket that looks fake and what you saw. The office hears at once.',
      q_seat: '🪑 Seat change', q_name: '✏️ Name fix', q_paid: '💸 Payment proof', q_cancel: '❌ Cancel request', q_pickup: '📍 Pickup change', q_done: '✅ Done',
      q_phone: '📞 Phone fix', q_date: '📅 Date change', q_fare: '₹ Fare fix', q_agent: '🧑‍💼 Agent change', q_check: '👀 Checking', q_call: '📞 Calling you', q_shot: '📎 Send screenshot', q_wait: '🕐 10 minutes', q_cancelOk: '❌ Cancel request noted' },
    hi: { titleOffice: 'एजेंट चैट', titleField: 'ऑफिस चैट', new: 'नया', search: 'खोजें — नाम, PNR', fOpen: 'खुले', fWaiting: 'जवाब बाकी', fUrgent: 'ज़रूरी', fMine: 'मेरे', fAll: 'सभी', fClosed: 'बंद',
      loading: 'लोड हो रहा है…', emptyOffice: 'अभी कोई बातचीत नहीं। एजेंट/काउंटर अपने पैनल से लिखते हैं — या ＋ नया दबाएँ।', emptyFilter: 'यहाँ कुछ नहीं है।',
      pickT: 'बातचीत चुनें', pickS: 'टिकट, सेटलमेंट, भुगतान — ऑफिस से सब कुछ एक जगह।', type: 'संदेश लिखें…', online: 'ऑनलाइन', officeOn: 'ऑफिस ऑनलाइन है', officeOff: 'ऑफिस ऑफ़लाइन — लौटकर जवाब देंगे',
      lastSeen: 'आख़िरी बार', never: 'अभी तक ऑनलाइन नहीं', today: 'आज', yesterday: 'कल', you: 'आप', office: 'ऑफिस', voice: 'वॉइस नोट', photo: 'फ़ोटो', recording: 'रिकॉर्ड हो रहा है',
      micDenied: 'वॉइस नोट के लिए माइक्रोफ़ोन की अनुमति दें।', noRec: 'यह ब्राउज़र वॉइस रिकॉर्ड नहीं कर सकता।', tooBig: 'फ़ाइल बहुत बड़ी है', retry: 'नहीं गया — फिर भेजने के लिए दबाएँ', urgent: 'ज़रूरी', urgentOn: 'ज़रूरी — ऑफिस की घंटी बजेगी', urgentOnO: 'ज़रूरी — यह बातचीत ज़रूरी में रहेगी',
      earlier: 'पुराने संदेश', take: '🙋 मैं देखूँगा', close: '✅ बंद करें', reopen: '🔄 फिर खोलें', markUrg: '🔴 ज़रूरी करें', clearUrg: '⚪ ज़रूरी हटाएँ', openTicket: 'टिकट खोलें',
      closedNote: 'यह बातचीत बंद है — लिखने पर फिर खुलेगी।', cancelNote: 'ℹ️ रद्द और रिफ़ंड ऑफिस हाथ से तय करता है — यह सिर्फ़ अनुरोध भेजता है।',
      pushAsk: 'नए संदेश पर फ़ोन पर सूचना पाएँ', pushSub: 'पैनल बंद होने पर भी आती है।', pushOn: 'चालू करें', pushDone: 'सूचना चालू ✓', pushBlocked: 'ब्राउज़र सेटिंग में सूचना रुकी है।',
      newTitle: 'नया संदेश', tPerson: '👤 व्यक्ति', tTicket: '🎫 टिकट', tAnnounce: '📣 घोषणा', tReport: '🛡️ संदिग्ध टिकट', to: 'किसे', findPerson: 'नाम या कोड खोजें', pnr: 'PNR (जैसे SHG-1234)',
      message: 'संदेश', audience: 'किसे भेजें', audAll: 'सभी एजेंट और काउंटर', audAgents: 'सिर्फ़ एजेंट', audCounters: 'सिर्फ़ काउंटर', start: 'भेजें', cancel: 'रद्द',
      reached: '{n} लोगों को भेजा ✓', pickPerson: 'पहले व्यक्ति चुनें।', needPnr: 'PNR लिखें।', needMsg: 'संदेश लिखें।', hello: 'नमस्ते कहें 👋', ann: 'घोषणा',
      handled: '{n} देख रहे हैं', agent: 'एजेंट', counter: 'काउंटर', call: 'कॉल', sendFail: 'भेज नहीं सके', reportHint: 'नकली लगने वाले टिकट का PNR/कोड और आपने क्या देखा लिखें। ऑफिस को तुरंत पता चलेगा।',
      q_seat: '🪑 सीट बदलना', q_name: '✏️ नाम सुधार', q_paid: '💸 भुगतान प्रमाण', q_cancel: '❌ रद्द अनुरोध', q_pickup: '📍 पिकअप बदलना', q_done: '✅ हो गया',
      q_phone: '📞 नंबर सुधार', q_date: '📅 तारीख बदलना', q_fare: '₹ किराया सुधार', q_agent: '🧑‍💼 एजेंट बदलना', q_check: '👀 देख रहे हैं', q_call: '📞 कॉल कर रहा हूँ', q_shot: '📎 स्क्रीनशॉट भेजें', q_wait: '🕐 10 मिनट', q_cancelOk: '❌ रद्द अनुरोध मिला' }
  };
  /* what a quick reply writes (bilingual on purpose — the reader may be on any language) */
  var QT = {
    q_seat: 'सिट परिवर्तन अनुरोध · Seat change — {PNR}: नयाँ सिट / new seat: ',
    q_name: 'नाम सच्याउनु पर्‍यो · Name correction — {PNR}: सही नाम / correct name: ',
    q_paid: 'भुक्तानी प्रमाण पठाइयो · Payment proof sent — {PNR}. कृपया जाँच गर्नुहोस् / please verify.',
    q_cancel: 'रद्द अनुरोध · Cancel request — {PNR}: यात्रुले टिकट रद्द गर्न चाहनुहुन्छ / passenger wants to cancel. कारण / reason: ',
    q_pickup: 'चढ्ने ठाउँ परिवर्तन · Pickup change — {PNR}: नयाँ ठाउँ / new pickup: ',
    q_phone: 'मोबाइल नम्बर सच्याउनु पर्‍यो · Phone correction — {PNR}: सही नम्बर / correct number: ',
    q_date: 'मिति परिवर्तन अनुरोध · Date change — {PNR}: नयाँ मिति / new date: ',
    q_fare: 'भाडा सच्याउनु पर्‍यो · Fare correction — {PNR}: सही भाडा / correct fare ₹',
    q_agent: 'एजेन्ट परिवर्तन · Agent change — {PNR}: सही एजेन्ट कोड / correct agent code: ',
    q_done: '✅ भयो · Done.',
    q_check: '👀 हेर्दैछौं — छिट्टै जानकारी दिन्छौं · Checking — will update you shortly.',
    q_call: '📞 तपाईंलाई फोन गर्दैछु · Calling you now.',
    q_shot: '📎 कृपया भुक्तानीको स्क्रिनसट पठाउनुहोस् · Please send the payment screenshot.',
    q_wait: '🕐 कृपया १० मिनेट पर्खनुहोस् · Please wait 10 minutes.',
    q_cancelOk: '❌ रद्द अनुरोध मिल्यो — अफिसले टिकट पेजबाट हातैले मिलाउँछ · Cancel request received — the office settles it by hand from the ticket page.'
  };
  // 15 Oct 2026: phone / date / fare / agent join the field chips (Quick Edit "Wrong …").
  var QF = B.office ? ['q_done', 'q_check', 'q_call', 'q_shot', 'q_wait', 'q_cancelOk'] : ['q_seat', 'q_name', 'q_phone', 'q_date', 'q_pickup', 'q_fare', 'q_paid', 'q_agent', 'q_cancel'];

  var LANGS = ['ne', 'en', 'hi'], LBL = { ne: 'ने', en: 'EN', hi: 'हि' };
  var lang = 'ne'; try { var sl = localStorage.getItem('shg_chat_lang'); if (LANGS.indexOf(sl) > -1) lang = sl; } catch (e) {}
  function t(k, n) { var s = (W[lang] && W[lang][k]) || W.en[k] || k; return n !== undefined ? s.replace('{n}', n) : s; }
  function applyLang() {
    document.querySelectorAll('#scApp [data-t], #scDlg [data-t]').forEach(function (el) { el.textContent = t(el.getAttribute('data-t')); });
    document.querySelectorAll('#scApp [data-ph]').forEach(function (el) { el.setAttribute('placeholder', t(el.getAttribute('data-ph'))); });
    $('scLang').textContent = LBL[lang];
    renderQuick();
  }

  /* ---------- helpers ---------- */
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function feel(n) { try { if (window.SHGFeel && SHGFeel.play) SHGFeel.play(n); } catch (e) {} }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); (window.crypto || {}).getRandomValues ? crypto.getRandomValues(b) : b.forEach(function (_, i) { b[i] = Math.random() * 256 | 0; });
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20, 32);
  }
  function toast(msg) { var el = $('scToast'); el.textContent = msg; el.hidden = false; clearTimeout(el.__h); el.__h = setTimeout(function () { el.hidden = true; }, 3800); }
  function get(params) {
    var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(API + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
  }
  function post(body, form) {
    var opt = { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF } };
    if (form) { opt.body = form; } else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(API, opt).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
  }
  function initials(s) { var p = String(s || '?').trim().split(/\s+/); return ((p[0] || '?').charAt(0) + (p[1] ? p[1].charAt(0) : '')).toUpperCase(); }
  function kindIcon(k) { return { general: '💬', booking: '🎫', settlement: '🧾', payout: '🏦', verify: '🛡️' }[k] || '💬'; }
  function hm(ts) { var d = new Date(ts * 1000); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
  function dayLabel(ts) {
    var d = new Date(ts * 1000), n = new Date(), y = new Date(); y.setDate(n.getDate() - 1);
    if (dayKey(ts) === dayKey(n.getTime() / 1000)) return t('today');
    if (dayKey(ts) === dayKey(y.getTime() / 1000)) return t('yesterday');
    try { return d.toLocaleDateString(lang === 'en' ? 'en-IN' : (lang === 'hi' ? 'hi-IN' : 'ne-NP'), { day: 'numeric', month: 'short' }); } catch (e) { return d.toDateString(); }
  }
  function listTime(ts) { if (!ts) return ''; return dayKey(ts) === dayKey(Date.now() / 1000) ? hm(ts) : dayLabel(ts); }
  function ago(ts) {
    if (!ts) return t('never');
    var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
    var v = s < 3600 ? Math.max(1, Math.round(s / 60)) + (lang === 'en' ? ' min ago' : (lang === 'hi' ? ' मिनट पहले' : ' मिनेट अघि'))
          : s < 86400 ? Math.round(s / 3600) + (lang === 'en' ? ' h ago' : (lang === 'hi' ? ' घंटे पहले' : ' घण्टा अघि'))
          : dayLabel(ts) + ' ' + hm(ts);
    return t('lastSeen') + ' ' + v;
  }
  function fmtSecs(s) { s = Math.max(0, Math.round(s || 0)); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
  function kb(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  /* text -> safe HTML with PNR chips (never raw links off the site) */
  function rich(s) {
    return esc(s).replace(/\b(SHG-[A-Z0-9-]{3,24})\b/g, function (m) { return '<a class="pn" href="/admin/booking-view.php?pnr=' + encodeURIComponent(m) + '">' + m + '</a>'; });
  }

  /* ---------- state ---------- */
  var S = { filter: 'open', q: '', threads: [], open: 0, meta: null, msgs: [], lastId: 0, firstId: 0, peerRead: 0, more: false,
    urgent: false, file: null, fileVoice: false, rec: null, polling: null, listPoll: null, busy: false };

  /* ---------- the list ---------- */
  function loadInbox() {
    return get({ action: 'inbox', filter: B.office ? S.filter : '', q: S.q }).then(function (j) {
      if (!j || !j.ok) { $('scThreads').innerHTML = '<div class="sc-empty">' + esc((j && j.error) || 'Error') + '</div>'; return; }
      S.threads = j.threads || [];
      renderInbox();
      paintBadge(j.unread || 0);
    }).catch(function () {});
  }
  function paintBadge(n) {
    var txt = n > 99 ? '99+' : String(n);
    var nb = $('navBadgeChat'); if (nb) { nb.textContent = n > 0 ? txt : ''; nb.style.display = n > 0 ? 'inline-block' : 'none'; }
    var tn = $('tbChatN'); if (tn) { tn.textContent = txt; tn.hidden = n <= 0; }
    var tb = $('tbChat'); if (tb) tb.classList.toggle('has', n > 0);
  }
  function renderInbox() {
    var box = $('scThreads');
    if (!S.threads.length) {
      box.innerHTML = '<div class="sc-empty"><div class="sc-big" aria-hidden="true">🗂️</div>' + esc(S.q || S.filter !== 'open' ? t('emptyFilter') : t('emptyOffice')) + '</div>';
      return;
    }
    box.innerHTML = S.threads.map(function (th) {
      var title = B.office ? th.title : (th.kind === 'general' ? t('titleField') + ' · ' + t('office') : th.title);
      var sub = th.lastPreview ? ((th.lastSide === (B.office ? 'office' : 'staff') ? t('you') + ': ' : '') + th.lastPreview) : (th.kind === 'general' ? t('hello') : th.subject);
      var tags = '';
      if (B.office) {
        tags += '<span class="sc-tag">' + esc(th.agent.role === 'counter' ? t('counter') : t('agent')) + (th.agent.code ? ' · ' + esc(th.agent.code) : '') + '</span>';
        if (th.kind !== 'general') tags += '<span class="sc-tag">' + kindIcon(th.kind) + ' ' + esc(th.pnr || th.subject) + '</span>';
      }
      if (th.priority === 'urgent' && th.status === 'open') tags += '<span class="sc-tag urgent">🔴 ' + esc(t('urgent')) + '</span>';
      if (th.status === 'closed') tags += '<span class="sc-tag closed">' + esc(t('fClosed')) + '</span>';
      var av = B.office ? esc(initials(th.agent.name)) : kindIcon(th.kind);
      return '<button type="button" class="sc-t' + (th.unread > 0 ? ' unread' : '') + (th.id === S.open ? ' on' : '') + '" data-id="' + th.id + '">'
        + '<span class="sc-av k-' + esc(th.kind) + '" aria-hidden="true">' + av + '</span>'
        + '<span class="sc-tm"><span class="sc-tr"><span class="sc-tn">' + esc(title) + '</span><span class="sc-tt">' + esc(listTime(th.lastAt)) + '</span></span>'
        + '<span class="sc-tr"><span class="sc-tp">' + esc(sub) + '</span>' + (th.unread > 0 ? '<span class="sc-un">' + (th.unread > 99 ? '99+' : th.unread) + '</span>' : '') + '</span>'
        + (tags ? '<span class="sc-tr" style="gap:4px;flex-wrap:wrap">' + tags + '</span>' : '') + '</span></button>';
    }).join('');
  }
  $('scThreads').addEventListener('click', function (e) {
    var b = e.target.closest('.sc-t'); if (!b) return;
    feel('tap'); openThread(parseInt(b.getAttribute('data-id'), 10), true);
  });
  var qTimer = null;
  $('scQ').addEventListener('input', function () { clearTimeout(qTimer); var v = this.value; qTimer = setTimeout(function () { S.q = v.trim(); loadInbox(); }, 280); });
  if ($('scFilters')) $('scFilters').addEventListener('click', function (e) {
    var c = e.target.closest('.sc-chip'); if (!c) return;
    feel('select');
    this.querySelectorAll('.sc-chip').forEach(function (x) { x.classList.toggle('on', x === c); });
    S.filter = c.getAttribute('data-f'); loadInbox();
  });

  /* ---------- a conversation ---------- */
  function isPhone() { return window.matchMedia('(max-width: 820px)').matches; }
  function openThread(id, push) {
    if (!id) return;
    if (S.open !== id) { S.msgs = []; S.lastId = 0; S.firstId = 0; S.peerRead = 0; clearFile(); }
    S.open = id; window.__shgChatOpen = id;
    $('scPick').hidden = true; $('scThread').hidden = false;
    document.body.classList.add('sc-open');
    if (push) {
      try {
        if (S.pushed) history.replaceState({ t: id }, '', '/admin/staff-chat.php?t=' + id);
        else { history.pushState({ t: id }, '', '/admin/staff-chat.php?t=' + id); S.pushed = true; }
      } catch (e) {}
    }
    $('scMsgs').innerHTML = '<div class="sc-empty">' + esc(t('loading')) + '</div>';
    renderInbox();
    return get({ action: 'thread', id: id, after: 0 }).then(function (j) {
      if (!j || !j.ok) { $('scMsgs').innerHTML = '<div class="sc-empty"><div class="sc-big">🔒</div>' + esc((j && j.error) || 'Error') + '</div>'; return; }
      S.meta = j.thread; S.msgs = j.messages || []; S.peerRead = j.peerRead || 0; S.more = !!j.more;
      S.lastId = S.msgs.length ? S.msgs[S.msgs.length - 1].id : 0; S.firstId = S.msgs.length ? S.msgs[0].id : 0;
      paintHeader(); renderMsgs(true); startPoll(); loadInbox();
      if (!isPhone()) $('scText').focus({ preventScroll: true });
    });
  }
  function closeThread(fromPop) {
    document.body.classList.remove('sc-open');
    stopPoll(); window.__shgChatOpen = 0;
    if (playing) { try { playing.pause(); } catch (e) {} playing = null; }
    S.open = 0; S.meta = null;
    $('scThread').hidden = true; $('scPick').hidden = false;
    if (!fromPop) { try { history.replaceState({}, '', '/admin/staff-chat.php'); } catch (e) {} }
    loadInbox();
  }
  /* back = the list. Only step the browser history back when this page itself
     pushed the conversation (otherwise "back" would leave the chat). */
  $('scBack').addEventListener('click', function () { feel('nav'); if (S.pushed) { S.pushed = false; history.back(); } else closeThread(); });
  window.addEventListener('popstate', function (e) {
    var id = e.state && e.state.t;
    if (id) openThread(id, false); else { S.pushed = false; closeThread(true); }
  });

  function paintHeader() {
    var m = S.meta; if (!m) return;
    var title = B.office ? m.agent.name : (m.kind === 'general' ? t('titleField') : ((m.kind === 'booking' || m.kind === 'verify') && m.pnr ? m.pnr : m.title));
    $('scTitle').textContent = title;
    var av = $('scAv'); av.className = 'sc-av k-' + m.kind; av.textContent = B.office ? initials(m.agent.name) : kindIcon(m.kind);
    var p = m.presence || {}, pres = $('scPres');
    var sub = B.office ? ((m.agent.role === 'counter' ? t('counter') : t('agent')) + (m.agent.code ? ' · ' + m.agent.code : '') + ' · ') : '';
    if (p.online) { pres.className = 'sc-pres on'; pres.textContent = sub + (B.office ? t('online') : t('officeOn')); }
    else { pres.className = 'sc-pres'; pres.textContent = sub + (B.office ? ago(p.at) : t('officeOff')); }
    if (B.office && m.assignedName) pres.textContent += ' · ' + t('handled', m.assignedName);
    var pn = $('scPnr');
    if (m.pnr && m.bookingUrl) { pn.hidden = false; pn.href = m.bookingUrl; pn.textContent = '🎫 ' + m.pnr + ' ↗'; pn.title = t('openTicket'); }
    else if (m.pnr) { pn.hidden = false; pn.removeAttribute('href'); pn.textContent = '🛡️ ' + m.pnr; }
    else { pn.hidden = true; }
    var acts = (m.contacts || []).map(function (c) {
      return '<a class="' + (c.kind === 'wa' ? 'wa' : 'tel') + '" href="' + esc(c.href) + '"' + (c.kind === 'wa' ? ' target="_blank" rel="noopener"' : '') + ' title="' + esc(c.label) + '" aria-label="' + esc(c.label) + '">'
        + (c.kind === 'wa' ? '<svg class="a-ic"><use href="#a-whatsapp"/></svg>' : '<svg class="a-ic"><use href="#a-phone"/></svg>') + '</a>';
    }).join('');
    if (m.pnr && m.bookingUrl) acts = '<a class="sc-pnr-btn" href="' + esc(m.bookingUrl) + '" title="' + esc(t('openTicket')) + '" aria-label="' + esc(t('openTicket')) + '"><svg class="a-ic"><use href="#a-ticket"/></svg></a>' + acts;
    if (B.office) acts += '<button type="button" id="scKebab" aria-label="More" aria-haspopup="menu">⋮</button>';
    $('scActs').innerHTML = acts;
    var kb2 = $('scKebab'); if (kb2) kb2.onclick = toggleMenu;
    $('scClosed').hidden = m.status !== 'closed';
    var ca = document.querySelector('.sc-pnr-btn'); if (ca && !isPhone()) ca.style.display = 'none';
  }
  function toggleMenu(e) {
    if (e) e.stopPropagation();
    var menu = $('scMenu'), m = S.meta || {};
    if (!menu.hidden) { menu.hidden = true; return; }
    var items = [];
    if (m.assignedTo !== B.me) items.push(['assign', t('take')]);
    items.push(m.status === 'closed' ? ['reopen', t('reopen')] : ['close', t('close')]);
    items.push(m.priority === 'urgent' ? ['unurgent', t('clearUrg')] : ['urgent', t('markUrg')]);
    menu.innerHTML = items.map(function (i) { return '<button type="button" role="menuitem" data-a="' + i[0] + '">' + esc(i[1]) + '</button>'; }).join('');
    menu.hidden = false;
  }
  document.addEventListener('click', function (e) { var menu = $('scMenu'); if (!menu.hidden && !e.target.closest('#scMenu')) menu.hidden = true; });
  $('scMenu').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-a]'); if (!b) return;
    var a = b.getAttribute('data-a'), body = { thread_id: S.open };
    if (a === 'assign') { body.action = 'assign'; body.to = B.me; }
    else if (a === 'close' || a === 'reopen') { body.action = a; }
    else { body.action = 'urgent'; body.on = a === 'urgent' ? 1 : 0; }
    $('scMenu').hidden = true;
    post(body).then(function (j) {
      if (!j || !j.ok) { toast((j && j.error) || t('sendFail')); return; }
      feel('success');
      if (a === 'assign') { S.meta.assignedTo = B.me; S.meta.assignedName = j.assignedName || B.name; }
      if (a === 'close' || a === 'reopen') S.meta.status = j.status;
      if (body.action === 'urgent') S.meta.priority = j.priority;
      paintHeader(); pollOnce(); loadInbox();
    });
  });

  /* messages */
  function msgHtml(m, prev) {
    var h = '';
    if (!prev || dayKey(prev.ts) !== dayKey(m.ts)) h += '<div class="sc-day">' + esc(dayLabel(m.ts)) + '</div>';
    if (m.side === 'system') return h + '<div class="sc-sys" data-id="' + m.id + '">' + rich(m.body) + ' · ' + hm(m.ts) + '</div>';
    if (m.kind === 'announce') {
      return h + '<div class="sc-ann" data-id="' + m.id + '"><div class="ah">📣 ' + esc(t('ann')) + (m.who ? ' · ' + esc(m.who) : '') + '</div><div class="tx">' + rich(m.body) + '</div>'
        + '<div class="sc-dur" style="text-align:right;margin-top:4px">' + hm(m.ts) + '</div></div>';
    }
    var sameRun = prev && prev.side === m.side && prev.kind !== 'announce' && prev.side !== 'system' && dayKey(prev.ts) === dayKey(m.ts) && (prev.who === m.who) && (m.ts - prev.ts < 600);
    if (!sameRun) {
      if (!m.mine) h += '<div class="sc-who">' + esc(B.office ? (m.who || t('agent')) : (t('office') + (m.who ? ' · ' + m.who : ''))) + '</div>';
      else if (!m.self && m.who) h += '<div class="sc-who r">' + esc(m.who) + '</div>';
    }
    var cls = 'sc-b ' + (m.mine ? 'me' : 'them') + (m.urgent ? ' urgent' : '') + (m.pending ? ' pending' : '') + (m.failed ? ' failed' : '');
    var inner = m.urgent ? '<span class="ur">🔴 ' + esc(t('urgent')) + '</span>' : '';
    var f = m.file;
    if (f && f.url) {
      if (/^image\//.test(f.mime)) inner += '<a href="' + esc(f.url) + '" target="_blank" rel="noopener"><img class="ph" loading="lazy" src="' + esc(f.url) + '" alt="' + esc(t('photo')) + '"></a>';
      else if (f.mime === 'application/pdf') inner += '<a class="sc-doc" href="' + esc(f.url) + '" target="_blank" rel="noopener"><span>📄</span><b>PDF</b><small>' + esc(kb(f.size || 0)) + '</small></a>';
      else inner += voiceHtml(f);
    } else if (f && f.local) {
      inner += f.kind === 'voice' ? '<div class="sc-voice"><span class="sc-play">🎤</span><span class="sc-dur">' + fmtSecs(f.secs) + '</span></div>'
            : (f.preview ? '<img class="ph" src="' + esc(f.preview) + '" alt="">' : '<div class="sc-doc"><span>📄</span><b>' + esc(f.name || 'PDF') + '</b></div>');
    }
    if (m.body) inner += '<div class="tx">' + rich(m.body) + '</div>';
    var tick = '';
    if (m.mine) tick = m.failed ? '⚠️' : (m.pending ? '🕓' : (m.id <= S.peerRead ? '<span class="tk seen" title="Seen">✓✓</span>' : '<span class="tk" title="Sent">✓</span>'));
    inner += '<div class="mt">' + (m.failed ? esc(t('retry')) + ' · ' : '') + hm(m.ts) + ' ' + tick + '</div>';
    return h + '<div class="' + cls + '" data-id="' + (m.id || '') + '" data-uuid="' + esc(m.uuid || '') + '">' + inner + '</div>';
  }
  function voiceHtml(f) {
    return '<div class="sc-voice" data-src="' + esc(f.url) + '" data-secs="' + (f.secs || 0) + '"><button type="button" class="sc-play" aria-label="Play">▶</button>'
      + '<div class="sc-wave-w"><div class="sc-wave"></div><div class="sc-prog"><div class="sc-wave"></div></div></div><span class="sc-dur">' + fmtSecs(f.secs) + '</span></div>';
  }
  function renderMsgs(toBottom) {
    // A voice note is playing: redraw once it stops (a redraw would orphan its player).
    if (playing && !playing.paused && !toBottom) { S.dirty = true; return; }
    S.dirty = false;
    var box = $('scMsgs'), atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
    var h = S.more ? '<button type="button" class="sc-earlier" id="scEarlier">' + esc(t('earlier')) + '</button>' : '';
    if (!S.msgs.length) h += '<div class="sc-empty"><div class="sc-big" aria-hidden="true">👋</div>' + esc(t('hello')) + '</div>';
    for (var i = 0; i < S.msgs.length; i++) h += msgHtml(S.msgs[i], S.msgs[i - 1]);
    box.innerHTML = h;
    var e2 = $('scEarlier'); if (e2) e2.onclick = loadEarlier;
    var stick = toBottom || atBottom;
    if (stick) box.scrollTop = box.scrollHeight;
    /* photos arrive after the text: keep the newest line in view while they grow the list */
    if (stick) box.querySelectorAll('img.ph').forEach(function (im) {
      if (!im.complete) im.addEventListener('load', function () { if (box.scrollHeight - box.scrollTop - box.clientHeight < im.clientHeight + 160) box.scrollTop = box.scrollHeight; }, { once: true });
    });
  }
  function loadEarlier() {
    var box = $('scMsgs'), h0 = box.scrollHeight;
    get({ action: 'thread', id: S.open, after: 0, before: S.firstId }).then(function (j) {
      if (!j || !j.ok) return;
      var older = j.messages || [];
      S.more = older.length >= 60;
      if (older.length) { S.msgs = older.concat(S.msgs); S.firstId = older[0].id; }
      renderMsgs(false);
      box.scrollTop = box.scrollHeight - h0;
    });
  }
  function merge(list) {
    var changed = false;
    (list || []).forEach(function (m) {
      for (var i = 0; i < S.msgs.length; i++) {
        var x = S.msgs[i];
        if ((x.id && x.id === m.id) || (m.uuid && x.uuid === m.uuid)) { S.msgs[i] = m; changed = true; return; }
      }
      S.msgs.push(m); changed = true;
      if (!m.mine && m.side !== 'system') feel('notify');
    });
    S.msgs.sort(function (a, b) { return (a.id || 9e15) - (b.id || 9e15); });
    var real = S.msgs.filter(function (m) { return m.id; });
    S.lastId = real.length ? real[real.length - 1].id : S.lastId;
    return changed;
  }

  /* polling: the open thread every 4 s while visible */
  function pollOnce() {
    if (!S.open || document.hidden) return Promise.resolve();
    var id = S.open;
    return get({ action: 'thread', id: id, after: S.lastId }).then(function (j) {
      if (!j || !j.ok || id !== S.open) return;
      var pr = j.peerRead || 0, ch = merge(j.messages);
      if (pr !== S.peerRead) { S.peerRead = pr; ch = true; }
      if (S.meta && (j.status !== S.meta.status || j.priority !== S.meta.priority)) { S.meta.status = j.status; S.meta.priority = j.priority; paintHeader(); }
      if (ch) renderMsgs(false);
    }).catch(function () {});
  }
  function startPoll() { stopPoll(); S.polling = setInterval(pollOnce, 4000); }
  function stopPoll() { if (S.polling) clearInterval(S.polling); S.polling = null; }
  S.listPoll = setInterval(function () { if (!document.hidden && (!isPhone() || !document.body.classList.contains('sc-open'))) loadInbox(); }, 12000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { pollOnce(); loadInbox(); } });
  var seenPoll = 0;
  document.addEventListener('shg-chat-poll', function (e) {
    var c = e.detail || {};
    if (c.last && c.last > S.lastId && c.t === S.open) pollOnce();
    if (c.last && c.last !== seenPoll) { seenPoll = c.last; loadInbox(); }
  });

  /* voice playback */
  var playing = null;
  $('scMsgs').addEventListener('click', function (e) {
    var fail = e.target.closest('.sc-b.failed'); if (fail) { retry(fail.getAttribute('data-uuid')); return; }
    var v = e.target.closest('.sc-voice[data-src]'); if (!v) return;
    var au = v.__a;
    if (!au) {
      au = v.__a = new Audio(v.getAttribute('data-src')); au.preload = 'metadata';
      var prog = v.querySelector('.sc-prog'), dur = v.querySelector('.sc-dur'), btn = v.querySelector('.sc-play');
      var w = v.querySelector('.sc-wave-w'); if (w) prog.querySelector('.sc-wave').style.setProperty('--w', w.clientWidth + 'px');
      au.addEventListener('timeupdate', function () { var d = au.duration && isFinite(au.duration) ? au.duration : (+v.getAttribute('data-secs') || 1); prog.style.width = Math.min(100, au.currentTime / d * 100) + '%'; dur.textContent = fmtSecs(au.currentTime); });
      au.addEventListener('ended', function () { btn.textContent = '▶'; prog.style.width = '0%'; dur.textContent = fmtSecs(+v.getAttribute('data-secs')); playing = null; if (S.dirty) renderMsgs(false); });
      au.addEventListener('pause', function () { btn.textContent = '▶'; if (S.dirty) setTimeout(function () { if (!playing || playing.paused) { playing = null; renderMsgs(false); } }, 50); });
      au.addEventListener('play', function () { btn.textContent = '❚❚'; });
    }
    if (e.target.closest('.sc-wave-w') && au.duration && isFinite(au.duration)) {
      var r = e.target.closest('.sc-wave-w').getBoundingClientRect(); au.currentTime = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * au.duration;
      return;
    }
    if (playing && playing !== au) playing.pause();
    if (au.paused) { au.play().catch(function () {}); playing = au; } else { au.pause(); }
  });

  /* ---------- composer ---------- */
  var ta = $('scText');
  function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; paintSend(); }
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !isPhone()) { e.preventDefault(); sendNow(); } });
  function hasContent() { return ta.value.trim() !== '' || !!S.file; }
  function paintSend() { $('scSend').innerHTML = hasContent() ? '<svg><use href="#sc-send"/></svg>' : '<svg><use href="#sc-mic"/></svg>'; $('scSend').setAttribute('aria-label', hasContent() ? 'Send' : t('voice')); }
  $('scSend').addEventListener('click', function () { if (hasContent()) sendNow(); else startRec(); });
  function renderQuick() {
    var pnr = (S.meta && S.meta.pnr) || 'SHG-____';
    var h = QF.map(function (k) { return '<button type="button" data-q="' + k + '">' + esc(t(k)) + '</button>'; }).join('');
    h += '<button type="button" class="urg' + (S.urgent ? ' on' : '') + '" data-q="urgent" aria-pressed="' + (S.urgent ? 'true' : 'false') + '">🔴 ' + esc(t('urgent')) + '</button>';
    $('scQuick').innerHTML = h;
    $('scQuick').__pnr = pnr;
  }
  $('scQuick').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-q]'); if (!b) return;
    var k = b.getAttribute('data-q');
    feel('select');
    if (k === 'urgent') { S.urgent = !S.urgent; renderQuick(); note(S.urgent ? t(B.office ? 'urgentOnO' : 'urgentOn') : ''); return; }
    var pnr = (S.meta && S.meta.pnr) || 'SHG-____';
    ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + QT[k].replace('{PNR}', pnr);
    grow(); ta.focus();
    var end = ta.value.length; try { ta.setSelectionRange(end, end); } catch (err) {}
    note(k === 'q_cancel' || k === 'q_cancelOk' ? t('cancelNote') : '');
  });
  function note(s) { var n = $('scNote'); n.textContent = s; n.hidden = !s; }

  /* attachments */
  $('scAttach').addEventListener('click', function () { if (!B.attach) { toast('—'); return; } var m = $('scAttachMenu'); m.hidden = !m.hidden; });
  $('scFileImg').addEventListener('change', function () { if (this.files[0]) pickFile(this.files[0]); this.value = ''; });
  $('scFilePdf').addEventListener('change', function () { if (this.files[0]) pickFile(this.files[0]); this.value = ''; });
  function pickFile(f) {
    $('scAttachMenu').hidden = true;
    if (/^image\//.test(f.type)) {
      shrink(f).then(function (blob) { setFile(blob, f.name.replace(/\.[^.]+$/, '') + '.jpg'); });
    } else {
      if (f.size > B.fileMax) { toast(t('tooBig') + ' (' + kb(B.fileMax) + ')'); return; }
      setFile(f, f.name);
    }
  }
  /* a phone photo is 3–8 MB; 1600 px JPEG keeps it readable at ~300 KB */
  function shrink(f) {
    return new Promise(function (res) {
      if (f.size < 900000 && /jpe?g|png|webp/.test(f.type)) { res(f); return; }
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = function () {
        var s = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function (b) { res(b || f); }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); res(f); };
      img.src = url;
    });
  }
  function setFile(blob, name) {
    if (blob.size > B.fileMax) { toast(t('tooBig') + ' (' + kb(B.fileMax) + ')'); return; }
    S.file = blob; S.fileName = name; S.fileVoice = false;
    var isImg = /^image\//.test(blob.type);
    var pi = $('scPrevImg');
    if (isImg) { if (S.filePreview) URL.revokeObjectURL(S.filePreview); S.filePreview = URL.createObjectURL(blob); pi.src = S.filePreview; pi.hidden = false; } else { pi.hidden = true; S.filePreview = ''; }
    $('scPrevName').textContent = (isImg ? '📷 ' : '📄 ') + name + ' · ' + kb(blob.size);
    $('scPrev').hidden = false; paintSend(); ta.focus();
  }
  function clearFile() { S.file = null; S.fileName = ''; $('scPrev').hidden = true; paintSend(); }
  $('scPrevX').addEventListener('click', clearFile);

  /* voice notes (MediaRecorder; ≤ 2 MB; Safari records mp4) */
  function pickMime() {
    var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < c.length; i++) { try { if (window.MediaRecorder && MediaRecorder.isTypeSupported(c[i])) return c[i]; } catch (e) {} }
    return '';
  }
  function startRec() {
    if (!B.attach) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) { toast(t('noRec')); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var mime = pickMime(), rec;
      try { rec = mime ? new MediaRecorder(stream, { mimeType: mime, audioBitsPerSecond: 32000 }) : new MediaRecorder(stream); } catch (e) { rec = new MediaRecorder(stream); }
      var chunks = [], size = 0, t0 = Date.now();
      S.rec = { rec: rec, stream: stream, chunks: chunks, t0: t0, cancel: false };
      rec.ondataavailable = function (e) { if (e.data && e.data.size) { chunks.push(e.data); size += e.data.size; if (size > B.voiceMax * 0.95) stopRec(true); } };
      rec.onstop = function () {
        stream.getTracks().forEach(function (tr) { tr.stop(); });
        var r = S.rec; S.rec = null; clearInterval(timer);
        $('scRecBar').hidden = true; $('scRow').hidden = false;
        if (!r || r.cancel || !chunks.length) return;
        var type = (rec.mimeType || mime || 'audio/webm').split(';')[0];
        var blob = new Blob(chunks, { type: type });
        var ext = /mp4/.test(type) ? 'm4a' : (/ogg/.test(type) ? 'ogg' : 'webm');
        var secs = Math.round((Date.now() - r.t0) / 1000);
        if (blob.size > B.voiceMax) { toast(t('tooBig')); return; }
        sendMessage({ body: '', file: blob, fileName: 'voice.' + ext, voice: true, secs: secs });
      };
      rec.start(1000);
      feel('toggleOn');
      $('scRow').hidden = true; $('scRecBar').hidden = false; $('scRecT').textContent = '0:00';
      var timer = setInterval(function () {
        var s = (Date.now() - t0) / 1000; $('scRecT').textContent = '● ' + t('recording') + ' ' + fmtSecs(s);
        if (s >= 120) stopRec(true);
      }, 250);
    }).catch(function () { toast(t('micDenied')); });
  }
  function stopRec(send) { if (!S.rec) return; S.rec.cancel = !send; try { S.rec.rec.stop(); } catch (e) {} feel(send ? 'success' : 'toggleOff'); }
  $('scRecSend').addEventListener('click', function () { stopRec(true); });
  $('scRecX').addEventListener('click', function () { stopRec(false); });

  /* sending (optimistic bubble, uuid = one message per tap) */
  function sendNow() {
    if (S.busy) return;
    var body = ta.value.trim();
    if (!body && !S.file) return;
    var f = S.file, fn = S.fileName;
    ta.value = ''; grow(); clearFile(); note('');
    sendMessage({ body: body, file: f, fileName: fn, voice: false, secs: 0 });
  }
  var outbox = {};
  function sendMessage(o) {
    if (!S.open) return;
    var u = uuid();
    var temp = { id: 0, uuid: u, side: B.office ? 'office' : 'staff', kind: o.file ? (o.voice ? 'voice' : (/^image\//.test(o.file.type) ? 'image' : 'pdf')) : 'text', body: o.body, ts: Math.floor(Date.now() / 1000),
      mine: true, self: true, who: B.name, urgent: S.urgent, pending: true,
      file: o.file ? { local: true, kind: o.voice ? 'voice' : 'file', secs: o.secs, name: o.fileName, preview: (!o.voice && /^image\//.test(o.file.type)) ? URL.createObjectURL(o.file) : '' } : null };
    outbox[u] = { o: o, urgent: S.urgent, thread: S.open };
    S.msgs.push(temp); renderMsgs(true);
    deliver(u);
    if (S.urgent) { S.urgent = false; renderQuick(); }
  }
  function deliver(u) {
    var job = outbox[u]; if (!job) return;
    var o = job.o, req;
    if (o.file) {
      var fd = new FormData();
      fd.append('action', 'send'); fd.append('thread_id', job.thread); fd.append('body', o.body || ''); fd.append('client_uuid', u);
      fd.append('urgent', job.urgent ? '1' : '0');
      if (o.voice) { fd.append('voice', '1'); fd.append('secs', String(o.secs || 0)); }
      fd.append('attach', o.file, o.fileName || 'file');
      req = post(null, fd);
    } else {
      req = post({ action: 'send', thread_id: job.thread, body: o.body, client_uuid: u, urgent: job.urgent ? 1 : 0 });
    }
    req.then(function (j) {
      if (!j || !j.ok) throw new Error((j && j.error) || t('sendFail'));
      delete outbox[u];
      feel('success');
      if (j.message && job.thread === S.open) { merge([j.message]); renderMsgs(true); }
      loadInbox();
    }).catch(function (err) {
      S.msgs.forEach(function (m) { if (m.uuid === u && !m.id) { m.pending = false; m.failed = true; } });
      if (job.thread === S.open) renderMsgs(false);
      feel('error'); toast(String(err && err.message || t('sendFail')));
    });
  }
  function retry(u) {
    if (!u || !outbox[u]) return;
    S.msgs.forEach(function (m) { if (m.uuid === u) { m.failed = false; m.pending = true; } });
    renderMsgs(false); deliver(u);
  }

  /* ---------- new conversation sheet ---------- */
  var people = null, dlgMode = '', dlgPick = 0;
  function dlgTabs() {
    var tabs = B.office ? [['person', t('tPerson')], ['ticket', t('tTicket')]].concat(B.broadcast ? [['announce', t('tAnnounce')]] : [])
                        : [['ticket', t('tTicket')], ['report', t('tReport')]];
    $('scDlgTabs').innerHTML = tabs.map(function (x) { return '<button type="button" class="sc-chip' + (x[0] === dlgMode ? ' on' : '') + '" data-m="' + x[0] + '">' + esc(x[1]) + '</button>'; }).join('');
  }
  function dlgBody() {
    var h = '';
    if (dlgMode === 'person') {
      h = '<label class="f">' + esc(t('to')) + '<input type="search" id="scPplQ" placeholder="' + esc(t('findPerson')) + '" autocomplete="off"></label><div class="sc-ppl" id="scPpl"></div>';
    } else if (dlgMode === 'ticket' || dlgMode === 'report') {
      h = '<label class="f">' + esc(t('pnr')) + '<input type="text" id="scDlgPnr" autocomplete="off" autocapitalize="characters" maxlength="40" value="' + esc(B.pnr || '') + '"></label>';
      if (dlgMode === 'report') h += '<p class="muted" style="margin:0;font-size:13px">' + esc(t('reportHint')) + '</p>';
    } else if (dlgMode === 'announce') {
      h = '<label class="f">' + esc(t('audience')) + '<select id="scAud"><option value="all">' + esc(t('audAll')) + '</option><option value="agents">' + esc(t('audAgents')) + '</option><option value="counters">' + esc(t('audCounters')) + '</option></select></label>';
    }
    h += '<label class="f">' + esc(t('message')) + '<textarea id="scDlgMsg" rows="4" maxlength="2000"></textarea></label>';
    $('scDlgBody').innerHTML = h; $('scDlgErr').textContent = '';
    if (dlgMode === 'person') {
      var paint = function () {
        var q = ($('scPplQ').value || '').toLowerCase();
        var list = (people || []).filter(function (p) { return !q || (p.name + ' ' + p.code + ' ' + p.desk).toLowerCase().indexOf(q) > -1; }).slice(0, 80);
        $('scPpl').innerHTML = list.length ? list.map(function (p) {
          return '<button type="button" data-p="' + p.id + '" class="' + (p.id === dlgPick ? 'on' : '') + '"><span class="sc-av k-general" style="width:34px;height:34px;border-radius:12px;font-size:12px">' + esc(initials(p.name)) + '</span>'
            + '<span>' + esc(p.name) + '<br><small>' + esc(p.role === 'counter' ? t('counter') : t('agent')) + (p.code ? ' · ' + esc(p.code) : '') + (p.desk ? ' · ' + esc(p.desk) : '') + '</small></span></button>';
        }).join('') : '<div class="sc-empty" style="padding:14px">' + esc(people ? t('emptyFilter') : t('loading')) + '</div>';
      };
      $('scPplQ').addEventListener('input', paint);
      $('scPpl').addEventListener('click', function (e) { var b = e.target.closest('button[data-p]'); if (!b) return; dlgPick = parseInt(b.getAttribute('data-p'), 10); paint(); feel('select'); });
      if (!people) get({ action: 'people' }).then(function (j) { people = (j && j.field) || []; paint(); }); else paint();
    }
  }
  function openDlg(mode) {
    dlgMode = mode || (B.office ? 'person' : 'ticket'); dlgPick = B.agent || 0;
    dlgTabs(); dlgBody();
    var d = $('scDlg'); if (d.showModal) d.showModal(); else d.setAttribute('open', '');
    feel('sheetOpen');
  }
  $('scNewBtn').addEventListener('click', function () { openDlg(); });
  $('scDlgTabs').addEventListener('click', function (e) { var b = e.target.closest('[data-m]'); if (!b) return; dlgMode = b.getAttribute('data-m'); dlgTabs(); dlgBody(); });
  $('scDlgCancel').addEventListener('click', function () { $('scDlg').close(); feel('sheetClose'); });
  $('scDlgForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = ($('scDlgMsg').value || '').trim(), err = $('scDlgErr'), body = { client_uuid: uuid() };
    if (dlgMode === 'person') { if (!dlgPick) { err.textContent = t('pickPerson'); return; } body.action = 'open'; body.kind = 'general'; body.agent_id = dlgPick; body.body = msg; }
    else if (dlgMode === 'ticket') { var p = ($('scDlgPnr').value || '').trim(); if (!p) { err.textContent = t('needPnr'); return; } body.action = 'open'; body.kind = 'booking'; body.pnr = p; body.body = msg; }
    else if (dlgMode === 'report') { var p2 = ($('scDlgPnr').value || '').trim(); if (!p2) { err.textContent = t('needPnr'); return; } body.action = 'report'; body.pnr = p2; body.body = msg; }
    else if (dlgMode === 'announce') { if (!msg) { err.textContent = t('needMsg'); return; } body.action = 'broadcast'; body.audience = $('scAud').value; body.body = msg; }
    $('scDlgGo').disabled = true;
    post(body).then(function (j) {
      $('scDlgGo').disabled = false;
      if (!j || !j.ok) { err.textContent = (j && j.error) || t('sendFail'); feel('error'); return; }
      $('scDlg').close(); feel('success');
      if (dlgMode === 'announce') { toast(t('reached', j.reached || 0)); loadInbox(); return; }
      if (j.thread_id) openThread(j.thread_id, true);
    }).catch(function () { $('scDlgGo').disabled = false; err.textContent = t('sendFail'); });
  });

  /* ---------- phone push (opt-in) ---------- */
  function b64u(s) { var p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')), o = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) o[i] = r.charCodeAt(i); return o; }
  function pushSupported() { return B.push && B.push.on && B.push.key && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
  function pushSync(ask) {
    return navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (s) { return s || (ask ? reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(B.push.key) }) : null); });
    }).then(function (s) {
      if (!s) return false;
      return fetch('/admin/api/push.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action: 'subscribe', subscription: s.toJSON(), lang: lang }) }).then(function (r) { return r.json(); }).then(function (j) {
        if (j && j.ok) { try { localStorage.setItem('shg_staff_push', '1'); } catch (e) {} return true; }
        return false;
      });
    });
  }
  function pushPaint() {
    var box = $('scPush');
    if (!pushSupported()) { box.hidden = true; return; }
    var dismissed = false; try { dismissed = (localStorage.getItem('shg_staff_push_later') || 0) > Date.now(); } catch (e) {}
    if (Notification.permission === 'granted') {
      box.hidden = true;
      /* re-bind this handset to whoever is signed in — once per tab session (shared counter phones) */
      var synced = false; try { synced = sessionStorage.getItem('shg_staff_push_sync') === String(B.me); } catch (e) {}
      if (!synced) pushSync(false).then(function (ok) { if (ok) { try { sessionStorage.setItem('shg_staff_push_sync', String(B.me)); } catch (e) {} } }).catch(function () {});
      return;
    }
    if (Notification.permission === 'denied') { box.hidden = dismissed; $('scPushSub').textContent = t('pushBlocked'); $('scPushOn').hidden = true; return; }
    box.hidden = dismissed;
  }
  $('scPushOn').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') { pushPaint(); btn.disabled = false; return; }
      return pushSync(true).then(function (ok) { btn.disabled = false; if (ok) { toast(t('pushDone')); feel('toggleOn'); $('scPush').hidden = true; } });
    }).catch(function () { btn.disabled = false; });
  });
  $('scPushLater').addEventListener('click', function () { try { localStorage.setItem('shg_staff_push_later', String(Date.now() + 3 * 86400000)); } catch (e) {} $('scPush').hidden = true; });

  /* ---------- language ---------- */
  $('scLang').addEventListener('click', function () {
    lang = LANGS[(LANGS.indexOf(lang) + 1) % LANGS.length];
    try { localStorage.setItem('shg_chat_lang', lang); } catch (e) {}
    feel('select'); applyLang(); renderInbox(); if (S.meta) { paintHeader(); renderMsgs(false); }
  });

  /* ---------- start ---------- */
  applyLang(); paintSend(); pushPaint();
  loadInbox().then(function () {
    if (B.open) { openThread(B.open, false); try { history.replaceState({ t: B.open }, '', location.href); } catch (e) {} return; }
    if (B.pnr) {
      post({ action: 'open', kind: 'booking', pnr: B.pnr }).then(function (j) {
        if (j && j.ok && j.thread_id) {
          var opened = openThread(j.thread_id, false);
          try { history.replaceState({ t: j.thread_id }, '', '/admin/staff-chat.php?t=' + j.thread_id); } catch (e) {}
          // 15 Oct 2026: a Quick Edit "Wrong …" chip writes its request in the box (the staff member sends it).
          if (B.quick && QT[B.quick] && opened && opened.then) opened.then(function () {
            if (ta.value.trim()) return;
            ta.value = QT[B.quick].replace('{PNR}', (S.meta && S.meta.pnr) || B.pnr); grow();
            try { ta.focus({ preventScroll: true }); var end = ta.value.length; ta.setSelectionRange(end, end); } catch (e) {}
            note(B.quick === 'q_cancel' ? t('cancelNote') : '');
          });
        }
        else toast((j && j.error) || t('sendFail'));
      });
      return;
    }
    if (B.agent && B.office) {
      post({ action: 'open', kind: 'general', agent_id: B.agent }).then(function (j) {
        if (j && j.ok && j.thread_id) { openThread(j.thread_id, false); try { history.replaceState({ t: j.thread_id }, '', '/admin/staff-chat.php?t=' + j.thread_id); } catch (e) {} }
        else toast((j && j.error) || t('sendFail'));
      });
      return;
    }
    if (!isPhone()) {
      var first = B.office ? (S.threads[0] && S.threads[0].id) : B.general;
      if (first) { openThread(first, false); try { history.replaceState({ t: first }, '', location.href); } catch (e) {} }
    }
  });
})();
</script>
<?php
admin_footer();
