<?php
/**
 * admin/quick-ticket.php — ⚡ QUICK TICKET SERVICE (6 Sep 2026).
 *
 * The desk's fast lane: type a passenger's NAME and MOBILE, press one
 * button, and the server does the rest — picks the next catchable bus,
 * the desk's boarding point, the best free seat and the fare, confirms
 * the sale, renders the PNG ticket and sends it to the passenger's
 * WhatsApp (automatic once the Twilio WhatsApp sender is live; until then
 * a one-tap "Send on WhatsApp" button on the result card). Every sale
 * runs through BookingService::create() — see includes/quickticket.php.
 *
 * Options are chips, not fields: direction, date, pickup, seats, gender,
 * payment received. The desk's pickup is remembered in this browser so a
 * Mehsana counter never has to touch it again. A live "what will be sold"
 * card updates as chips change; a stopwatch keeps the 30-second promise
 * honest. ?name=&phone= pre-fill the form (the app's banner links here for
 * staff).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';
$admin = admin_boot('bookings.view');

/* 🤖 AI Ticket Bot (6 Sep 2026): what it has learned so far, for the
   panel header. Never fatal — a cache hiccup must not take the desk down. */
$bot = ['enabled' => true, 'sample' => 0, 'repeat' => 0, 'windowDays' => 0, 'topStops' => [], 'outcomes' => 0, 'hitRate' => null];
try {
    $bot = TicketBot::summary();
} catch (Throwable $e) {
    Logger::warning('TicketBot summary failed', ['e' => $e->getMessage()]);
}

$canSell  = Auth::isSellingStaff();
$csrf     = Security::e(Security::csrfToken());
$preName  = Security::clean((string) ($_GET['name'] ?? ''), 120);
$prePhone = Security::clean((string) ($_GET['phone'] ?? ''), 20);
/* The engine's own cap, not a second opinion. This read min(10, ...) — a
   hard-coded ceiling that is not a setting — so with counter_max_seats_per_
   booking at its default 20 the desk offered only chips 1-10 and refused to
   render the party sizes the engine would happily sell. */
$maxSeats = BookingService::maxSeatsFor(true);
$maxDisc  = Settings::getFloat('counter_max_discount_pct', 15.0);
/* Nepal counters (24 Sep 2026, owner: "Nepalgunj and other authorised
   counters in Nepal … INR/NPR currency handling where required"). Fares are
   held in INR everywhere — the database, the ticket, the accounts — and that
   does not change. What a clerk at Nepalgunj or the Rupaidiha desk needs is
   the NPR figure to say out loud while taking cash, so the peg travels to
   the page and the desk prints "≈ NPR x" UNDER the rupee total. It is a
   conversion aid and is labelled as one: nothing is stored in NPR.
   npr_per_inr lives in Admin → Settings; 0 switches the line off. */
$nprPeg   = Settings::getFloat('npr_per_inr', NPR_PER_INR);
/* Which desk this clerk is signed in at (24 Sep 2026) — the same label the
   tickets they issue will carry. Read straight off their own profile; a
   staff member with no counter set simply sees no badge. */
$deskRow   = Database::fetch(
    'SELECT counter_name, counter_code FROM admin_profiles WHERE admin_id = :id',
    ['id' => (int) ($admin['id'] ?? 0)]
);
$deskLabel = $deskRow === null ? '' : Settings::counterLabel(
    (string) ($deskRow['counter_code'] ?? ''),
    (string) ($deskRow['counter_name'] ?? '')
);
$waDriver = Settings::getString('whatsapp_driver', 'click_to_chat');
$waReady  = $waDriver === 'twilio'
    ? (Settings::getString('twilio_account_sid', '') !== ''
        && Settings::getString('twilio_auth_token', '') !== ''
        && Settings::getString('twilio_whatsapp_from', '') !== '')
    : ($waDriver === 'cloud_api'
        && Settings::getString('whatsapp_api_token', '') !== ''
        && Settings::getString('whatsapp_phone_id', '') !== '');
$who = trim((string) ($admin['full_name'] ?? ($admin['username'] ?? 'staff')));

// Today's quick tickets — a counter agent sees only their own.
$scope  = Auth::bookingScopeAdminId();
$params = ['d0' => todayISO()];
$where  = 'b.created_at >= :d0';
if ($scope !== null) {
    $where .= ' AND b.sold_by_admin_id = :me';
    $params['me'] = $scope;
}
$recent = Database::fetchAll(
    "SELECT b.id, b.pnr, b.contact_phone, b.total_amount, b.created_at, b.status,
            l.travel_date, l.boarding_stop, a.full_name AS seller,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS pax,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats
       FROM bookings b
  LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
  LEFT JOIN admins a ON a.id = b.sold_by_admin_id
      WHERE {$where}
        AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.admin_note LIKE 'Quick Ticket%')
   ORDER BY b.id DESC
      LIMIT 12",
    $params
);

admin_header('🤖 QuickBot Ticket', 'quick-ticket');
?>
<style>
.qt{max-width:1080px}
.qt-hero{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-radius:18px;padding:20px 22px;margin-bottom:16px;color:#fff;position:relative;overflow:hidden;
  background:linear-gradient(135deg,#12264E 0%,#1C3B72 55%,#2E5FA8 100%);box-shadow:0 10px 30px rgba(18,38,78,.28)}
.qt-hero::before{content:"";position:absolute;right:-70px;bottom:-90px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(240,124,31,.55),transparent 65%);pointer-events:none}
.qt-hero h2{margin:8px 0 4px;font-size:22px;line-height:1.2;font-weight:900}
.qt-hero p{margin:0;font-size:13px;opacity:.9;max-width:620px;line-height:1.45}
.qt-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;background:var(--orange);color:#fff;padding:5px 10px;border-radius:999px;box-shadow:0 4px 14px rgba(240,124,31,.45);animation:qtPulse 2.4s ease-in-out infinite}
@keyframes qtPulse{0%,100%{box-shadow:0 4px 14px rgba(240,124,31,.45)}50%{box-shadow:0 4px 22px rgba(240,124,31,.9)}}
/* The desk badge sits beside the QuickBot pill — quieter than it (this is
   context, not the headline) but bright enough to catch a clerk who signed
   in at the wrong window. */
.qt-desk{display:inline-flex;align-items:center;gap:5px;margin-left:8px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);color:#fff;padding:5px 10px;border-radius:999px}
.qt-stepper{display:flex;gap:8px;flex-wrap:wrap;list-style:none;margin:12px 0 0;padding:0}
.qt-stepper li{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);opacity:.75;transition:all .25s}
.qt-stepper li b{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:rgba(255,255,255,.25);font-size:11px}
.qt-stepper li.on{opacity:1;background:var(--orange);border-color:var(--orange)}
.qt-stepper li.done{opacity:1;background:#178A50;border-color:#178A50}
.qt-voice{position:relative;z-index:1;align-self:flex-start;min-height:44px;padding:0 14px;border-radius:999px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.12);color:#fff;font:700 13px/1 inherit;cursor:pointer}
.qt-voice.on{background:#1FA35A;border-color:#1FA35A}
.qt-clock{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:110px;height:110px;border-radius:50%;background:rgba(255,255,255,.12);border:3px solid rgba(255,255,255,.35);font-variant-numeric:tabular-nums}
.qt-clock span{font-size:32px;font-weight:900;line-height:1}
.qt-clock small{font-size:11px;opacity:.8;letter-spacing:.08em;text-transform:uppercase}
.qt-clock.run{border-color:var(--orange)}
.qt-clock.done{border-color:#4ade80;background:rgba(74,222,128,.18)}
.qt-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:16px;margin-bottom:16px}
@media(max-width:820px){.qt-grid{grid-template-columns:minmax(0,1fr)}}
.qt-form .qt-lbl{display:block;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);margin:6px 0 6px}
.qt-in{width:100%;box-sizing:border-box;min-height:54px;font-size:20px;font-weight:700;padding:10px 14px;border:2px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink)}
.qt-in:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 4px rgba(240,124,31,.18)}
.qt-phone{display:flex;gap:8px;align-items:stretch}
.qt-cc{display:flex;border:2px solid var(--line);border-radius:12px;overflow:hidden;flex:0 0 auto}
.qt-cc button{border:0;background:var(--card);color:var(--ink);font-weight:800;font-size:13px;padding:0 10px;min-width:70px;cursor:pointer}
.qt-cc button.on{background:var(--navy);color:#fff}
.qt-err{margin-top:8px;padding:10px 12px;border-radius:10px;background:#f7dcdc;color:#8a1f1f;font-weight:700;font-size:13px}
.qt-opts{margin-top:12px;border:1px dashed var(--line);border-radius:12px;padding:8px 12px}
.qt-opts summary{cursor:pointer;font-weight:800;font-size:13px;list-style:none;display:flex;align-items:center;gap:6px}
.qt-opts summary::-webkit-details-marker{display:none}
.qt-opts summary #qtOptSum{color:var(--mut);font-weight:600}
.qt-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}
.qt-k{flex:0 0 78px;font-size:12px;font-weight:800;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
.chips{display:flex;gap:6px;flex-wrap:wrap;flex:1 1 200px}
.chips button{border:1.5px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:7px 12px;font-size:13px;font-weight:700;cursor:pointer;min-height:36px}
.chips button.on{background:var(--navy);border-color:var(--navy);color:#fff}
.chips button.dim{opacity:.45}
.chips button:disabled{opacity:.4;cursor:not-allowed}
.qt-date{border:1.5px solid var(--line);border-radius:999px;padding:6px 10px;background:var(--card);color:var(--ink);font-size:13px;min-height:36px}
.qt-disc{display:flex;gap:6px;flex-wrap:wrap;flex:1 1 200px}
.qt-disc input,.qt-disc select{border:1.5px solid var(--line);border-radius:9px;padding:7px 10px;background:var(--card);color:var(--ink);font-size:14px;min-height:36px;min-width:0}
.qt-disc input[type=number]{width:90px}.qt-disc input[type=text]{flex:1 1 140px}
.qt-go{width:100%;margin-top:14px;min-height:60px;border:0;border-radius:14px;font-size:19px;font-weight:900;color:#fff;cursor:pointer;
  background:linear-gradient(135deg,#F07C1F,#D96A10);box-shadow:0 8px 24px rgba(240,124,31,.4);transition:transform .15s,box-shadow .15s}
.qt-go:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 28px rgba(240,124,31,.5)}
.qt-go:disabled{opacity:.55;cursor:not-allowed;box-shadow:none}
.qt-hint{margin-top:8px;font-size:12px;color:var(--mut);line-height:1.4}
.qt-plan-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;font-size:15px}
.qt-live{font-size:12px;color:var(--mut)}
.qt-route{display:flex;flex-direction:column;gap:2px;margin-bottom:12px}
.qt-route .qt-dir{font-size:11.5px;font-weight:800;color:var(--orange);text-transform:uppercase;letter-spacing:.05em}
.qt-route b{font-size:18px}
.qt-route small{color:var(--mut);font-size:12px}
.qt-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
.qt-facts>div{background:var(--hover);border-radius:12px;padding:10px 12px;min-width:0}
.qt-facts small{display:block;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--mut)}
.qt-facts b{display:block;font-size:16px;margin-top:2px;word-break:break-word}
.qt-facts em{display:block;font-style:normal;font-size:11.5px;color:var(--mut);margin-top:2px}
.qt-facts .big b{font-size:24px;color:var(--navy)}
/* The NPR conversion aid for a Nepal desk (24 Sep 2026). Deliberately
   smaller and quieter than the rupee figure beside it: the fare IS the
   rupee amount — this is what to say out loud while taking Nepali cash. */
.qt-npr{display:block;font-size:12.5px;font-weight:700;color:#0863b8;margin-top:1px;letter-spacing:.01em}
.qt-alt{margin-top:12px;padding:10px 12px;border-radius:12px;border:1px dashed var(--line);font-size:12.5px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.qt-alt button{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:999px;padding:5px 10px;font-weight:700;cursor:pointer;font-size:12px}
.qt-noplan{padding:14px;border-radius:12px;background:#fff3e0;color:#8a4a00;font-weight:700;font-size:13px;line-height:1.45}
:root[data-theme="dark"] .qt-noplan{background:#3b2a12;color:#ffd9a8}
.qt-why{margin-top:10px;font-size:12px;color:var(--mut)}
.qt-result{border:2px solid #178A50;margin-bottom:16px;animation:qtIn .35s ease}
@keyframes qtIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.qt-res-head{display:flex;align-items:center;gap:12px;margin-bottom:12px}
.qt-ok{font-size:34px;line-height:1}
.qt-res-head b{font-size:18px;display:block}
.qt-res-head small{color:var(--mut);font-size:12.5px}
.qt-time-badge{margin-left:auto;background:#178A50;color:#fff;font-weight:900;padding:6px 12px;border-radius:999px;font-size:14px;white-space:nowrap}
.qt-res-grid{display:grid;grid-template-columns:minmax(0,300px) minmax(0,1fr);gap:16px}
@media(max-width:700px){.qt-res-grid{grid-template-columns:minmax(0,1fr)}}
.qt-res-img img{width:100%;max-width:300px;height:auto;border-radius:12px;border:1px solid var(--line);box-shadow:0 8px 24px rgba(0,0,0,.18);display:block;background:#fff}
.qt-wa{margin-top:12px;padding:12px;border-radius:12px;font-size:13px;line-height:1.45;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.qt-wa.ok{background:#d7f4e3;color:#0a6b3b}
.qt-wa.warn{background:#fff3e0;color:#8a4a00}
:root[data-theme="dark"] .qt-wa.ok{background:#0f2a1c;color:#9be7bd}
:root[data-theme="dark"] .qt-wa.warn{background:#3b2a12;color:#ffd9a8}
.qt-wa .btn{background:#25D366;color:#fff;border-color:#25D366}
.qt-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.qt-actions .btn{flex:1 1 140px;text-align:center}
.qt-next{background:var(--orange)!important;color:#fff!important;border-color:var(--orange)!important}
.qt-recent{width:100%;border-collapse:collapse;font-size:13px}
.qt-recent th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);padding:8px 8px;border-bottom:1px solid var(--line)}
.qt-recent td{padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:top}
.qt-recent tr.new td{background:rgba(23,138,80,.08)}
.qt-recent a{font-weight:700}
.qt-empty{color:var(--mut);font-size:13px;padding:8px}
.flash.warn{background:#fff3e0;color:#8a4a00}
:root[data-theme="dark"] .flash.warn{background:#3b2a12;color:#ffd9a8}
/* 🤖 QuickBot */
.qt-line{border-color:#7c3aed!important;background:linear-gradient(180deg,rgba(124,58,237,.06),transparent 70%)!important;font-size:17px!important;font-weight:600!important}
.qt-line:focus{box-shadow:0 0 0 4px rgba(124,58,237,.18)!important}
.qt-why{margin:-2px 0 10px;padding:8px 10px;border-radius:10px;background:rgba(124,58,237,.08);font-size:12.5px;color:var(--mut);line-height:1.5}
.qt-bot{border:2px solid rgba(124,58,237,.25);background:linear-gradient(180deg,rgba(124,58,237,.07),transparent 65%);margin-bottom:16px}
.qt-bot-stat{font-size:12px;color:var(--mut);text-align:right;line-height:1.4}
.qt-bot-line{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap}
.qt-bot-line input{flex:1 1 240px;min-height:50px;font-size:17px;font-weight:600;padding:8px 14px;border:2px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);min-width:0}
.qt-bot-line input:focus{outline:none;border-color:#7c3aed;box-shadow:0 0 0 4px rgba(124,58,237,.15)}
.qt-bot-line .btn{min-height:50px;font-weight:800;background:#7c3aed;color:#fff;border-color:#7c3aed;white-space:nowrap;padding:0 18px}
.qt-bot-line .btn:disabled{opacity:.55}
.qt-bot-ex{margin-top:6px;font-size:12px;color:var(--mut);line-height:1.5}
.qt-bot-ex code{background:var(--hover);padding:1px 6px;border-radius:6px;font-size:11.5px}
.qt-bot-out{margin-top:12px}
.qt-bot-prof{display:flex;gap:10px 16px;align-items:center;flex-wrap:wrap;padding:10px 12px;border-radius:12px;background:var(--hover);margin-bottom:10px;font-size:13px;line-height:1.4}
.qt-bot-prof b{font-size:15px}
.qt-bot-prof .new{color:var(--mut)}
.qt-bot-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
.qt-bot-fields>div{background:var(--hover);border-radius:12px;padding:9px 11px;min-width:0;border:1.5px solid transparent}
.qt-bot-fields>div.check{border-color:#f59e0b}
.qt-bot-fields small{display:block;font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--mut)}
.qt-bot-fields b{display:block;font-size:15px;margin-top:2px;word-break:break-word}
.qt-bot-fields em{display:block;font-style:normal;font-size:11px;color:var(--mut);margin-top:3px;line-height:1.35}
.qt-src{display:inline-block;font-size:10px;font-weight:800;padding:1px 7px;border-radius:999px;margin-left:4px;vertical-align:middle;text-transform:uppercase;letter-spacing:.03em}
.qt-src.input,.qt-src.text{background:#dbeafe;color:#1e3a8a}
.qt-src.history{background:#ede9fe;color:#5b21b6}
.qt-src.desk,.qt-src.pattern{background:#fef3c7;color:#92400e}
.qt-src.auto,.qt-src.none{background:var(--line);color:var(--mut)}
.qt-conf{height:6px;border-radius:999px;background:var(--line);overflow:hidden;margin-top:6px}
.qt-conf i{display:block;height:100%;background:linear-gradient(90deg,#7c3aed,#22c55e)}
.qt-bot-why{margin:10px 0 0;padding-left:18px;font-size:12.5px;color:var(--mut);line-height:1.55}
/* SHG AI BRAIN Phase 2 (10 Sep 2026): the one clarifying question + "same as last time" under the smart line */
.qt-ask{margin:6px 0 10px;padding:10px 12px;border-radius:12px;background:#FFF7E6;border:1.5px solid #F6C86B}
.qt-ask-q{font-weight:800;font-size:14px;margin-bottom:8px}
.qt-ask-chips{display:flex;gap:6px;flex-wrap:wrap}
.qt-ask-chips button,.qt-same{min-height:40px;padding:6px 12px;border-radius:999px;border:1.5px solid var(--line,#d9dee7);background:#fff;color:inherit;font:inherit;font-weight:700;cursor:pointer}
.qt-ask-chips button:hover,.qt-same:hover{border-color:#7c3aed}
.qt-same{display:inline-flex;align-items:center;gap:6px;margin-top:8px;background:#EEF6FF;border-color:#93C5FD}
:root[data-theme="dark"] .qt-ask{background:rgba(246,200,107,.12);border-color:#8a6a1f}
:root[data-theme="dark"] .qt-ask-chips button,:root[data-theme="dark"] .qt-same{background:#0D1530;border-color:#1A2240}
.qt-bot-plan{margin-top:10px;padding:10px 12px;border-radius:12px;border:1.5px dashed rgba(124,58,237,.45);font-size:13px;line-height:1.5}
.qt-bot-plan b{font-size:15px}
.qt-bot-act{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.qt-bot-act .btn{flex:1 1 160px;text-align:center;min-height:46px;font-weight:800}
.qt-bot-act .go{background:#7c3aed;color:#fff;border-color:#7c3aed}
.qt-bot-hint{margin-top:6px;font-size:12.5px;color:#5b21b6;font-weight:700;line-height:1.45}
.qt-bot-hint .qt-src{margin-left:2px}
.qt-bot-err{margin-top:10px;padding:10px 12px;border-radius:10px;background:#f7dcdc;color:#8a1f1f;font-weight:700;font-size:13px}
:root[data-theme="dark"] .qt-bot-hint{color:#c4b5fd}
:root[data-theme="dark"] .qt-src.input,:root[data-theme="dark"] .qt-src.text{background:#1e3a8a;color:#dbeafe}
:root[data-theme="dark"] .qt-src.history{background:#4c1d95;color:#ede9fe}
:root[data-theme="dark"] .qt-src.desk,:root[data-theme="dark"] .qt-src.pattern{background:#78350f;color:#fef3c7}

/* ---- 🌙 Passive Brain: what the night shift left on the desk ---------- */
.qt-brain{margin-bottom:14px}
.qt-brain-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.qt-brain-head b{font-size:15px}
.qt-brain-head .qt-acc{margin-left:auto;font-size:12px;font-weight:700;color:#64748b}
.qt-brain-empty{padding:14px;border-radius:10px;background:#f1f5f9;color:#475569;font-size:13px;font-weight:600}
.qt-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,270px),1fr));gap:10px}
.qt-card{position:relative;display:flex;flex-direction:column;gap:6px;min-width:0;padding:11px 12px;border:1px solid #e2e8f0;border-left:4px solid #94a3b8;border-radius:11px;background:#fff;text-align:left;cursor:pointer;transition:box-shadow .15s,transform .15s}
.qt-card:hover{box-shadow:0 6px 18px rgba(15,23,42,.11);transform:translateY(-1px)}
.qt-card.hot{border-left-color:#dc2626}
.qt-card.warm{border-left-color:#f59e0b}
.qt-card.cool{border-left-color:#64748b}
.qt-card.draft{border-left-color:#16a34a;background:#f7fdf9}
.qt-card-top{display:flex;align-items:baseline;gap:8px;min-width:0}
.qt-card-name{font-weight:800;font-size:14px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.qt-card-pct{margin-left:auto;flex:none;font-size:11.5px;font-weight:800;padding:1px 7px;border-radius:999px;background:#eef2ff;color:#4338ca}
.qt-card.hot .qt-card-pct{background:#fee2e2;color:#b91c1c}
.qt-card.warm .qt-card-pct{background:#fef3c7;color:#92400e}
.qt-card-trip{font-size:12.5px;font-weight:700;color:#0f172a}
.qt-card-why{font-size:11.5px;line-height:1.45;color:#64748b}
.qt-card-quote{font-size:12px;line-height:1.45;color:#166534;font-style:italic;overflow-wrap:anywhere}
.qt-card-flags{font-size:11.5px;font-weight:700;color:#b45309;line-height:1.45}
.qt-card-x{position:absolute;top:6px;right:6px;width:22px;height:22px;line-height:20px;text-align:center;border:0;border-radius:6px;background:transparent;color:#94a3b8;font-size:14px;cursor:pointer}
.qt-card-x:hover{background:#f1f5f9;color:#dc2626}
.qt-card-stale{font-size:11px;font-weight:700;color:#b45309}
.qt-alerts{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.qt-alert{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border-radius:9px;background:#f8fafc;font-size:12.5px;line-height:1.45}
.qt-alert.high{background:#fef2f2;color:#7f1d1d}
.qt-alert.warn{background:#fffbeb;color:#78350f}
.qt-alert b{font-weight:800}
.qt-alert-x{margin-left:auto;flex:none;border:0;background:transparent;color:inherit;opacity:.5;cursor:pointer;font-size:13px}
.qt-alert-x:hover{opacity:1}
.qt-brain-sub{margin:12px 0 6px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#64748b}
:root[data-theme="dark"] .qt-card{background:#0f172a;border-color:#1e293b}
:root[data-theme="dark"] .qt-card.draft{background:#052e16}
:root[data-theme="dark"] .qt-card-trip{color:#e2e8f0}
:root[data-theme="dark"] .qt-card-quote{color:#86efac}
:root[data-theme="dark"] .qt-brain-empty{background:#1e293b;color:#cbd5e1}
:root[data-theme="dark"] .qt-alert{background:#1e293b;color:#cbd5e1}
:root[data-theme="dark"] .qt-alert.high{background:#450a0a;color:#fecaca}
:root[data-theme="dark"] .qt-alert.warn{background:#451a03;color:#fde68a}
</style>

<div class="qt">
  <input type="hidden" name="shg_csrf" value="<?= $csrf ?>">

  <section class="qt-hero">
    <div class="qt-hero-l">
      <span class="qt-badge">🤖 QuickBot Ticket — 10-Second Booking</span>
      <?php if ($deskLabel !== ''): ?>
        <!-- Which window this is (24 Sep 2026). The same string that prints
             on every ticket sold here, shown before the first keystroke so a
             clerk signed in at the wrong desk sees it immediately rather
             than after a passenger reads it off their ticket. -->
        <span class="qt-desk">📍 <?= Security::e($deskLabel) ?></span>
      <?php endif; ?>
      <h2>Name + Mobile → Auto Suggest → One Tap → Ticket</h2>
      <p>एउटै लाइनमा लेख्नुहोस् — "Ram Bahadur 9876543210 2 seats Mehsana kal" — वा नाम + मोबाइल मात्र। QuickBot ले यात्रीको पुराना टिकट र desk को pattern बाट route, date, boarding, seats, best seat र fare आफैँ भर्छ; तपाईं एक पटक Confirm थिच्नुहोस् — ticket बन्छ, WhatsApp जान्छ।</p>
      <ol class="qt-stepper">
        <li class="on" id="qtSt1"><b>1</b> Line / name + mobile</li>
        <li id="qtSt2"><b>2</b> Auto: bus · seat · fare</li>
        <li id="qtSt3"><b>3</b> Confirm → ticket + WhatsApp</li>
      </ol>
    </div>
    <div class="qt-clock" id="qtClock" title="Seconds since the first keystroke"><span id="qtTime">0.0</span><small>sec</small></div>
    <button type="button" class="qt-voice" id="qtVoice" aria-pressed="false" title="Read the result aloud · नतिजा बोलेर सुनाउने">🔇 Voice off</button>
  </section>

  <?php if (!$canSell): ?>
    <div class="flash bad">Your role can open this desk but cannot sell. Ask the office for a selling login (counter / agent / manager).</div>
  <?php endif; ?>
  <?php if (!$waReady): ?>
    <div class="flash warn" id="qtWaNote">💬 Automatic WhatsApp is <b>not live yet</b> — the Twilio WhatsApp sender (KYC) is still pending. Every ticket still gets a one-tap <b>Send on WhatsApp</b> button below; once the sender is approved in Settings, tickets go out by themselves.</div>
  <?php endif; ?>

  <!-- 🌙 Passive Brain (7 Sep 2026): what the 02:00 job left here.
       Ranked cards for who is likely to ring today, the requests that came
       in on their own overnight, and the alerts that need a decision.
       Hidden until the API answers, so a desk with no brain data (or with
       the migration not yet run) sees exactly the page it saw before. -->
  <section class="card qt-brain" id="qtBrain" hidden>
    <div class="qt-brain-head">
      <b>🌙 QuickBot ले रातभर सोचेको — Ready Queue</b>
      <span class="muted" id="qtBrainCount"></span>
      <span class="qt-acc" id="qtBrainAcc"></span>
    </div>
    <div id="qtBrainBody"></div>
  </section>

  <div class="qt-grid">
    <form class="card qt-form" id="qtForm" autocomplete="off" novalidate>
      <!-- 🤖 QuickBot (7 Sep 2026): ONE smart line. Name + mobile is enough;
           seats, town, date, gender, payment, agent code may follow in any
           order and language. It is read on every pause (api action "bot")
           and fills the fields and chips below; Enter = confirm. -->
      <label class="qt-lbl" for="qtLine">🤖 QuickBot line · एउटै लाइनमा: नाम + मोबाइल (+ seats · town · date · cash)</label>
      <input id="qtLine" class="qt-in qt-line" maxlength="300" placeholder="Ram Bahadur 9876543210 · 2 seats · Mehsana · kal · cash" <?= ($preName === '' && $prePhone === '') ? 'autofocus' : '' ?> autocomplete="off" enterkeyhint="go">
      <div class="qt-bot-hint" id="qtBotHint" hidden></div>
      <div class="qt-ask" id="qtAsk" hidden></div>

      <label class="qt-lbl" for="qtName">👤 Passenger name · यात्रीको नाम</label>
      <input id="qtName" class="qt-in" maxlength="120" placeholder="Ram Bahadur Thapa" value="<?= Security::e($preName) ?>" autocomplete="off">

      <label class="qt-lbl" for="qtPhone">📱 Mobile (WhatsApp) · मोबाइल <span class="qt-opt-hint">— walk-in भए खाली छोड्नुहोस् · leave blank for a walk-in</span></label>
      <div class="qt-phone">
        <div class="qt-cc" id="qtCc">
          <button type="button" class="on" data-cc="IN" title="India">🇮🇳 +91</button>
          <button type="button" data-cc="NP" title="Nepal">🇳🇵 +977</button>
        </div>
        <input id="qtPhone" class="qt-in" type="tel" inputmode="tel" maxlength="15" placeholder="98XXXXXXXX — or blank" value="<?= Security::e($prePhone) ?>" autocomplete="off">
      </div>
      <div class="qt-err" id="qtErr" hidden></div>

      <details class="qt-opts" id="qtOpts">
        <summary>⚙️ Options · <span id="qtOptSum">auto</span></summary>
        <div class="qt-row"><span class="qt-k">Direction</span>
          <div class="chips" id="qtDir">
            <button type="button" class="on" data-v="">Auto</button>
            <button type="button" data-v="toNepal">🇮🇳→🇳🇵 Going</button>
            <button type="button" data-v="toIndia">🇳🇵→🇮🇳 Return</button>
          </div></div>
        <div class="qt-row"><span class="qt-k">Date</span>
          <div class="chips" id="qtDate">
            <button type="button" class="on" data-v="">Auto · next bus</button>
            <button type="button" data-v="today">Today</button>
            <button type="button" data-v="tomorrow">Tomorrow</button>
            <input type="date" id="qtDateIn" class="qt-date" title="Any other date">
          </div></div>
        <div class="qt-row"><span class="qt-k">Boarding</span>
          <div class="chips" id="qtBoard"><button type="button" class="on" data-v="">Auto</button></div></div>
        <div class="qt-row"><span class="qt-k">Seats</span>
          <div class="chips" id="qtSeats">
            <?php for ($i = 1; $i <= $maxSeats; $i++): ?><button type="button" class="<?= $i === 1 ? 'on' : '' ?>" data-v="<?= $i ?>"><?= $i ?></button><?php endfor; ?>
          </div></div>
        <div class="qt-row"><span class="qt-k">Gender</span>
          <div class="chips" id="qtGender">
            <button type="button" class="on" data-v="">—</button>
            <button type="button" data-v="Male">♂ Male</button>
            <button type="button" data-v="Female">♀ Female</button>
          </div></div>
        <div class="qt-row"><span class="qt-k">Received</span>
          <div class="chips" id="qtPay">
            <button type="button" class="on" data-v="cash">💵 Cash</button>
            <button type="button" data-v="upi">📱 UPI</button>
            <button type="button" data-v="esewa">🇳🇵 eSewa</button>
            <button type="button" data-v="bank">🏦 Bank</button>
          </div></div>
        <div class="qt-row"><span class="qt-k">Discount</span>
          <div class="qt-disc">
            <input type="number" id="qtDisc" min="0" step="1" placeholder="0" inputmode="numeric">
            <select id="qtDiscType"><option value="flat">₹ off</option><option value="percent">% off</option></select>
            <input type="text" id="qtNote" maxlength="120" placeholder="Note (optional) · receipt no.">
          </div></div>
        <div class="qt-hint">Max discount <?= Security::e((string) $maxDisc) ?>% (server re-checks). Boarding choice is remembered on this desk.</div>
      </details>

      <button type="submit" class="qt-go" id="qtGo" <?= $canSell ? '' : 'disabled' ?>>⚡ Confirm &amp; Issue Ticket →</button>
      <div class="qt-hint">Enter in the line = confirm · The sale confirms instantly (cash / UPI already received at the desk), the ticket PNG + PDF are ready and the WhatsApp goes, credited to <b><?= Security::e($who) ?></b>.</div>
    </form>

    <aside class="card qt-plan" id="qtPlan" aria-live="polite">
      <div class="qt-plan-head"><b>🤖 QuickBot suggestion · के बेचिन्छ</b><span class="qt-live" id="qtPlanState">…</span></div>
      <div class="qt-why" id="qtWhy" hidden></div>
      <div id="qtPlanBody">Loading the next bus…</div>
    </aside>
  </div>

  <section class="card qt-result" id="qtResult" hidden></section>

  <section class="card">
    <div class="qt-plan-head"><b>🧾 Today's quick tickets</b><span class="muted" id="qtRecentCount"><?= count($recent) ?></span></div>
    <div style="overflow-x:auto">
    <table class="qt-recent" id="qtRecent">
      <thead><tr><th>Time</th><th>Passenger</th><th>Mobile</th><th>Bus · date</th><th>Seat</th><th>Fare</th><th>Ticket</th></tr></thead>
      <tbody id="qtRecentBody">
      <?php if ($recent === []): ?>
        <tr class="qt-empty-row"><td colspan="7" class="qt-empty">No quick tickets yet today — the first one takes about 30 seconds.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $r):
          $pnr = (string) $r['pnr'];
          $bd  = Boarding::stopDisplay((string) ($r['boarding_stop'] ?? ''));
      ?>
        <tr>
          <td><?= Security::e(date('H:i', strtotime((string) $r['created_at']))) ?></td>
          <td><b><?= Security::e((string) ($r['pax'] ?? '')) ?></b><?= $scope === null && !empty($r['seller']) ? '<br><small class="muted">' . Security::e((string) $r['seller']) . '</small>' : '' ?></td>
          <td><?= Notify::usablePhone($r['contact_phone'] ?? '') !== ''
                    ? Security::e((string) $r['contact_phone'])
                    : '<span class="muted">walk-in</span>' ?></td>
          <td><?= Security::e(formatDate((string) $r['travel_date'])) ?><br><small class="muted"><?= Security::e($bd['code'] . ' · ' . $bd['name'] . ($bd['time'] ? ' · ' . $bd['time'] : '')) ?></small></td>
          <td><b><?= Security::e(implode(', ', array_map(
                    static fn ($s) => Seats::displayLabel(trim((string) $s), 'sleeper', 'sharing'),
                    array_filter(explode(', ', (string) ($r['seats'] ?? '')), static fn ($s) => trim((string) $s) !== '')
                ))) ?></b></td>
          <td><?= Security::e(inr((float) $r['total_amount'])) ?></td>
          <td><a href="/admin/booking-view.php?pnr=<?= rawurlencode($pnr) ?>"><?= Security::e($pnr) ?></a> · <a href="<?= Security::e(Ticket::imageUrl($pnr) . '&view=1') ?>" target="_blank" rel="noopener">🖼</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;
  var CAN = <?= $canSell ? 'true' : 'false' ?>;
  var MAX_DISC = <?= json_encode($maxDisc) ?>;
  var NPR_PEG  = <?= json_encode($nprPeg) ?>;   // 0 = do not show the NPR line
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) {
    var root = typeof r === 'string' ? document.querySelector(r) : (r || document);
    return root ? Array.prototype.slice.call(root.querySelectorAll(s)) : [];
  };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };

  /* Seat labels (24 Sep 2026). This page used to keep its own copy of the
     OLD deck-prefixed grid, so after the two-floor grid shipped the plan
     card, the result card, Recent, the "same as last time" chip and the
     spoken read-back said "UA4" while the seat map and the ticket shown
     in the same card said "A10". These are the shared helpers from
     assets/js/06-results.js (seatModeRuleJS ... seatLabel), kept in step
     with them: tests/seat-label-parity.js lifts THIS copy as well and
     holds it to tests/seat-labels.json (= Seats::displayLabel). Display
     only - the stored id (L1..U36) still flows through plan, sale and API. */
  if (!(window.SHG_BOOT && window.SHG_BOOT.settings)) {
    window.SHG_BOOT = { settings: { seat_mode_map: <?= json_encode(Settings::getArray('seat_mode_map', []) ?: null) ?> } };
  }
  function seatModeRuleJS(coachType, mode) {
    try {
      const map = window.SHG_BOOT && SHG_BOOT.settings && SHG_BOOT.settings.seat_mode_map;
      const spec = map && map[coachType || 'sleeper'];
      const rule = spec && spec.modes && spec.modes[mode];
      if (rule) return rule;
    } catch (e) { /* boot payload absent or malformed - fall through */ }
    return null;
  }
  function bedsPerLabelJS(coachType, mode) {
    const rule = seatModeRuleJS(coachType, mode);
    const n = rule && parseInt(rule.bedsPerLabel, 10);
    if (n >= 1) return n;
    return mode === 'private' ? 2 : 1;
  }
  function seatRowLetterJS(idx) {
    idx = Math.max(0, idx | 0);
    let out = '';
    do { out = String.fromCharCode(65 + (idx % 26)) + out; idx = Math.floor(idx / 26) - 1; } while (idx >= 0);
    return out;
  }
  function seatModeSpecJS(coachType) {
    try {
      const map = window.SHG_BOOT && SHG_BOOT.settings && SHG_BOOT.settings.seat_mode_map;
      return (map && map[coachType || 'sleeper']) || null;
    } catch (e) { return null; }
  }
  function seatBedsOfLabelJS(label, coachType, mode) {
    const rule = seatModeRuleJS(coachType, mode);
    if (rule && rule.explicit && rule.explicit[label]) {
      return rule.explicit[label].map(function (b) { return String(b).toUpperCase(); });
    }
    const per = bedsPerLabelJS(coachType, mode);
    const m = label.match(/^([A-Z])(\d+)$/);
    if (per <= 1 || !m) return [label];
    const j = parseInt(m[2], 10), out = [];
    for (let k = per * (j - 1) + 1; k <= per * j; k++) out.push(m[1] + k);
    return out;
  }
  function seatBedLabelJS(bed, coachType) {
    const m = bed.match(/^([A-Z])(\d+)$/);
    const n = m ? parseInt(m[2], 10) : 0;
    if (!(n >= 1)) return bed;
    const spec = seatModeSpecJS(coachType);
    const rule = seatModeRuleJS(coachType, (spec && spec.canonical) || 'sharing');
    const across = (rule && Array.isArray(rule.across) && rule.across.length === 2) ? rule.across : [4, 2];
    const perRow = Math.max(1, (parseInt(across[0], 10) || 0) + (parseInt(across[1], 10) || 0));
    const floor = Math.max(0, ((spec && Array.isArray(spec.decks)) ? spec.decks : ['L', 'U']).indexOf(m[1]));
    return seatRowLetterJS(Math.floor((n - 1) / perRow)) + (((n - 1) % perRow) + 1 + floor * perRow);
  }
  function seatJoinBedLabelsJS(lbls) {
    if (lbls.length < 2) return lbls[0] || '';
    let row = null, prev = null;
    for (let i = 0; i < lbls.length; i++) {
      const m = String(lbls[i]).match(/^([A-Z]+)(\d+)$/);
      if (!m || (row !== null && m[1] !== row) || (prev !== null && parseInt(m[2], 10) !== prev + 1)) return lbls.join('+');
      row = m[1]; prev = parseInt(m[2], 10);
    }
    return lbls[0] + '-' + prev;
  }
  function seatLabel(id, coachType, mode) {
    id = String(id == null ? '' : id).toUpperCase();
    const m = id.match(/^([LU])(\d+)$/);
    if (m) {
      if (!(parseInt(m[2], 10) >= 1)) return id;
      const coach = coachType || 'sleeper';
      return seatJoinBedLabelsJS(seatBedsOfLabelJS(id, coach, mode || 'sharing').map(function (b) { return seatBedLabelJS(b, coach); }));
    }
    if (/^\d+$/.test(id)) return 'A' + id;
    return id;
  }
  function seatLabelJoin(seats, coach, mode){ return (seats||[]).map(function(s){return seatLabel(s,coach,mode);}).join(', '); }

  var st = { direction: '', date: '', boarding: '', seats: 1, gender: '', pay: 'cash', cc: 'IN', prefer: [], preferPhone: '' };
  var lastBot = null;   // the last QuickBot answer (for the "same as last time" tap)
  try {
    st.boarding = localStorage.getItem('shg_qt_boarding') || '';
    var p0 = localStorage.getItem('shg_qt_pay'); if (p0) st.pay = p0;
  } catch (e) {}
  var plan = null, planReq = 0, planTimer = null, busy = false;
  var t0 = 0, clockTimer = null;

  /* ---- transport ---------------------------------------------------- */
  function api(body) {
    return fetch('/api/quick-ticket.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server error (HTTP ' + r.status + ')' }; });
    });
  }

  /* ---- option chips ------------------------------------------------- */
  function chips(id, key, after) {
    var box = $(id); if (!box) return;
    box.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-v]'); if (!b || b.disabled) return;
      $$('button[data-v]', box).forEach(function (x) { x.classList.toggle('on', x === b); });
      var v = b.getAttribute('data-v');
      st[key] = key === 'seats' ? (parseInt(v, 10) || 1) : v;
      if (after) after(v);
      summary();
      schedulePlan(0);
    });
  }
  chips('#qtDir', 'direction');
  chips('#qtDate', 'date', function () { $('#qtDateIn').value = ''; });
  chips('#qtSeats', 'seats');
  chips('#qtGender', 'gender');
  chips('#qtPay', 'pay', function (v) { try { localStorage.setItem('shg_qt_pay', v); } catch (e) {} });
  $$('button', '#qtPay').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === st.pay); });
  $('#qtDateIn').addEventListener('change', function () {
    if (!this.value) return;
    st.date = 'custom';
    $$('button[data-v]', $('#qtDate')).forEach(function (x) { x.classList.remove('on'); });
    summary(); schedulePlan(0);
  });
  $('#qtCc').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-cc]'); if (!b) return;
    $$('button', this).forEach(function (x) { x.classList.toggle('on', x === b); });
    st.cc = b.getAttribute('data-cc');
  });
  // Boarding chips are built from the plan (the route's own stops).
  $('#qtBoard').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-v]'); if (!b) return;
    $$('button[data-v]', this).forEach(function (x) { x.classList.toggle('on', x === b); });
    st.boarding = b.getAttribute('data-v');
    try { if (st.boarding) localStorage.setItem('shg_qt_boarding', st.boarding); else localStorage.removeItem('shg_qt_boarding'); } catch (err) {}
    summary(); schedulePlan(0);
  });
  $('#qtDisc').addEventListener('input', function () {
    var v = parseFloat(this.value) || 0;
    if ($('#qtDiscType').value === 'percent' && v > MAX_DISC) this.value = String(MAX_DISC);
  });

  function dateValue() {
    if (st.date === 'today') return isoOffset(0);
    if (st.date === 'tomorrow') return isoOffset(1);
    if (st.date === 'custom') return $('#qtDateIn').value || '';
    return '';
  }
  function isoOffset(d) {
    var x = new Date(); x.setDate(x.getDate() + d);
    return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
  }
  function summary() {
    var parts = [];
    if (st.direction) parts.push(st.direction === 'toNepal' ? 'Going' : 'Return');
    if (st.date) parts.push(st.date === 'custom' ? ($('#qtDateIn').value || 'date') : st.date);
    if (st.boarding) parts.push('📍 ' + st.boarding.split(/\s[—–-]\s|,|·/)[0].trim());
    if (st.seats > 1) parts.push(st.seats + ' seats');
    if (st.gender) parts.push(st.gender);
    if (st.pay !== 'cash') parts.push(st.pay);
    $('#qtOptSum').textContent = parts.length ? parts.join(' · ') : 'auto';
  }

  /* ---- the live plan ------------------------------------------------ */
  function schedulePlan(ms) { clearTimeout(planTimer); planTimer = setTimeout(refreshPlan, ms == null ? 250 : ms); }
  function refreshPlan() {
    var id = ++planReq;
    $('#qtPlanState').textContent = '⏳ checking…';
    /* QuickBot (7 Sep 2026): ONE call does everything — the smart line is
       parsed, this number's verified history and the desk's own patterns
       fill whatever the clerk has not chosen, and the live plan comes back
       with it. Chips the clerk set are sent as explicit input and always
       win; seats 0 / direction '' / boarding '' mean "not chosen". */
    var rawPhone = $('#qtPhone').value.trim();
    // "Same as last time" berths belong to ONE passenger: a different number forgets them (reviewed 10 Sep 2026).
    if (st.prefer.length && (rawPhone ? fullPhone(rawPhone) : '') !== st.preferPhone) { st.prefer = []; st.preferPhone = ''; }
    api({
      action: 'bot', text: $('#qtLine').value.trim(), name: $('#qtName').value.trim(), phone: rawPhone ? fullPhone(rawPhone) : '', country: st.cc,
      direction: st.direction, date: dateValue(), boarding: st.boarding, seats: st.seats > 1 ? st.seats : 0, gender: st.gender, pay: st.pay,
      prefer: st.prefer, lang: 'en'
    })
      .then(function (j) {
        if (id !== planReq) return;
        if (!j.ok || !j.data) {
          plan = null;
          $('#qtPlanState').textContent = '⚠️';
          $('#qtPlanBody').innerHTML = '<div class="qt-noplan">' + esc(j.error || 'No bus can be sold right now.') + '</div>';
          return;
        }
        var d = j.data;
        lastBot = d;
        applySuggestion(d);
        renderAsk(d);
        if (!d.plan) {
          plan = null;
          $('#qtPlanState').textContent = '⚠️';
          $('#qtPlanBody').innerHTML = '<div class="qt-noplan">' + esc(d.planError || 'No bus can be sold right now.') + '</div>';
          return;
        }
        plan = d.plan;
        $('#qtPlanState').textContent = (d.enabled === false ? 'learning off · ' : '') + 'live · ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        renderPlan(); renderStops();
        if (submitAfterPlan) { submitAfterPlan = false; fireSubmit(); }
      })
      .catch(function () {
        if (id !== planReq) return;
        submitAfterPlan = false;
        $('#qtPlanState').textContent = '⚠️ offline';
        schedulePlan(5000);
      });
  }
  function inLabel(min) {
    if (min == null) return '';
    if (min < 0) return 'departed';
    if (min < 60) return 'in ' + min + ' min';
    var h = Math.floor(min / 60), m = min % 60;
    if (h < 24) return 'in ' + h + 'h' + (m ? ' ' + m + 'm' : '');
    return 'in ' + Math.floor(h / 24) + 'd ' + (h % 24) + 'h';
  }
  function money(n) { return '₹' + Number(n || 0).toLocaleString('en-IN'); }
  /* The NPR aid for a Nepal desk. Rounded to a whole rupee the way
     nprEstimate() does server-side, so the clerk and the quote agree. */
  function npr(n) {
    if (!NPR_PEG || !Number(n)) return '';
    return 'NPR ' + Math.round(Number(n) * NPR_PEG).toLocaleString('en-IN');
  }
  function nprNote(n) {
    var s = npr(n);
    return s ? '<span class="qt-npr">≈ ' + s + '</span>' : '';
  }
  function renderPlan() {
    if (!plan) return;
    var f = plan.fare || {};
    var seatsTxt = seatLabelJoin(plan.seats, plan.coach, plan.bookingMode);
    var html = ''
      + '<div class="qt-route"><span class="qt-dir">' + esc(plan.directionLabel || plan.direction) + (plan.extraBus ? ' · extra bus' : '') + '</span>'
      + '<b>' + esc(plan.from) + ' → ' + esc(plan.to) + '</b>'
      + (plan.busName ? '<small>' + esc(plan.busName) + (plan.busNumber ? ' · ' + esc(plan.busNumber) : '') + '</small>' : '') + '</div>'
      + '<div class="qt-facts">'
      + '<div><small>Travel date</small><b>' + esc(plan.dateLabel) + '</b><em>' + (plan.isToday ? '✓ today' : 'next running day') + (plan.departed ? ' · already departed' : '') + '</em></div>'
      + '<div><small>Bus departs</small><b>' + esc(plan.depTime || '—') + '</b><em>' + esc(plan.from) + '</em></div>'
      + '<div><small>Boarding · चढ्ने ठाउँ</small><b>' + esc(plan.boardingCode) + ' · ' + esc(plan.boardingName) + '</b><em>' + esc(plan.boardingTime || '') + (plan.departsInMin != null ? ' · ' + inLabel(plan.departsInMin) : '') + '</em></div>'
      + '<div><small>Seat' + ((plan.seats || []).length > 1 ? 's' : '') + '</small><b>' + esc(seatsTxt) + '</b><em>' + esc(plan.seatsLeft) + ' free · ' + esc(plan.coach) + '</em></div>'
      + '<div class="big"><small>Fare · भाडा</small><b>' + money(f.total) + nprNote(f.total) + '</b><em>' + esc(plan.seatCount) + ' × ' + money(f.perSeat) + (f.groupDiscount > 0 ? ' · group −' + money(f.groupDiscount) : '') + (f.fee > 0 ? ' + fee ' + money(f.fee) : '') + '</em></div>'
      + '</div>'
      + '<div class="qt-why">' + (plan.matchedDesk ? '📍 Desk pickup remembered — <b>' + esc(plan.boardingName) + '</b>.' : '📍 First pickup still ahead. Tap a stop under Options → Boarding to make it this desk\'s default.') + '</div>';
    if (plan.alternatives && plan.alternatives.length) {
      plan.alternatives.forEach(function (a) {
        html += '<div class="qt-alt">↔ Other bus: <b>' + esc(a.route) + '</b> · ' + esc(a.dateLabel) + ' ' + esc(a.depTime) + ' · ' + esc(a.boardingName) + (a.boardingTime ? ' ' + esc(a.boardingTime) : '') + ' · ' + esc(a.seatsLeft) + ' free '
          + '<button type="button" data-alt="' + esc(a.direction) + '">Switch</button></div>';
      });
    }
    $('#qtPlanBody').innerHTML = html;
    $$('button[data-alt]', $('#qtPlanBody')).forEach(function (b) {
      b.addEventListener('click', function () {
        var dir = this.getAttribute('data-alt');
        st.direction = dir;
        $$('button[data-v]', $('#qtDir')).forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-v') === dir); });
        summary(); schedulePlan(0);
      });
    });
    // A fresh plan means "back at step 1" — unless a ticket is still on
    // screen (the plan also refreshes right after a sale, for the next one).
    if ($('#qtResult').hidden) step(1);
  }
  function renderStops() {
    var box = $('#qtBoard'); if (!box || !plan) return;
    var html = '<button type="button" data-v=""' + (!st.boarding ? ' class="on"' : '') + '>Auto</button>';
    (plan.stops || []).forEach(function (s) {
      var on = st.boarding && plan.matchedDesk && s.name === plan.boarding.replace(/\s@\s.*$/, '');
      html += '<button type="button" data-v="' + esc(s.name) + '" class="' + (on ? 'on ' : '') + (s.ahead ? '' : 'dim') + '" title="' + esc(s.name) + '">'
        + esc(s.code) + ' ' + esc(s.short) + (s.time ? ' · ' + esc(s.time) : '') + '</button>';
    });
    box.innerHTML = html;
  }

  /* ---- stepper + stopwatch ------------------------------------------ */
  function step(n) {
    [1, 2, 3].forEach(function (i) {
      var li = $('#qtSt' + i); if (!li) return;
      li.classList.toggle('on', i === n);
      li.classList.toggle('done', i < n);
    });
  }
  function startClock() {
    if (t0) return;
    t0 = Date.now();
    $('#qtClock').classList.add('run'); $('#qtClock').classList.remove('done');
    clockTimer = setInterval(function () { $('#qtTime').textContent = ((Date.now() - t0) / 1000).toFixed(1); }, 100);
  }
  function stopClock() {
    clearInterval(clockTimer); clockTimer = null;
    var s = t0 ? ((Date.now() - t0) / 1000) : 0;
    $('#qtTime').textContent = s.toFixed(1);
    $('#qtClock').classList.remove('run'); $('#qtClock').classList.add('done');
    t0 = 0;
    return s;
  }
  function resetClock() { clearInterval(clockTimer); clockTimer = null; t0 = 0; $('#qtTime').textContent = '0.0'; $('#qtClock').classList.remove('run', 'done'); }
  ['#qtName', '#qtPhone'].forEach(function (id) {
    $(id).addEventListener('input', function () { hideErr(); if (!t0 && (this.value || '').trim() !== '') startClock(); });
  });
  $('#qtName').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#qtPhone').focus(); } });

  /* ---- sell --------------------------------------------------------- */
  function showErr(msg) { var el = $('#qtErr'); el.textContent = msg; el.hidden = false; }
  function hideErr() { $('#qtErr').hidden = true; }
  function validate(name, phone) {
    if (name.length < 2) return 'Passenger name लेख्नुहोस् · Enter the passenger name.';
    var d = phone.replace(/\D/g, '');
    /* A walk-in with no phone still gets a ticket at the desk (owner ask,
       point 9): blank is allowed and the server stores the walk-in
       placeholder. A number that IS typed must still be a real one. */
    if (d === '') return '';
    if (st.cc === 'NP') {
      if (!(d.length === 10 || (d.length === 13 && d.indexOf('977') === 0))) return 'Nepali mobile: 10 digits (98XXXXXXXX) or +977 98XXXXXXXX.';
    } else if (!(d.length === 10 || (d.length === 12 && d.indexOf('91') === 0))) {
      return 'Indian mobile: 10 digits (9XXXXXXXXX) or +91 9XXXXXXXXX.';
    }
    return '';
  }
  function fullPhone(phone) {
    var d = phone.replace(/\D/g, '');
    if (st.cc === 'NP' && d.length === 10) return '977' + d;
    if (st.cc === 'IN' && d.length === 10) return '91' + d;
    return d;
  }
  function setBusy(on) {
    busy = on;
    var b = $('#qtGo');
    b.disabled = on || !CAN;
    b.innerHTML = on ? '⏳ Issuing the ticket…' : '⚡ Confirm &amp; Issue Ticket →';
  }
  $('#qtForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (!CAN || busy) return;
    var name = $('#qtName').value.trim(), phone = $('#qtPhone').value.trim();
    var err = validate(name, phone);
    if (err) { showErr(err); ($('#qtName').value.trim().length < 2 ? $('#qtName') : $('#qtPhone')).focus(); return; }
    hideErr();
    if (!t0) startClock();
    step(2); setBusy(true);
    var dv = parseFloat($('#qtDisc').value) || 0;
    api({
      action: 'sell', name: name, phone: fullPhone(phone), country: st.cc, gender: st.gender, seats: st.seats, prefer: st.prefer,
      direction: st.direction, date: dateValue(), boarding: st.boarding, pay: st.pay,
      discountType: dv > 0 ? $('#qtDiscType').value : '', discountValue: dv, note: $('#qtNote').value.trim(),
      // 🤖 what the bot proposed for this sale (null when it proposed nothing) — scores the outcome loop
      bot: botPrefill || undefined,
      // 🌙 the Ready Queue card this sale came from, so the brain can score
      // its own overnight guess against a real ticket (0 = typed by hand).
      brainCard: brain.card || undefined,
      brainDraft: brain.draft || undefined
    }).then(function (j) {
      setBusy(false);
      if (!j.ok) { showErr(j.error || 'The ticket could not be issued.'); say('टिकट बनेन। स्क्रिनमा कारण हेर्नुहोस्।'); step(1); return; }
      var secs = stopClock();
      step(3);
      renderResult(j.data, secs);
      sayTicket(j.data);
      prependRecent(j.data);
      $('#qtLine').value = ''; $('#qtName').value = ''; $('#qtPhone').value = ''; $('#qtDisc').value = ''; $('#qtNote').value = '';
      st.prefer = []; st.preferPhone = '';
      botReset();
      // The card became a ticket — drop it from the queue and re-read.
      brain.card = 0; brain.draft = 0;
      loadBrain();
      schedulePlan(300);
    }).catch(function () {
      setBusy(false); step(1);
      showErr('Network problem — the ticket may or may not have been issued. Check Tickets before trying again.');
    });
  });

  /* 🔊 Voice (19 Sep 2026, owner: "like a human speaking"). At a busy window
     the eyes are on the passenger and the cash, not the screen - so the desk
     can HEAR that the ticket is done, which seat, and whether WhatsApp went.
     Off by default, remembered per device. Nepali text read by the phone's
     Nepali voice, else its Hindi voice (Devanagari reads naturally), else the
     default. Never throws: a phone without speech simply stays silent. */
  var voiceOn = false;
  try { voiceOn = localStorage.getItem('shg:qt:voice') === '1'; } catch (e) {}
  function voiceBtn() {
    var b = $('#qtVoice'); if (!b) return;
    b.textContent = voiceOn ? '🔊 Voice on' : '🔇 Voice off';
    b.setAttribute('aria-pressed', voiceOn ? 'true' : 'false');
    b.classList.toggle('on', voiceOn);
  }
  function pickVoice() {
    try {
      var vs = window.speechSynthesis.getVoices() || [];
      return vs.filter(function (v) { return /^ne/i.test(v.lang); })[0]
          || vs.filter(function (v) { return /^hi/i.test(v.lang); })[0] || null;
    } catch (e) { return null; }
  }
  function say(text) {
    if (!voiceOn || !text || !window.speechSynthesis) return;
    try {
      window.speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(text), v = pickVoice();
      if (v) { u.voice = v; u.lang = v.lang; } else { u.lang = 'hi-IN'; }
      u.rate = 0.95;
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }
  function sayTicket(d) {
    var seats = (d.seats || []).map(function (s) { return seatLabelJoin([s], 'sleeper', 'sharing'); }).join(', ');
    var wa = d.whatsapp || {};
    say('टिकट बन्यो। ' + (d.name ? d.name + ', ' : '') + 'सिट ' + seats + '। '
      + (d.totalLabel ? 'भाडा ' + String(d.totalLabel).replace('₹', '') + ' रुपैयाँ। ' : '')
      + (wa.sent ? 'टिकट व्हाट्सएपमा गयो।' : 'व्हाट्सएप गएन, बटन थिचेर पठाउनुहोस्।'));
  }
  (function () {
    var b = $('#qtVoice'); if (!b) return;
    if (!window.speechSynthesis) { b.hidden = true; return; }
    voiceBtn();
    b.addEventListener('click', function () {
      voiceOn = !voiceOn;
      try { localStorage.setItem('shg:qt:voice', voiceOn ? '1' : '0'); } catch (e) {}
      voiceBtn();
      say(voiceOn ? 'आवाज खुल्यो। टिकट बनेपछि म बोलेर सुनाउँछु।' : '');
    });
  })();

  function renderResult(d, secs) {
    var wa = d.whatsapp || {};
    var waCls = wa.sent ? 'ok' : 'warn';
    var waTxt = wa.sent
      ? '✅ WhatsApp sent automatically to <b>' + esc(wa.to) + '</b> (' + esc(wa.driver) + ').'
      : (wa.paused
          ? '⚠️ Twilio is refusing the account' + (wa.pausedWhy ? ' (' + esc(wa.pausedWhy) + ')' : '') + '. Automatic sends are paused until ' + esc(wa.pausedUntil) + ' and then retried on their own. Send this ticket now with one tap:'
          : (wa.attempted
              ? '⚠️ Automatic WhatsApp did not go' + (wa.configured ? ' (provider refused — sender / template not approved yet)' : ' (sender not live yet — KYC pending)') + '. Send it now with one tap:'
              : '💬 Automatic WhatsApp is off. Send it now with one tap:'));
    var html = ''
      + '<div class="qt-res-head"><span class="qt-ok">✅</span><div><b>Ticket ready · टिकट तयार</b>'
      + '<small>PNR <b>' + esc(d.pnr) + '</b>' + (d.ticketNumber ? ' · Ticket ' + esc(d.ticketNumber) : '') + ' · ' + esc(d.name) + ' · ' + esc(d.phone) + '</small></div>'
      + '<span class="qt-time-badge">⏱ ' + secs.toFixed(1) + 's</span></div>'
      + '<div class="qt-res-grid">'
      + '<div class="qt-res-img">' + (d.pngReady || d.viewUrl ? '<a href="' + esc(d.viewUrl) + '" target="_blank" rel="noopener"><img src="' + esc(d.viewUrl) + '" alt="Ticket ' + esc(d.pnr) + '"></a>' : '') + '</div>'
      + '<div><div class="qt-facts">'
      + '<div><small>Bus</small><b>' + esc(d.route) + '</b><em>' + esc(d.dateLabel) + ' · dep ' + esc(d.depTime) + '</em></div>'
      + '<div><small>Boarding</small><b>' + esc(d.boardingCode) + ' · ' + esc(d.boardingName) + '</b><em>' + esc(d.boardingTime) + '</em></div>'
      + '<div><small>Seat' + ((d.seats || []).length > 1 ? 's' : '') + '</small><b>' + esc(seatLabelJoin(d.seats, 'sleeper', 'sharing')) + '</b></div>'
      + '<div class="big"><small>Fare</small><b>' + esc(d.totalLabel) + nprNote(d.total) + '</b><em>received · ' + esc(st.pay) + '</em></div>'
      + '</div>'
      + '<div class="qt-wa ' + waCls + '">' + waTxt + (wa.link ? ' <a class="btn" href="' + esc(wa.link) + '" target="_blank" rel="noopener">💬 Send on WhatsApp</a>' : '') + '</div>'
      + '<div class="qt-actions">'
      + '<a class="btn" href="' + esc(d.imageUrl) + '">🖼 Download PNG</a>'
      + '<a class="btn" href="' + esc(d.pdfUrl) + '">📄 Download PDF</a>'
      + '<a class="btn" href="' + esc(d.printUrl) + '" target="_blank" rel="noopener">🖨 Print</a>'
      + '<a class="btn" href="' + esc(d.adminUrl) + '">📋 Open booking</a>'
      + '<button type="button" class="btn qt-next" id="qtNext">⚡ Next ticket</button>'
      + '</div></div></div>';
    var box = $('#qtResult');
    box.innerHTML = html; box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    $('#qtNext').addEventListener('click', function () {
      box.hidden = true; resetClock(); step(1); $('#qtLine').focus();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }
  function prependRecent(d) {
    var body = $('#qtRecentBody'); if (!body) return;
    var empty = body.querySelector('.qt-empty-row'); if (empty) empty.remove();
    var tr = document.createElement('tr'); tr.className = 'new';
    tr.innerHTML = '<td>' + esc(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })) + '</td>'
      + '<td><b>' + esc(d.name) + '</b></td><td>' + esc(d.phone) + '</td>'
      + '<td>' + esc(d.dateLabel) + '<br><small class="muted">' + esc(d.boardingCode + ' · ' + d.boardingName + (d.boardingTime ? ' · ' + d.boardingTime : '')) + '</small></td>'
      + '<td><b>' + esc(seatLabelJoin(d.seats, 'sleeper', 'sharing')) + '</b></td><td>' + esc(d.totalLabel) + '</td>'
      + '<td><a href="' + esc(d.adminUrl) + '">' + esc(d.pnr) + '</a> · <a href="' + esc(d.viewUrl) + '" target="_blank" rel="noopener">🖼</a></td>';
    body.insertBefore(tr, body.firstChild);
    var c = $('#qtRecentCount'); if (c) c.textContent = String((parseInt(c.textContent, 10) || 0) + 1);
  }

  /* ---- 🤖 QuickBot: one suggestion engine drives the whole desk -------- */
  var BOT_ON = <?= $bot['enabled'] ? 'true' : 'false' ?>;
  var MAXS = <?= (int) $maxSeats ?>;
  var botPrefill = null, lineTimer = null, submitAfterPlan = false;
  var SRC_LABEL = { input: 'you', text: 'line', history: 'history', desk: 'this desk', pattern: 'pattern', auto: 'auto', none: '—' };
  function srcTag(f) { return (f && f.source && f.source !== 'input' && f.source !== 'none') ? '<span class="qt-src ' + esc(f.source) + '">' + esc(SRC_LABEL[f.source] || f.source) + '</span>' : ''; }
  function pct(x) { return Math.round((Number(x) || 0) * 100) + '%'; }
  function pickChip(sel, v) {
    var hit = false;
    $$('button[data-v]', $(sel)).forEach(function (b) { var on = b.getAttribute('data-v') === String(v); b.classList.toggle('on', on); if (on) hit = true; });
    return hit;
  }
  function botReset() {
    botPrefill = null;
    var h = $('#qtBotHint'); h.hidden = true; h.innerHTML = '';
    var w = $('#qtWhy'); if (w) { w.hidden = true; w.innerHTML = ''; }
  }
  function setBotHint(html) { var h = $('#qtBotHint'); h.innerHTML = html; h.hidden = !html; }
  /* ONE clarifying question (SHG AI BRAIN Phase 2, 10 Sep 2026): when the line
     settled too little, the bot asks for the one fact it needs most, in the
     passenger's language; a tap fills the chip and re-plans. The "same as last
     time" tap sits here too: usual pickup, direction, party and berths. */
  function renderAsk(d) {
    var box = $('#qtAsk'); if (!box) return;
    var html = '';
    var a = d && d.ask;
    if (a && a.field && (a.options || []).length) {
      html += '<div class="qt-ask-q">❓ ' + esc(a.question) + '</div><div class="qt-ask-chips">'
        + a.options.map(function (o) { return '<button type="button" data-k="' + esc(a.field) + '" data-v="' + esc(o.value) + '">' + esc(o.label) + '</button>'; }).join('')
        + '</div>';
    }
    var same = d && d.sameAsLast;
    if (same && (same.seats || []).length && st.prefer.join(',') !== (same.seats || []).slice(0, 4).join(',')) {
      html += '<button type="button" class="qt-same" data-same="1">🔁 Same as last time · ' + esc(seatLabelJoin(same.seats, 'sleeper', 'sharing')) + (same.lastTrip ? ' · ' + esc(same.lastTrip) : '') + '</button>';
    }
    box.innerHTML = html;
    box.hidden = html === '';
  }
  $('#qtAsk').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.getAttribute('data-same')) {
      var s = (lastBot && lastBot.sameAsLast) || {};
      st.prefer = (s.seats || []).slice(0, 4);
      st.preferPhone = fullPhone($('#qtPhone').value.trim());
      if (s.boarding) st.boarding = s.boarding;
      if (s.direction) { st.direction = s.direction; pickChip('#qtDir', st.direction); }
      if (s.party > 1) { st.seats = Math.min(s.party, MAXS); pickChip('#qtSeats', st.seats); }
      schedulePlan(0); return;
    }
    var k = b.getAttribute('data-k'), v = b.getAttribute('data-v');
    if (k === 'date') {
      if (v === 'other') { var o = $('#qtOpts'); if (o) o.open = true; $('#qtDateIn').focus(); return; }
      st.date = 'custom'; $('#qtDateIn').value = v; $$('button[data-v]', $('#qtDate')).forEach(function (x) { x.classList.remove('on'); });
    } else if (k === 'seats') { st.seats = Math.min(parseInt(v, 10) || 1, MAXS); pickChip('#qtSeats', st.seats); }
    else if (k === 'direction') { st.direction = v; pickChip('#qtDir', st.direction); }
    else if (k === 'boarding') { st.boarding = v; }
    summary();
    schedulePlan(0);
  });
  function profileLine(p) {
    if (!p) return BOT_ON ? '<span class="new">🆕 New number — no earlier ticket on this mobile; the desk\'s patterns are used instead.</span>' : '<span class="new">Learning is switched off in Settings — the line is still read, nothing is remembered.</span>';
    var bits = ['<b>👤 ' + esc(p.name || 'Known passenger') + '</b>', esc(p.trips) + ' verified trip' + (p.trips === 1 ? '' : 's')];
    if (p.lastTrip) bits.push('last travelled ' + esc(p.lastTrip));
    if (p.boarding) bits.push('usually boards ' + esc(p.boarding.code + ' · ' + p.boarding.name) + ' (' + pct(p.boarding.share) + ')');
    if (p.direction) bits.push(p.direction.value === 'toNepal' ? 'mostly towards Nepal' : 'mostly back to Gujarat');
    if (p.party && p.party.size > 1) bits.push('party of ' + esc(p.party.size));
    if (p.deck) bits.push(esc(p.deck.value) + ' deck ' + pct(p.deck.share));
    if (p.companions && p.companions.length) bits.push('travels with ' + esc(p.companions.slice(0, 3).join(', ')));
    if (p.country === 'NP') bits.push('🇳🇵 Nepali number');
    return bits.join(' · ');
  }
  /* Put the engine's proposal into the form. Chips the clerk chose were sent
     as explicit input and come back unchanged; only what the LINE said or
     what MEMORY / the desk's PATTERNS supplied moves — and only when it
     differs, so a re-plan after a chip tap is a no-op here. */
  function applySuggestion(d) {
    var pf = d.prefill || {}, f = d.fields || {}, applied = [];
    var src = function (k) { return (f[k] && f[k].source) || ''; };
    var fromLine = function (k) { return src(k) === 'text'; };
    var fromMemory = function (k) { return src(k) === 'history' || src(k) === 'desk' || src(k) === 'pattern'; };
    if (pf.country && (fromLine('country') || src('country') === 'history') && st.cc !== pf.country) {
      st.cc = pf.country; $$('button[data-cc]', $('#qtCc')).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-cc') === st.cc); });
    }
    if (pf.phone && fromLine('phone') && $('#qtPhone').value.replace(/\D/g, '') !== pf.phone) $('#qtPhone').value = pf.phone;
    if (pf.name && (fromLine('name') || (fromMemory('name') && !$('#qtName').value.trim()))) {
      if ($('#qtName').value.trim() !== pf.name) $('#qtName').value = pf.name;
      if (fromMemory('name')) applied.push('name' + srcTag(f.name));
    }
    if (pf.gender && (fromLine('gender') || fromMemory('gender')) && st.gender !== pf.gender) { st.gender = pf.gender; pickChip('#qtGender', st.gender); applied.push('gender ' + esc(pf.gender) + srcTag(f.gender)); }
    if (pf.seats > 1 && (fromLine('seats') || fromMemory('seats')) && st.seats !== pf.seats) { st.seats = Math.min(pf.seats, MAXS); pickChip('#qtSeats', st.seats); applied.push(st.seats + ' seats' + srcTag(f.seats)); }
    if (pf.direction && (fromLine('direction') || fromMemory('direction')) && st.direction !== pf.direction) { st.direction = pf.direction; pickChip('#qtDir', st.direction); applied.push((pf.direction === 'toNepal' ? 'going' : 'return') + srcTag(f.direction)); }
    if (pf.boarding && (fromLine('boarding') || fromMemory('boarding')) && st.boarding !== pf.boarding) { st.boarding = pf.boarding; applied.push('📍 ' + esc(pf.boarding.split(/\s[—–-]\s|,|·/)[0].trim()) + srcTag(f.boarding)); }
    if (pf.date && fromLine('date') && dateValue() !== pf.date) { st.date = 'custom'; $('#qtDateIn').value = pf.date; $$('button[data-v]', $('#qtDate')).forEach(function (x) { x.classList.remove('on'); }); applied.push('📅 ' + esc(pf.date)); }
    if (pf.pay && fromLine('pay') && st.pay !== pf.pay) { st.pay = pf.pay; pickChip('#qtPay', st.pay); applied.push(esc(pf.pay)); }
    botPrefill = (fromMemory('name') || fromMemory('gender') || fromMemory('boarding') || fromMemory('direction') || fromMemory('seats'))
      ? { name: fromMemory('name') ? pf.name : '', gender: fromMemory('gender') ? pf.gender : null, boarding: fromMemory('boarding') ? pf.boarding : '', direction: fromMemory('direction') ? pf.direction : '', seats: fromMemory('seats') ? pf.seats : 0 }
      : null;
    var typed = $('#qtLine').value.trim() !== '' || $('#qtPhone').value.replace(/\D/g, '').length >= 10;
    if (typed) {
      var line = '🤖 ' + profileLine(d.profile || null);
      if (applied.length) line += '<br>filled: ' + applied.join(' · ') + ' — change any chip if the passenger says otherwise.';
      setBotHint(line);
    } else {
      setBotHint('');
    }
    var w = $('#qtWhy');
    if (w) {
      var rs = (d.reasons || []).filter(function (r) { return !/read from your line|word found in your line|^Next catchable|^Default pickup/.test(r); });
      w.innerHTML = rs.length ? rs.map(function (r) { return '• ' + esc(r); }).join('<br>') : '';
      w.hidden = !rs.length;
    }
    summary();
  }
  function fireSubmit() {
    var ev; try { ev = new Event('submit', { cancelable: true }); } catch (e) { ev = document.createEvent('Event'); ev.initEvent('submit', true, true); }
    $('#qtForm').dispatchEvent(ev);
  }
  // The smart line: read on every pause; Enter = the one tap.
  $('#qtLine').addEventListener('input', function () { hideErr(); if (!t0 && this.value.trim() !== '') startClock(); clearTimeout(lineTimer); lineTimer = setTimeout(function () { schedulePlan(0); }, 450); });
  $('#qtLine').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    clearTimeout(lineTimer);
    if (busy) return;
    submitAfterPlan = true;          // parse first, then sell — never sell a line that was not read
    schedulePlan(0);
  });
  // A mobile typed straight into the field looks the passenger up too.
  $('#qtPhone').addEventListener('input', function () { clearTimeout(lineTimer); lineTimer = setTimeout(function () { schedulePlan(0); }, 400); });

  /* ---- 🌙 Ready Queue ------------------------------------------------
     The night shift's output. A card is not a booking: tapping one only
     fills this same form, so the sale still goes through plan() + the
     confirm tap + every rule. brainCard / brainDraft ride along with the
     sale so the brain learns which guesses actually became tickets. ---- */
  var brain = { cards: [], drafts: [], alerts: [], card: 0, draft: 0 };

  function brainRender() {
    var box = $('#qtBrain'), body = $('#qtBrainBody');
    if (!box || !body) return;
    var n = brain.cards.length + brain.drafts.length + brain.alerts.length;
    if (!n) { box.hidden = true; return; }
    box.hidden = false;

    var html = '';
    if (brain.drafts.length) {
      html += '<div class="qt-brain-sub">📥 आफैँ आएका request (' + brain.drafts.length + ')</div><div class="qt-cards">';
      brain.drafts.forEach(function (d, i) {
        html += '<div class="qt-card draft" data-kind="draft" data-i="' + i + '" role="button" tabindex="0">'
             +  '<button class="qt-card-x" data-x="draft" data-id="' + d.id + '" title="Clear">&times;</button>'
             +  '<div class="qt-card-top"><span class="qt-card-name">' + esc(d.passenger || d.phone) + '</span>'
             +  '<span class="qt-card-pct">' + (d.confidence | 0) + '%</span></div>'
             +  '<div class="qt-card-trip">' + esc(brainTrip(d)) + '</div>'
             +  (d.text ? '<div class="qt-card-quote">“' + esc(d.text) + '”</div>' : '')
             +  (d.flags && d.flags.length ? '<div class="qt-card-flags">⚠️ ' + esc(d.flags.join(' · ')) + '</div>' : '')
             +  '</div>';
      });
      html += '</div>';
    }

    if (brain.cards.length) {
      html += '<div class="qt-brain-sub">🔮 आज call गर्न सक्ने (' + brain.cards.length + ')</div><div class="qt-cards">';
      brain.cards.forEach(function (c, i) {
        html += '<div class="qt-card ' + esc(c.band || 'cool') + '" data-kind="card" data-i="' + i + '" role="button" tabindex="0">'
             +  '<button class="qt-card-x" data-x="card" data-id="' + c.id + '" title="Not calling">&times;</button>'
             +  '<div class="qt-card-top"><span class="qt-card-name">' + esc(c.passenger || c.phone) + '</span>'
             +  '<span class="qt-card-pct">' + Math.round((c.score || 0) / 10) + '%</span></div>'
             +  '<div class="qt-card-trip">' + esc(brainTrip(c)) + '</div>'
             +  (c.reason && c.reason.length ? '<div class="qt-card-why">' + esc(c.reason[0]) + '</div>' : '')
             +  (c.stale ? '<div class="qt-card-stale">carried over from ' + esc(c.predictDate) + '</div>' : '')
             +  '</div>';
      });
      html += '</div>';
    }

    if (brain.alerts.length) {
      html += '<div class="qt-brain-sub">📣 ध्यान दिनुपर्ने</div><div class="qt-alerts">';
      brain.alerts.forEach(function (a) {
        html += '<div class="qt-alert ' + esc(a.severity || 'info') + '"><div><b>' + esc(a.title) + '</b>'
             +  (a.body ? '<br>' + esc(a.body) : '') + '</div>'
             +  '<button class="qt-alert-x" data-x="alert" data-id="' + (a.id | 0) + '" title="Done">&times;</button></div>';
      });
      html += '</div>';
    }
    body.innerHTML = html;
  }

  function brainTrip(c) {
    var bits = [];
    if (c.travelDate) bits.push(fmtDay(c.travelDate));
    if (c.direction) bits.push(c.direction === 'toNepal' ? 'to Nepal' : 'to India');
    bits.push((c.seats || 1) + ' seat' + ((c.seats || 1) === 1 ? '' : 's'));
    if (c.boarding) bits.push(String(c.boarding).split('—')[0].split('@')[0].trim());
    return bits.join(' · ');
  }

  function fmtDay(iso) {
    var d = new Date(iso + 'T00:00:00');
    if (isNaN(d)) return iso;
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }

  /* Tapping a card is exactly "the desk typed this" — nothing is sold. */
  function brainUse(kind, i) {
    var c = (kind === 'draft' ? brain.drafts : brain.cards)[i];
    if (!c) return;
    brain.card  = kind === 'card'  ? (c.id | 0) : 0;
    brain.draft = kind === 'draft' ? (c.id | 0) : 0;

    $('#qtLine').value = '';
    $('#qtName').value = c.passenger || '';
    // Strip a country code ONLY when doing so leaves a real 10-digit mobile.
    // A blind /^91/ strip turned the Indian number 9198765432 into 98765432
    // and validate() then refused the sale, making that card unusable.
    var pd = String(c.phone || '').replace(/\D/g, '');
    if (pd.length === 13 && pd.indexOf('977') === 0) { pd = pd.slice(3); st.cc = 'NP'; }
    else if (pd.length === 12 && pd.indexOf('91') === 0) { pd = pd.slice(2); st.cc = 'IN'; }
    $('#qtPhone').value = pd;
    st.seats = c.seats || 1;
    st.direction = c.direction || '';
    st.boarding = c.boarding || st.boarding;
    // dateValue() speaks chips, not ISO: a predicted day is a "custom" date
    // carried in the date input, otherwise it would be silently dropped.
    if (c.travelDate) {
      var di = $('#qtDateIn');
      if (di) di.value = c.travelDate;
      st.date = 'custom';
    } else {
      st.date = '';
    }
    $$('button[data-v]', $('#qtSeats')).forEach(function (b) { b.classList.toggle('on', parseInt(b.getAttribute('data-v'), 10) === st.seats); });
    $$('button[data-v]', $('#qtDir')).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === st.direction); });
    $$('button[data-v]', $('#qtDate')).forEach(function (b) { b.classList.remove('on'); });

    if (!t0) startClock();
    summary();
    schedulePlan(0);
    $('#qtForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function brainDismiss(kind, id) {
    api({ action: 'brain_dismiss', kind: kind, id: id }).then(function () { loadBrain(); });
  }

  function loadBrain() {
    api({ action: 'brain_queue' }).then(function (j) {
      if (!j.ok || !j.data) return;
      brain.cards  = j.data.cards || [];
      brain.drafts = j.data.drafts || [];
      brain.alerts = j.data.alerts || [];
      var p = j.data.pending || {};
      $('#qtBrainCount').textContent = ((p.cards | 0) + (p.drafts | 0)) + ' waiting';
      var a = j.data.accuracy || {};
      $('#qtBrainAcc').textContent = (a.sample | 0) >= 10
        ? 'accuracy ' + Math.round((a.hitRate || 0) * 100) + '% of ' + a.sample
        : '';
      brainRender();
    }).catch(function () { /* the desk works without the brain */ });
  }

  $('#qtBrainBody').addEventListener('click', function (e) {
    var x = e.target.closest('[data-x]');
    if (x) { e.stopPropagation(); brainDismiss(x.getAttribute('data-x'), parseInt(x.getAttribute('data-id'), 10) || 0); return; }
    var card = e.target.closest('.qt-card');
    if (card) brainUse(card.getAttribute('data-kind'), parseInt(card.getAttribute('data-i'), 10) || 0);
  });
  $('#qtBrainBody').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var card = e.target.closest('.qt-card');
    if (card) { e.preventDefault(); brainUse(card.getAttribute('data-kind'), parseInt(card.getAttribute('data-i'), 10) || 0); }
  });

  /* ---- boot ---------------------------------------------------------- */
  summary();
  refreshPlan();
  loadBrain();
  // Keep "departs in" honest on a desk that stays open all day.
  setInterval(function () { if (!document.hidden && !busy) refreshPlan(); }, 60 * 1000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) schedulePlan(0); });
  if (!$('#qtName').value && !$('#qtPhone').value) $('#qtLine').focus(); else if (!$('#qtPhone').value) $('#qtPhone').focus(); else schedulePlan(0);
})();
</script>
<?php admin_footer();
