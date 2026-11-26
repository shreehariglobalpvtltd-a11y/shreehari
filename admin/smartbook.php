<?php
/**
 * admin/smartbook.php — SmartBook for staff (15 Oct 2026, SmartBook v5 §11).
 *
 * The SAME card and the SAME engine as the customer home page (D13: "everything
 * else is the same engine and the same UI in every mode"), opened on the
 * .network door after login, in the session's own mode:
 *
 *   Agent    code locked from the login (never typed), wallet in the header
 *            (cash to hand over + commission earned), commission after each
 *            sale, a bus that left within 24 h is bookable, Online = the
 *            customer pays by QR (the agent sends the link).
 *   Counter  the desk from the login, Cash = taken at the desk, Online = UPI /
 *            eSewa seen on the desk's phone; 80 mm receipt after each sale;
 *            keyboard: Enter = one more passenger, Ctrl+Enter = book + print;
 *            the regular customers (top 20 mobiles) one tap away.
 *   Admin    may open Agent or Counter mode (?mode=agent|counter); may override
 *            a soft-placed hard rule with a written reason (audit-logged).
 *
 * The card markup is read from app.template.html (one copy), so a change to
 * the customer card is a change here too.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$e    = static fn($v): string => Security::e((string) $v);
$mode = SmartBook::sessionMode(Security::clean((string) ($_GET['mode'] ?? ''), 10));
if ($mode === 'customer') {
    http_response_code(403);
    exit('Selling staff only.');
}

$ownAgent = AgentWallet::isAgentRow((int) $admin['id']) ? AgentWallet::agentCodeLabel((int) $admin['id']) : '';
$agents   = $ownAgent !== '' ? [] : AgentWallet::sellableAgents();
$agentReq = $ownAgent === '' && Settings::getBool('smartbook_agent_required', true) && $agents !== [];
$coCode   = Settings::getString('company_agent_code', 'SHG-0001');

/* The card, from the one template. */
$tpl  = (string) @file_get_contents(dirname(__DIR__) . '/app.template.html');
$card = '';
if (preg_match('~<section class="sb" id="smartBook"[^>]*>.*?</section>~s', $tpl, $m) === 1) {
    // un-hide the section itself only (its sheet / modal / ticket stay hidden until used)
    $card = SiteFacts::apply((string) preg_replace('~(<section class="sb" id="smartBook"[^>]*?)\s+hidden>~', '$1>', $m[0], 1));
    $card = (string) preg_replace('/<!--.*?-->/s', '', $card);
    /* 25 Nov 2026 (wx1): the ⇄ route swap is the customer card's - the desk keeps its own
       two-button direction row (data-staff scopes the wx1 CSS to the customer face) */
    $card = (string) preg_replace('~<button type="button" class="sb-dir-swap"[^>]*>.*?</button>\s*~s', '', $card, 1);
    $card = (string) preg_replace('~<section class="sb" id="smartBook"~', '$0 data-staff="1"', $card, 1);
}
/* The agent field lives in THIS file, not in the template: the customer page
   can never carry it (smartbook-ui-test keeps the template free of it).
   16 Oct 2026 (owner: "agent code advance ma hune"): it is the FIRST thing on
   the staff card - spliced in above the QuickBot line - so a counter picks the
   agent before anything else and an agent sees their locked code on top. */
$agentField = $ownAgent !== ''
    ? '<div class="sb-f sb-f-agent" id="sbAgentF"><span class="sb-l">Agent code · एजेन्ट कोड</span>'
      . '<div class="sb-agent-lock">🔒 <span id="sbAgentOwn">' . $e($ownAgent) . '</span> · ' . $e((string) ($admin['full_name'] ?: $admin['username'])) . ' <small>from your login — every sale is credited here</small></div></div>'
    : '<div class="sb-f sb-f-agent" id="sbAgentF"><label class="sb-l" for="sbAgent">Agent code · एजेन्ट छान्नुहोस्' . ($agentReq ? ' <small style="color:#cd4154;text-transform:none">(required / अनिवार्य)</small>' : '') . '</label>'
      . '<div class="sb-agent-row"><input id="sbAgent" list="sbAgentList" type="text" autocomplete="off" maxlength="60" placeholder="SHG-0007 · name · city — type to search" enterkeyhint="next">'
      . '<datalist id="sbAgentList"></datalist>'
      . '<button type="button" class="sb-agent-other" id="sbAgentOther" title="Credit the company\'s own code ' . $e($coCode) . '">🏢 Other / company</button></div>'
      . '<small class="sb-why" id="sbAgentWhy"></small><small class="sb-fe" id="sbAgentE" hidden></small></div>';
$card = str_contains($card, '<form class="sb-bot"')
    ? (string) preg_replace('~(<form class="sb-bot")~', $agentField . '$1', $card, 1)
    : (string) preg_replace('~(<div class="sb-f sb-f-pax">)~', $agentField . '$1', $card, 1);

$me     = (int) $admin['id'];
$label  = ucfirst($mode);
$wallet = null;
/* WHO the sale is credited to (15 Oct 2026, owner: "Agent Code entry in Agent
   Mode and especially Counter Mode, with agent selection/search, automatic
   commission attribution"). An AGENT login sells on its own code - shown,
   locked, never typed (D13). Any other desk (counter, admin, an admin in Agent
   mode) picks the agent from the one list every desk picker uses
   (AgentWallet::sellableAgents): type a code / name / city, or Other =
   the company's own code. The choice is remembered per clerk in
   localStorage shg_ctr_agent_<id> - the same key counter mode and QuickBot
   read - so one pick serves the whole shift. The server validates again. */
if ($mode === 'agent') {
    $code   = AgentWallet::agentCodeLabel($admin['role'] === 'agent' ? $me : $me);
    $bal    = AgentWallet::balances($me);
    // 15 Oct 2026: the agent's own cash limit (profile; falls back to
    // agent_cash_limit_default) — 'agent_cash_limit_inr' was never defined anywhere.
    $limit  = (float) AgentWallet::profile($me)['cash_limit'];
    $wallet = ['code' => $code, 'cash' => (float) $bal['cash'], 'commission' => (float) $bal['commission'], 'limit' => $limit];
    $label  = 'Agent ' . ($code !== '' ? $code : '#' . $me);
} elseif ($mode === 'counter') {
    $desk  = Auth::deskScopeCode() ?? (string) (Database::scalar('SELECT counter_code FROM bookings WHERE sold_by_admin_id = :a AND counter_code IS NOT NULL ORDER BY id DESC LIMIT 1', ['a' => $me], '') ?: '');
    $label = 'Counter' . ($desk !== '' ? ' ' . $desk : '');
}
/* 16 Nov 2026 (P8-3f / P4-4d): the desk's extras - extra luggage pieces and an infant on
   the lap (no berth). Each field shows only while its owner switch is on
   (luggage_fee_per_piece > 0 / infant_seat_on) AND its columns exist - the same test
   BookingService::deskExtras() charges by - AND api/smartbook.php hands the two
   counts to the sale (its $seller context). Until that API carries them the fields
   stay hidden, so this card never shows a charge the ticket would not take. */
$sbExtrasWired = str_contains((string) @file_get_contents(dirname(__DIR__) . '/api/smartbook.php'), "'luggagePieces'");
$sbLugFee  = $sbExtrasWired && CounterDesk::hasColumn('bookings', 'luggage_pieces') && CounterDesk::hasColumn('bookings', 'luggage_fee')
    ? max(0, Settings::getInt('luggage_fee_per_piece', 0)) : 0;
$sbInfOn   = $sbExtrasWired && Settings::getBool('infant_seat_on', false)
    && CounterDesk::hasColumn('bookings', 'infant_count') && CounterDesk::hasColumn('bookings', 'infant_fee');
$sbInfFare = $sbInfOn ? max(0, Settings::getInt('infant_seat_fare', 0)) : 0;
/* fx1 (26 Nov 2026): the desk this login sits on, for the card's first defaults -
   the dial code (+977 at a Nepal window), the language, and the conveniences
   below. Each is a switch (Settings row; no row = the default):
     desk_npr_first_on      1  an NPR desk reads रू big (the figure frozen on the ticket), ₹ small
     desk_fast_entry_on     1  Enter = next field, phone Enter never adds a passenger, autofocus
                               on a keyboard, +977 first at a Nepal desk, the passenger being
                               typed is kept for the open tab only (never in the shared store)
     desk_gender_two_btn_on 1  a passenger with no F / M shows two buttons (never pre-set)
     desk_print80_touch_on  0  a phone / tablet does not open the 80 mm print by itself
     staff_card_lang       ne  the card's language until the clerk picks one (en = as before)
   0 / off = the desk exactly as it was. */
$sbDesk     = (string) (CounterDesk::forAdmin($me)['code'] ?? '');
$sbDeskLang = $sbDesk !== '' ? CounterDesk::lang($sbDesk) : '';
$sbLangDef  = strtolower(trim(Settings::getString('staff_card_lang', 'ne')));
$sbLang     = in_array($sbDeskLang, ['en', 'hi', 'ne'], true) ? $sbDeskLang : (in_array($sbLangDef, ['en', 'hi', 'ne'], true) ? $sbLangDef : 'en');
$sbFast     = Settings::getBool('desk_fast_entry_on', true);
$isAdmin = in_array((string) $admin['role'], ['superadmin', 'manager'], true) || Auth::isSuperadmin();
$ver     = (string) (preg_match("/var ASSET_VER = '([^']+)'/", (string) @file_get_contents(dirname(__DIR__) . '/sw.js'), $vm) === 1 ? $vm[1] : '1');
$F       = SiteFacts::all();

admin_header('SmartBook', 'smartbook');
?>
<link rel="stylesheet" href="/assets/css/smartbook.css?v=<?= $e($ver) ?>">
<style>
.sbx-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 6px}
.sbx-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:linear-gradient(120deg,#2f6bff,#1a44c8 52%,#0a2a8f);color:#fff;font-weight:800;font-size:13px;box-shadow:0 6px 16px -6px rgba(26,68,200,.6)}
.sbx-w{display:inline-flex;gap:10px;padding:6px 12px;border-radius:12px;background:var(--card,#fff);border:1px solid var(--line,#e0e8f2);font-size:13px}
.sbx-w b.low{color:#cd4154}
.sbx-modes a{padding:6px 10px;border-radius:9px;border:1px solid var(--line,#e0e8f2);text-decoration:none;font-size:12.5px;margin-right:4px}
.sbx-modes a.on{background:#1a44c8;color:#fff;border-color:#1a44c8}
.sbx-disc{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px;font-weight:700}
.sbx-disc input{width:84px;min-height:40px;padding:0 10px;border-radius:9px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit}
.sbx-disc select{min-height:40px;padding:0 8px;border-radius:9px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit}
.sbx-disc small{font-weight:600;opacity:.7}
.sbx-disc.cut small{color:#B45309;opacity:1}
/* 15 Nov 2026: quick discount chips + the passenger's ticket note */
.sbx-chips{display:inline-flex;flex-wrap:wrap;gap:6px;flex-basis:100%}
.sbx-chips button{min-height:34px;min-width:44px;padding:0 10px;border-radius:999px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s,transform .1s}
.sbx-chips button:active{transform:scale(.94)}
.sbx-chips button.on{background:#F07C1F;border-color:#F07C1F;color:#fff}
.sbx-chips button.x{min-width:34px;opacity:.7}
.sbx-tnote{display:flex;flex-direction:column;gap:4px;flex:1 1 260px;font-size:13px;font-weight:700}
.sbx-tnote input{min-height:40px;padding:0 10px;border-radius:9px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit;font-weight:600}
.sbx-tnote small{font-weight:600;opacity:.7;font-size:11.5px}
/* 16 Nov 2026 (P8-3f / P4-4d): extra luggage / lap infant counts (only while switched on) */
.sbx-xtra{display:flex;flex-wrap:wrap;gap:8px 14px;font-size:13px;font-weight:700}
.sbx-xtra label{display:flex;align-items:center;flex-wrap:wrap;gap:6px;flex:1 1 220px}
.sbx-xtra label>span{flex-basis:100%}
.sbx-xtra input{width:72px;min-height:40px;padding:0 10px;border-radius:9px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit;font-size:16px}
.sbx-xtra small{font-weight:600;opacity:.7;font-size:11.5px}
.sbx-xtra .sbx-xnote{flex-basis:100%}
#sbRail .sbx-desk .sbx-xtra input{flex:0 0 84px;width:84px}
/* 15 Nov 2026: the desk box inside the summary rail, right above the Book buttons */
#sbRail .sbx-desk{display:flex;flex-direction:column;gap:10px;margin:12px 0;padding:12px 12px 10px;border-radius:14px;border:1.5px dashed #F07C1F;background:rgba(240,124,31,.07)}
#sbRail .sbx-desk .sbx-disc{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
#sbRail .sbx-desk .sbx-disc>label{flex-basis:100%;font-size:14px;font-weight:800}
#sbRail .sbx-desk .sbx-disc input{flex:1 1 90px;width:auto;font-size:16px}
#sbRail .sbx-desk .sbx-tnote{flex:none}
#sbRail .sbx-desk .sbx-tnote input{font-size:15px}
.sb-dl .sbx-disc-row dd{color:#0E6B35;font-weight:800}
.sbx-reg{display:flex;gap:6px;overflow-x:auto;padding:4px 0 8px;scrollbar-width:thin}
.sbx-reg button{flex:0 0 auto;min-height:38px;border:1px solid var(--line,#e0e8f2);border-radius:10px;background:var(--card,#fff);color:inherit;font:inherit;font-size:12.5px;padding:0 10px;cursor:pointer;white-space:nowrap}
.sbx-help{font-size:12px;color:var(--muted,#637491);margin:2px 0 0}
.sbx-ov{display:none;margin:0}.sbx-ov input{width:100%;min-height:40px}
html.sbx-admin .sbx-ov{display:block}
.sbx-auto{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid #f0b429;background:#fff8e6;color:#7a5200;font-weight:800;font-size:12.5px;cursor:pointer}
.sbx-more{margin-left:auto;position:relative}.sbx-more>summary{list-style:none;cursor:pointer;width:38px;height:38px;display:grid;place-items:center;border-radius:10px;border:1px solid var(--line,#e0e8f2);font-size:18px}
.sbx-more>summary::-webkit-details-marker{display:none}
.sbx-more-in{position:absolute;right:0;top:44px;z-index:30;width:min(420px,92vw);padding:12px;border-radius:14px;background:var(--card,#fff);border:1px solid var(--line,#e0e8f2);box-shadow:0 12px 30px rgba(7,26,64,.18);display:grid;gap:8px}
.sbx-line{display:flex;gap:4px;list-style:none;margin:4px 0 8px;padding:0;overflow-x:auto;scrollbar-width:none;counter-reset:sbx}
.sbx-line li{flex:0 0 auto;counter-increment:sbx;display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 12px 0 6px;border-radius:999px;border:1px solid var(--line,#e0e8f2);background:var(--card,#fff);font-size:12.5px;font-weight:700;cursor:pointer;color:var(--muted,#637491)}
.sbx-line li::before{content:counter(sbx);display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#e8eef8;color:#3b4a66;font-size:11px}
.sbx-line li.ok{color:#11603a;border-color:#9fdcb8;background:#eefaf2}.sbx-line li.ok::before{content:'✓';background:#1f9d55;color:#fff}
.sbx-line li{transition:background-color .2s,border-color .2s,color .2s,box-shadow .25s}
.sbx-line li.now{border-color:#f07a1e;color:#d4570c;box-shadow:0 0 0 3px rgba(240,122,30,.18)}
.sbx-line li.sbx-go::before{content:'→'}.sbx-line li.sbx-go.ok{background:linear-gradient(120deg,#ffa24d,#f07a1e 50%,#d4570c);color:#fff;border-color:transparent;box-shadow:0 6px 14px -6px rgba(212,87,12,.6)}
#smartBook{padding-left:0;padding-right:0}
/* 14 Nov 2026 (staff 360px audit): under 860px the Pay bar is fixed. The
   customer page parks it above its 64px tab bar and pads #view-home to clear
   it; the staff page has neither, so here the bar floated 64px above the
   bottom edge and hid the last 88px of the card for good. On this page it
   sits on the bottom edge, the page ends below it, and a field reached with
   "next" stops above it. Layout only - the card, its script and its rules
   in assets/css/smartbook.css are untouched. */
@media (max-width:859px){
  #smartBook{--bottomnav-h:0px}
  main.wrap{padding-bottom:calc(var(--sb-bar-h,66px) + 24px + env(safe-area-inset-bottom,0px))}
  html{scroll-padding-bottom:calc(var(--sb-bar-h,66px) + 14px)}
  .sbx-more>summary{width:42px;height:42px;padding:0}
  .sbx-reg button{min-height:42px}
}
/* 19 Nov 2026 (px1, owner: "mobile ma sano letter, surumai ticket katne thau aos, ekdam sajilo"):
   on a phone the staff card is SELL-FIRST - mobile + name on top, big, one under the other;
   then F / M, the agent, the route / date / pickup chips; the typed-order line, Quick / Full
   switch, regular customers and the desk tools follow. The page title, the bus strip, the
   card heading and the step line are not drawn (the Book bar already says what is missing).
   Order + size only: same fields, same ids, same script, nothing removed from the page.
   Setting staff_phone_min_on = 0 gives the old phone layout back. */
<?php if (Settings::getBool('staff_phone_min_on', true)): ?>
@media (max-width:600px){
  main.wrap{display:flex;flex-direction:column}
  main.wrap>h1,.sbx-line{display:none}
  main.wrap>*{order:5}
  main.wrap>#smartBook{order:1}
  main.wrap>.sbx-reg{order:2}
  main.wrap>.sbx-top{order:3;margin-top:10px}
  main.wrap #smartBook{--sb-h:56px}
  main.wrap #smartBook .sb-card{display:flex;flex-direction:column;padding:12px 12px 8px}
  main.wrap #smartBook .sb-card>*{order:6}
  main.wrap #smartBook .sb-road,main.wrap #smartBook .sb-head{display:none}
  main.wrap #smartBook .sb-grid{order:1;grid-template-columns:minmax(0,1fr);gap:10px;margin:0 0 10px}
  main.wrap #smartBook .sb-dir{order:0;margin-bottom:10px}
  main.wrap #smartBook .sb-dir-b{min-height:50px;font-size:15px}
  main.wrap #smartBook .sb-f-pax{order:2}
  main.wrap #smartBook .sb-f-agent{order:3}
  main.wrap #smartBook .sb-row2,main.wrap #smartBook .sb-seat,main.wrap #smartBook .sb-suggest{order:4}
  main.wrap #smartBook .sb-seg button{min-height:48px;font-size:15px}
  main.wrap #smartBook .sb-qline{order:4;font-size:15px;gap:8px}
  main.wrap #smartBook .sb-f-alt{order:5}
  main.wrap #smartBook .sb-terms{order:9}
  main.wrap #smartBook .sb-grid .sb-f{margin:0;grid-column:1}
  main.wrap #smartBook .sb-grid input,main.wrap #smartBook .sb-grid select{font-size:20px;font-weight:600;border-radius:16px;padding:0 14px}
  main.wrap #smartBook .sb-tel select{flex:0 0 92px;font-size:17px;padding:0 8px}
  main.wrap #smartBook .sb-f-alt input,main.wrap #smartBook .sb-f-alt select,main.wrap #smartBook .sb-f-agent input{font-size:17px;border-radius:14px}
  main.wrap #smartBook .sb-qline span{padding:9px 14px}
  main.wrap #smartBook .sb-qline button{min-height:44px;font-size:15px}
  main.wrap #smartBook .sb-step button{width:52px;height:52px;font-size:22px}
  main.wrap #smartBook .sb-g{min-height:52px;padding:0 18px;font-size:17px}
  main.wrap #smartBook .sb-qmode button,main.wrap #smartBook .sb-qhelp{font-size:15px;min-height:44px}
  .sbx-reg button{font-size:15px;min-height:46px}
  .sbx-badge,.sbx-auto{font-size:14px}
}
<?php endif; ?>
/* px2: one form only - the Quick / Full switch is not drawn (smartbook_form_mode = full | quick) */
html.sb-one-form #smartBook #sbQMode{display:none}
/* fx1 (26 Nov 2026): the desk under a FINGER - every control of the desk box and the
   F / M + age chips at least 44px, inputs 16px (no zoom-in on focus). Coarse pointers
   only: a mouse desk keeps its compact rows. Size only, nothing moved. */
@media (pointer:coarse){
  .sbx-chips button{min-height:44px;min-width:48px;font-size:14px}
  .sbx-chips button.x{min-width:44px}
  .sbx-disc input,.sbx-disc select,.sbx-tnote input,.sbx-xtra input,.sbx-ov input{min-height:44px;font-size:16px}
  #sbRail .sbx-desk .sbx-tnote input{font-size:16px}
  .sbx-cash select{min-height:44px!important;font-size:16px!important}
  .sbx-more>summary{width:44px;height:44px}
  .sbx-reg button,.sbx-auto{min-height:44px}
  .sbx-modes a{display:inline-flex;align-items:center;min-height:44px}
  #smartBook[data-staff] .sb-p{height:auto;min-height:46px}
  #smartBook[data-staff] .sb-p button{height:auto;min-height:46px}
  #smartBook[data-staff] .sb-dir-b{min-height:44px}
}
/* fx1: a one-way desk draws ONE run (the other button is hidden by the card script) */
#smartBook[data-staff] .sb-dir.sb-dir-one{grid-template-columns:minmax(0,1fr)}
#smartBook[data-staff] .sb-dir-b[hidden]{display:none}
@media (max-width:600px){main.wrap #smartBook .sb-card>.sbx-block{order:0;margin:0 0 10px}}
#smartBook[data-staff] .sbx-block{display:block;margin:-4px 0 10px;padding:8px 10px;border-radius:10px;border:1px solid #f0b429;background:#fff8e6;color:#7a3d00;font-size:14px;font-weight:700;line-height:1.4}
#smartBook[data-staff] .sbx-block[hidden]{display:none}
/* fx1: F | M as two buttons while nothing is chosen (never pre-set) */
#smartBook[data-staff] .sb-p .sb-g.sb-g2{min-width:52px;border-right:1px solid var(--sb-line,#e0e8f2)}
/* fx1: the card's language, in the gear menu */
.sbx-lang{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:13px;font-weight:700}
.sbx-lang button{min-width:48px;min-height:44px;padding:0 12px;border-radius:10px;border:1px solid var(--line,#e0e8f2);background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer}
.sbx-lang button.on{background:#1a44c8;border-color:#1a44c8;color:#fff}
</style>
<div class="sbx-top">
  <span class="sbx-badge">⚡ SmartBook · <?= $e($label) ?></span>
  <?php if ($wallet !== null): ?>
    <span class="sbx-w">Cash to hand over <b class="<?= $wallet['limit'] > 0 && $wallet['cash'] >= $wallet['limit'] ? 'low' : '' ?>"><?= $e(inr($wallet['cash'])) ?></b> · Commission <b><?= $e(inr($wallet['commission'])) ?></b></span>
  <?php endif; ?>
  <label class="sbx-auto" title="A complete one-line order books at once as Cash: ticket confirmed, WhatsApp sent - no extra tap"><input type="checkbox" id="sbxAuto"> ⚡ Auto-book</label>
  <?php /* 15 Nov 2026 (owner, screenshot of the Journey Summary: "yesma discount aako xaina — main ta
           yei ho"): the discount + ticket note box is ONE block, moved by the script under the card
           into the summary rail, right above Book · Cash / Online (#sbRail, before #sbBar). */ ?>
  <div class="sbx-desk" id="sbxDesk">
  <?php $discAgent = $mode === 'agent' && $ownAgent !== '';   // mg20: an agent's own discount, from their commission
  // 15 Nov 2026 (owner: "discount garne option harayo"): the office selling in Agent mode keeps the box
  $discOffice = $mode === 'agent' && $ownAgent === '' && (string) ($admin['role'] ?? '') !== 'agent';
  if (in_array($mode, ['admin', 'counter'], true) || $discAgent || $discOffice):
        $discCap = max(0.0, Settings::getFloat('counter_max_discount_pct', 15.0));
        $capTxt  = rtrim(rtrim(number_format($discCap, 1), '0'), '.'); ?>
  <span class="sbx-disc" title="<?= $discAgent ? 'Discount on this ticket — Cash sales only; at most ' . $e($capTxt) . '% of the fare and never more than your commission on it, which it is taken from' : 'Discount on this ticket — at most ' . $e($capTxt) . '% of the fare (Settings → Agents &amp; counter)' ?>">
    <label for="sbxDiscVal">🏷️ छुट · Discount</label>
    <input type="number" id="sbxDiscVal" min="0" step="1" inputmode="numeric" placeholder="0" aria-describedby="sbxDiscNote">
    <select id="sbxDiscType" aria-label="Discount type"><option value="flat">₹</option><option value="percent">%</option></select>
    <small id="sbxDiscNote"><?= $discAgent ? 'Cash · max ' . $e($capTxt) . '% · कमिसनबाट कट्छ' : 'max ' . $e($capTxt) . '%' ?></small>
    <?php /* 15 Nov 2026 (owner: "sano amount ni discount dina milos"): one tap for the usual amounts; any
             typed amount still works (₹1 up). The server caps every one of them. */ ?>
    <span class="sbx-chips" role="group" aria-label="Quick discount">
      <?php foreach ([['flat', 20], ['flat', 50], ['flat', 100], ['flat', 200], ['percent', 5], ['percent', 10]] as [$ct, $cv]):
            if ($ct === 'percent' && $cv > $discCap) { continue; } ?>
      <button type="button" data-dt="<?= $ct ?>" data-dv="<?= $cv ?>"><?= $ct === 'flat' ? '₹' . $cv : $cv . '%' ?></button>
      <?php endforeach; ?>
      <button type="button" data-dt="" data-dv="0" class="x" aria-label="Clear discount">✕</button>
    </span>
  </span>
  <?php elseif ($mode === 'agent'): ?>
  <!-- an agent account with no agent code: no commission to take a discount from -->
  <span class="sbx-disc"><small>🏷️ छुट दिन आफ्नो agent code चाहिन्छ — कार्यालयलाई भन्नुहोस् · A discount needs your agent code: ask the office</small></span>
  <?php endif; ?>
  <?php /* 15 Nov 2026 (owner: "customer lai discount / note haru lekhna milos ticket ma"): a short
           note for the PASSENGER, printed on the ticket (bookings.ticket_note). Desk sales only:
           an agent's Online link has no desk seller, so the server keeps no note there. */ ?>
  <span class="sbx-tnote">
    <label for="sbxTNote">🧾 टिकटमा नोट · Note on ticket</label>
    <input id="sbxTNote" maxlength="80" autocomplete="off" placeholder="जस्तै: दशैं छुट · family offer" aria-describedby="sbxTNoteHint">
    <small id="sbxTNoteHint">यात्रुको टिकटमा छापिन्छ · printed on the ticket</small>
  </span>
  <?php if ($sbLugFee > 0 || $sbInfOn): /* P8-3f / P4-4d: desk-only extras, on Cash / desk sales (a desk seller) */ ?>
  <span class="sbx-xtra">
    <?php if ($sbLugFee > 0): ?>
    <label><span>🧳 अतिरिक्त सामान · Extra luggage (pieces)</span>
      <input type="number" id="sbxLug" min="0" max="<?= BookingService::LUGGAGE_MAX ?>" step="1" value="0" inputmode="numeric">
      <small>× <?= $e(inr((float) $sbLugFee)) ?></small></label>
    <?php endif; ?>
    <?php if ($sbInfOn): ?>
    <label><span>👶 शिशु · Infant (lap, no berth)</span>
      <input type="number" id="sbxInf" min="0" max="<?= BookingService::INFANT_MAX ?>" step="1" value="0" inputmode="numeric">
      <small><?= $sbInfFare > 0 ? '× ' . $e(inr((float) $sbInfFare)) : 'निःशुल्क · free' ?></small></label>
    <?php endif; ?>
    <small class="sbx-xnote">Cash · desk sale · कमिसन लाग्दैन / no commission</small>
  </span>
  <?php endif; ?>
  </div>
  <details class="sbx-more"><summary>⚙</summary><div class="sbx-more-in">
    <?php if ($isAdmin): ?>
      <span class="sbx-modes"><?php foreach (['admin' => 'Admin', 'counter' => 'Counter mode', 'agent' => 'Agent mode'] as $k => $v): ?><a class="<?= $mode === $k ? 'on' : '' ?>" href="?mode=<?= $e($k) ?>"><?= $e($v) ?></a><?php endforeach; ?></span>
    <?php endif; ?>
    <?php /* fx1 (26 Nov 2026): the card's language - Nepali first unless the desk / the office says otherwise (staff_card_lang) */ ?>
    <span class="sbx-lang" id="sbxLang" role="group" aria-label="भाषा · Language">भाषा · Language
      <button type="button" data-lang="ne" lang="ne">नेपाली</button><button type="button" data-lang="hi" lang="hi">हिन्दी</button><button type="button" data-lang="en" lang="en">English</button></span>
    <label class="sbx-help"><input type="checkbox" id="sbxPrint"> 80 mm print after each Cash sale</label>
    <p class="sbx-help"><?= $sbFast ? 'Enter (मोबाइल) = अर्को बक्स · next box · ' : '' ?>Enter = one more passenger · Ctrl+Enter = auto-berth + Cash + print · same engine as the website (rules decide, nothing typed is trusted).<?php if ($ownAgent === ''): ?> The agent you pick is remembered for this desk; commission goes to that agent, the cash stays with you.<?php endif; ?></p>
    <?php if ($mode === 'admin'): ?>
    <div class="sbx-ov" id="sbxOv"><label class="sbx-help" for="sbxOvIn">Override a hard rule on hand-picked berths — written reason (saved in the audit log)</label>
      <input id="sbxOvIn" maxlength="200" placeholder="e.g. family travelling together, confirmed by phone"></div>
    <?php endif; ?>
  </div></details>
</div>
<!-- 15 Oct 2026 (owner: "customer ko jastai ordering line"): the order as one line of steps -
     each turns green as it is filled; tap one to jump to it. Type the whole order in the
     QuickBot line below ("Ram 98…, 2, Vadodara bholi") and Auto-book does the rest. -->
<ol class="sbx-line" id="sbxLine" aria-label="Order steps">
  <?php if ($ownAgent === '' && $agents !== []): ?><li data-k="agent" data-go="sbAgent">Agent</li><?php endif; ?>
  <li data-k="route" data-go="sbDir">Route</li><li data-k="date" data-go="sbDates">Date</li><li data-k="point" data-go="sbPoint">Pickup</li>
  <li data-k="phone" data-go="sbPhone">Mobile</li><li data-k="name" data-go="sbName">Name</li><li data-k="pax" data-go="sbPax">F / M</li>
  <li data-k="book" data-go="sbCash" class="sbx-go">🎫 Book</li>
</ol>
<div class="sbx-reg" id="sbxReg" aria-label="Regular customers"></div>
<?php echo $card; ?>
<script>
window.SHG_BOOT = window.SHG_BOOT || {};
window.SHG_BOOT.csrf = <?= json_encode(Security::csrfToken()) ?>;
window.SHG_BOOT.apiBase = '/api';
/* 15 Nov 2026: the discount + note box sits in the summary rail, right above Book · Cash / Online. */
(function () {
  var desk = document.getElementById('sbxDesk'), rail = document.getElementById('sbRail'), bar = document.getElementById('sbBar');
  if (desk && rail && bar && desk.querySelector('input')) rail.insertBefore(desk, bar);
})();
<?php /* px2 (20 Nov 2026, owner: "quick ra full 2 ta form - euta matra, full form default"):
   smartbook_form_mode = full (default) | quick | both. full / quick = ONE form, the switch is not drawn. */
$sbFormMode = strtolower(trim(Settings::getString('smartbook_form_mode', 'full')));
if (!in_array($sbFormMode, ['full', 'quick', 'both'], true)) { $sbFormMode = 'full'; } ?>
window.SHG_SB_FORM = <?= json_encode($sbFormMode) ?>;
<?php if ($sbFormMode !== 'both'): ?>document.documentElement.classList.add('sb-one-form');<?php endif; ?>
window.SB_STAFF = <?= json_encode([
    'mode' => $mode, 'label' => $label, 'id' => $me, 'role' => (string) $admin['role'],
    'ownCode' => $ownAgent, 'agents' => $agents, 'agentRequired' => $agentReq, 'company' => $coCode,
    'commDirect' => Settings::getFloat('agent_flat_direct', 200.0), 'commJoint' => Settings::getFloat('agent_flat_joint', 400.0), 'commFlat' => AgentWallet::flatMode(),
    // P8-3f / P4-4d: 0 = the field is not on the page
    'luggageFee' => $sbLugFee, 'infantFare' => $sbInfFare, 'lugMax' => BookingService::LUGGAGE_MAX, 'infMax' => BookingService::INFANT_MAX,
    // fx1 (26 Nov 2026): the desk's conveniences - each a switch, false = the card as it was
    'deskCc'   => $sbFast && $sbDesk !== '' && CounterDesk::dialCode($sbDesk) === '977' ? 'NP' : '',
    'fast'     => $sbFast,
    'nprFirst' => Settings::getBool('desk_npr_first_on', true),
    'twoBtn'   => Settings::getBool('desk_gender_two_btn_on', true),
    'printTouch' => Settings::getBool('desk_print80_touch_on', false),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
if (<?= $mode === 'admin' ? 'true' : 'false' ?>) document.documentElement.classList.add('sbx-admin');
/* The few app globals the card uses, for a panel page that does not load the app bundle. */
if (typeof window.shgApi === 'undefined') {
  window.shgApi = {
    post: function (path, body) {
      var ov = document.getElementById('sbxOvIn');
      if (ov && ov.value.trim() && body && body.action !== 'boot') body.overrideReason = ov.value.trim();
      return fetch('/api' + path, { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.SHG_BOOT.csrf }, body: JSON.stringify(body || {}) })
        .then(function (r) { return r.json().then(function (j) {
          if (!j || j.ok !== true) { var er = new Error((j && j.error) || 'Error'); er.fields = j && j.fields; er.status = r.status; throw er; }
          return j.data; }); });
    }
  };
}
if (typeof window.toast === 'undefined') {
  window.toast = function (m) {
    var t = document.getElementById('sbxToast');
    if (!t) { t = document.createElement('div'); t.id = 'sbxToast'; t.setAttribute('role', 'status');
      t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#122445;color:#fff;padding:10px 16px;border-radius:12px;z-index:200;font-size:14px;max-width:90vw';
      document.body.appendChild(t); }
    t.textContent = m; t.hidden = false; clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, 3500);
  };
}
<?php /* fx1 (26 Nov 2026): the card opened in English for every desk (nothing on the panel ever
   wrote shg:lang). Now: the clerk's own pick, else the desk's language, else staff_card_lang
   (default ne; en = as before). <html lang> follows, so the card's own words agree. */ ?>
var SBX_LANG_DEF = <?= json_encode($sbLang) ?>;
var LANG = (function () { try { return localStorage.getItem('shg:lang') || SBX_LANG_DEF; } catch (e) { return SBX_LANG_DEF; } })();
if (LANG !== 'en' && LANG !== 'hi' && LANG !== 'ne') LANG = SBX_LANG_DEF;
try { document.documentElement.lang = LANG; } catch (e) {}
(function () {
  var box = document.getElementById('sbxLang'); if (!box) return;
  function paint() { box.querySelectorAll('button[data-lang]').forEach(function (b) { var on = b.getAttribute('data-lang') === LANG; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); }); }
  box.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-lang]'); if (!b) return;
    LANG = b.getAttribute('data-lang');
    try { localStorage.setItem('shg:lang', LANG); } catch (x) {}
    document.documentElement.lang = LANG;   // the card re-words itself on this (its own observer)
    paint();
  });
  paint();
})();
window.factPolicyLine = function () { var p = <?= json_encode($F['policy']['line'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; return p[LANG] || p.en; };
(function () {
  var pr = document.getElementById('sbxPrint');
  /* fx1: on a PHONE nothing is printed by itself until the clerk ticks the box (a new tab
     after every sale, with no printer). Switch desk_print80_touch_on = 1 = on by default
     everywhere, as before. A saved choice always wins. */
  var prPhone = <?= Settings::getBool('desk_print80_touch_on', false) ? 'false' : 'true' ?> && !!(window.matchMedia && window.matchMedia('(pointer:coarse) and (max-width:600px)').matches);
  try { var prSaved = localStorage.getItem('sb:print80'); pr.checked = prSaved === null ? !prPhone : prSaved !== '0'; } catch (e) { pr.checked = !prPhone; }
  pr.addEventListener('change', function () { try { localStorage.setItem('sb:print80', pr.checked ? '1' : '0'); } catch (e) {} });
  /* After a sale the card is cleared for the next customer. */
  window.SB_AFTER = function () {
    setTimeout(function () { if (window.SmartBook && window.SmartBook.resetForNext) window.SmartBook.resetForNext(); }, 800);
  };
})();
/* 15 Oct 2026: Auto-book (one-line order → ticket, no tap) - remembered per desk, on by default. */
(function () {
  var au = document.getElementById('sbxAuto');
  try { au.checked = localStorage.getItem('sb:auto') !== '0'; } catch (e) { au.checked = true; }
  au.addEventListener('change', function () { try { localStorage.setItem('sb:auto', au.checked ? '1' : '0'); } catch (e) {} });
  window.SB_AUTOBOOK = function () { return au.checked; };
})();
/* The ordering line: green as each step is filled, blue = the next one to do. */
(function () {
  var line = document.getElementById('sbxLine');
  function paint() {
    var sb = window.SmartBook; if (!sb || !sb.state) return;
    var S = sb.state, er = sb.errors ? sb.errors() : {};
    var done = {
      route: !!S.dir, date: !!(S.q && S.q.date), point: !!S.point || S.dir === 'toIndia',
      phone: !er.phone, name: !er.name, pax: !er.pax, agent: !er.agent
    };
    done.book = sb.canBook ? sb.canBook() : false;
    var next = false;
    line.querySelectorAll('li').forEach(function (li) {
      var k = li.getAttribute('data-k'), ok = !!done[k];
      li.classList.toggle('ok', ok);
      li.classList.toggle('now', !ok && !next && (next = true));
    });
  }
  setInterval(paint, 400);
  line.addEventListener('click', function (e) {
    var li = e.target.closest('li[data-go]'); if (!li) return;
    /* fx1: "Route" pointed at an id the card never had (the run buttons are .sb-dir) - the step did nothing */
    var el = document.getElementById(li.getAttribute('data-go')) || (li.getAttribute('data-k') === 'route' ? document.querySelector('#smartBook .sb-dir') : null); if (!el) return;
    if (li.getAttribute('data-k') === 'book') { if (window.SmartBook && window.SmartBook.book) window.SmartBook.book('cash', false); return; }
    try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (x) {}
    var f = el.matches('input,select,button') ? el : el.querySelector('input,select,button');
    if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 250);
  });
})();
/* 22 Nov 2026 (Nepal counter "book this bus"): ?sbdate=YYYY-MM-DD opens the card on that date. */
(function () {
  var d = new URLSearchParams(location.search).get('sbdate') || '';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return;
  var tries = 0, t = setInterval(function () {
    var sb = window.SmartBook, inp = document.getElementById('sbDateIn');
    if (!(sb && sb.state && sb.state.dirs && sb.state.dirs.length && inp) && ++tries < 40) return;
    clearInterval(t); if (!inp) return;
    inp.value = d; inp.dispatchEvent(new Event('change', { bubbles: true }));
  }, 250);
})();
/* Opened from a customer / booking page: ?cphone=&cname= fill the card (never books by itself). */
(function () {
  var qs = new URLSearchParams(location.search), ph = qs.get('cphone') || '', nm = qs.get('cname') || '';
  if (!ph) return;
  var tries = 0, t = setInterval(function () {
    var sb = window.SmartBook;
    if (!(sb && sb.state && sb.state.dirs && sb.state.dirs.length) && ++tries < 40) return;
    clearInterval(t); if (!sb) return;
    if (sb.loadCustomer) sb.loadCustomer(ph, nm);
  }, 250);
})();
</script>
<script defer src="/assets/js/27-smartbook.js?v=<?= $e($ver) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  window.SHG_BOOT = window.SHG_BOOT || {};
  setTimeout(function () {
    if (!window.shgApi) return;
    window.shgApi.post('/smartbook.php', { action: 'regulars', mode: <?= json_encode($mode) ?> }).then(function (list) {
      var box = document.getElementById('sbxReg'); if (!box || !list || !list.length) return;
      box.innerHTML = list.map(function (c) {
        var n = String(c.name || '').replace(/[&<>"']/g, function (x) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[x]; });
        return '<button type="button" data-ph="' + String(c.phone).replace(/\D/g, '') + '" data-nm="' + n + '">⭐ ' + n + ' · ' + String(c.phone).slice(-4) + ' (' + (c.trips | 0) + ')</button>';
      }).join('');
      box.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-ph]'); if (!b || !window.SmartBook) return;
        window.SmartBook.loadCustomer(b.getAttribute('data-ph'), b.getAttribute('data-nm'));
      });
    }).catch(function () {});
  }, 600);
});
</script>
<?php /* 29 Oct 2026 (management upgrade 2.2): desk keyboard + a beep on a sale.
   Staff page only, so the shared card script (27-smartbook.js, also served
   to customers) is untouched. Ctrl+Enter = book + print and Enter = one more
   passenger stay as they were; these are the extra keys a counter asked for. */ ?>
<div class="sbx-keys" id="sbxKeys" aria-hidden="true">⌨ <?= $sbFast ? '<b>Enter</b> अर्को बक्स · next box · ' : '' ?><b>F2</b> new booking · <b>F3</b> find PNR · <b>F5</b> refresh seats · <b>Esc</b> close · <b>Ctrl+Enter</b> book &amp; print</div>
<style>
.sbx-keys{display:none;margin:10px auto 0;max-width:980px;font-size:12px;color:var(--mut);text-align:center}
.sbx-keys b{display:inline-block;min-width:22px;padding:1px 6px;border:1px solid var(--line);border-bottom-width:2px;border-radius:6px;background:var(--card);color:var(--ink);font-size:11px}
@media (pointer:fine){.sbx-keys{display:block}}
</style>
<script>
(function () {
  function sb() { return window.SmartBook || null; }
  function vis(id) { var el = document.getElementById(id); return !!(el && !el.hidden); }
  function click(id) { var el = document.getElementById(id); if (el) { el.click(); return true; } return false; }
  document.addEventListener('keydown', function (e) {
    if (e.altKey || e.metaKey) return;
    if (e.key === 'F2') {                       // a fresh card for the next customer
      e.preventDefault();
      if (sb() && sb().resetForNext) { sb().resetForNext(); }
      if (vis('sbTicket')) { document.getElementById('sbTicket').hidden = true; }
      var ph = document.getElementById('sbPhone'); if (ph) { ph.focus(); }
    } else if (e.key === 'F3') {                // the top-bar search finds a PNR / phone / seat
      e.preventDefault();
      var q = document.querySelector('.tb-search input[name="q"]');
      if (q) { q.focus(); q.select(); }
    } else if (e.key === 'F5' && !e.ctrlKey && !e.shiftKey) {   // Ctrl+F5 / Shift+F5 still reload the page
      e.preventDefault();
      if (sb() && sb().requote) { sb().requote(0); }
    } else if (e.key === 'Escape') {            // the seat map has no Esc of its own
      if (vis('sbSheet')) { click('sbSheetX'); }
      else if (vis('sbModal')) { document.getElementById('sbModal').hidden = true; document.body.classList.remove('shg-modal-open'); }
    }
  });

  /* A short beep when a ticket is issued: the ticket panel (wide screens) or
     the ticket sheet (phones) turns visible. WebAudio, no file to download;
     silent when the browser has not allowed sound yet. */
  var ctx = null;
  function beep() {
    try {
      ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.type = 'sine'; o.frequency.value = 880;
      g.gain.setValueAtTime(0.0001, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.2, ctx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
      o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.26);
    } catch (e) {}
  }
  function watch(id) {
    var el = document.getElementById(id); if (!el || !window.MutationObserver) return;
    var was = !el.hidden;
    new MutationObserver(function () {
      var now = !el.hidden;
      if (now && !was && el.querySelector('img, .sb-tk, [data-pnr]')) { beep(); }
      was = now;
    }).observe(el, { attributes: true, attributeFilter: ['hidden'] });
  }
  document.addEventListener('DOMContentLoaded', function () { watch('sbTicket'); watch('sbModal'); });
})();
</script>
<?php admin_footer();
