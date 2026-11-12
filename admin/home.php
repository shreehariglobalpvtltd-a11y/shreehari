<?php
/**
 * admin/home.php — "🏠 गृह · Home", the ONE dashboard (30 Oct 2026).
 *
 * Owner: "ONE dashboard — agent, admin, staff sabai euta dashboard bhitra,
 * khali kholda pharak. Counter le counter ko kam, agent le agent ko kam,
 * sabai le ticket katna milos, admin le admin ko kam. Euta mobile ma 2-3 ID
 * bhaye sahi ma jaos."
 *
 * Every sign-in lands here (Auth::homeUrl(), admin/login.php — a next= deep
 * link still wins). Same page for everyone, opened differently:
 *   - who you are: photo, name, role, ID number, card status, My ID card,
 *     Switch ID (only when your own mobile carries another active ID),
 *     Admin panel (dashboard.view)
 *   - your numbers: an agent's money + today's sales, a counter's drawer,
 *     the office's "needs you" list — never a company figure to a field role
 *   - big tiles in your role's order, the rest under "＋ थप काम · More"
 * Each tile carries the check its page boots with (includes/homeboard.php),
 * so nothing here opens a door that was shut before.
 *
 * Switch ID: signs this ID out and opens the one door on the same mobile —
 * the other ID's own password, the WhatsApp code or "hi" (then "which ID?").
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/homeboard.php';
require_once INCLUDE_PATH . '/teamportal.php';
if (is_file(INCLUDE_PATH . '/agentpro.php')) {
    require_once INCLUDE_PATH . '/agentpro.php';
}
$admin = admin_boot();
$me    = (int) ($admin['id'] ?? 0);
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$flash = null;

/* ---- Switch ID: out of this one, into the door with the same mobile ready ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · This page was open too long, please try again.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'switch') {
        $row = Database::fetch('SELECT id, phone FROM admins WHERE id = :i', ['i' => $me]);
        $p10 = TeamPortal::phone10((string) ($row['phone'] ?? ''));
        if ($p10 === '' || HomeBoard::otherIds($me) === 0) {
            $flash = ['bad', 'यो ID मा अर्को ID भएको मोबाइल छैन — कार्यालयलाई भन्नुहोस् · No other ID on this mobile — ask the office.'];
        } else {
            $intl = TeamPortal::intlFor($p10, $row);
            Logger::audit('admin.switch', 'admin', (string) $me, null, null, 'Switch ID: signed out to open another ID on the same mobile');
            Auth::adminLogout();
            $_SESSION['one_door'] = ['kind' => 'mobile', 'phone' => $p10, 'cc' => str_starts_with($intl, '977') ? '977' : '91', 'id' => '', 't' => time()];
            Response::redirect('admin/login.php?step=mobile');
        }
    }
}
if ($flash === null && is_string($_SESSION['home_flash'] ?? null)) {
    $flash = ['ok', (string) $_SESSION['home_flash']];
}
unset($_SESSION['home_flash']);

$m = null;
if (class_exists('AgentPro') && AgentPro::available()) {
    try {
        $m = AgentPro::member($me, false);
    } catch (Throwable $ex) {
        $m = null;
    }
}
$office = HomeBoard::isOffice();
$counts = [];
if ($office && class_exists('AgentPro')) {
    $counts = AgentPro::counts();   // company numbers: the office only
}
$tiles  = HomeBoard::tiles($admin, $m, $counts);
$strip  = HomeBoard::strip($admin, $counts);
$others = (string) ($admin['role'] ?? '') === 'viewer' ? 0 : HomeBoard::otherIds($me);

$name   = trim((string) ($m['name'] ?? ($admin['full_name'] ?? ''))) ?: (string) ($admin['username'] ?? '');
$first  = explode(' ', $name)[0] ?? $name;
$roleL  = (string) ($m['roleLabel'] ?? ucfirst((string) ($admin['role'] ?? '')));
$idNo   = (string) ($m['idNo'] ?? '');
$status = (string) ($m['status'] ?? '');
$chip   = match ($status) {
    'valid'   => ['ok', '✅ मान्य · Valid'],
    'expired' => ['warn', '⏳ म्याद सकियो · Expired'],
    'suspended', 'revoked', 'inactive' => ['bad', '⛔ रोकिएको · Suspended'],
    default   => null,
};
$photo  = (string) ($m['photoUrl'] ?? '');
$viaOtp = !empty($admin['via_otp']) && empty($admin['must_change_pw']);   // same rule as the top bar (_guard.php)
$tile = static function (array $t) use ($e): string {
    return '<a class="hb-tile" href="' . $e($t['href']) . '"><i aria-hidden="true">' . $e($t['icon']) . '</i>'
         . '<b>' . $e($t['ne']) . '</b><small>' . $e($t['en']) . '</small>'
         . ($t['badge'] > 0 ? '<span class="hb-badge">' . (int) $t['badge'] . '</span>' : '') . '</a>';
};

/* =====================================================================
 *  12 Nov 2026 — "Management" home (owner: "khulepachhi dherai details —
 *  ticket, bus chalani / seat / fleet, agent: sabai ekai thau ma, minimal,
 *  mobile ra desktop dubai ma"). Three blocks, one screen:
 *    Tickets · Bus · Agents — each: live numbers + the few actions that
 *    matter. Every link is taken from admin_nav() (the permission-filtered
 *    menu), so a block never shows a page the menu would hide. Everything
 *    else stays one tap away under "More".
 * ===================================================================== */
require_once INCLUDE_PATH . '/quickunlock.php';
$navOk = [];
foreach (admin_nav() as $n) {
    $navOk[(string) ($n['href'] ?? '')] = true;
}

/* 12 Nov 2026 (owner: "agent, admin ra customer lai jodne — AI le sahaj, fast"):
   ONE box. A PNR, part of a mobile or a name answers right here with the
   ticket, its customer and the agent who sold it, each one tap from
   WhatsApp. Anything that reads as an order ("Surat ko bhada 2300") goes on
   to S Hari AI exactly as before (it still asks before it changes anything). */
$askQ    = trim(Security::clean((string) ($_GET['q'] ?? ''), 120));
$askHits = [];
$askTeam = [];
$askFwd  = false;
$canAi   = isset($navOk['easy-settings.php']);
$canFind = Auth::can('bookings.view');
$waOf = static function (string $phone, string $cc = ''): string {
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($d) === 10) {
        $d = (in_array($cc, ['91', '977'], true) ? $cc : '91') . $d;
    }
    return strlen($d) >= 11 ? 'https://wa.me/' . $d : '';
};
if ($askQ !== '' && mb_strlen($askQ) >= 2) {
    $isCmd = false;
    if ($canAi) {
        try {
            require_once INCLUDE_PATH . '/aiops.php';
            $isCmd = AiOps::parseWords($askQ) !== [];
        } catch (Throwable $ex) {
            $isCmd = false;
        }
    }
    if (!$isCmd && $canFind) {
        try {
            $scopeId = Auth::bookingScopeAdminId();
            $like    = '%' . $askQ . '%';
            $dg      = preg_replace('/\D/', '', $askQ) ?? '';
            $pLike   = strlen($dg) >= 4 ? '%' . $dg . '%' : $like;
            $askHits = Database::fetchAll(
                "SELECT b.id, b.pnr, b.contact_phone, b.contact_country_code, b.status, b.total_amount,
                        r.from_city, r.to_city, s.travel_date,
                        a.id AS agent_id, a.full_name AS agent_name, a.phone AS agent_phone, a.role AS agent_role,
                        (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.id LIMIT 1) AS pax_name,
                        (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax_n
                   FROM bookings b
                   LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                   LEFT JOIN schedules s     ON s.id = bl.schedule_id
                   LEFT JOIN routes r        ON r.id = s.route_id
                   LEFT JOIN admins a        ON a.id = b.sold_by_admin_id
                  WHERE (b.pnr LIKE :qA OR b.contact_phone LIKE :qB
                         OR EXISTS (SELECT 1 FROM booking_passengers bp WHERE bp.booking_id = b.id AND bp.full_name LIKE :qC))"
                . ($scopeId !== null ? ' AND b.sold_by_admin_id = :ag' : '') .
                " ORDER BY b.id DESC LIMIT 6",
                ['qA' => $like, 'qB' => $pLike, 'qC' => $like] + ($scopeId !== null ? ['ag' => $scopeId] : [])
            );
            if ($scopeId === null && isset($navOk['people.php'])) {
                $askTeam = Database::fetchAll(
                    "SELECT id, full_name, phone, role FROM admins
                      WHERE is_active = 1 AND role <> 'superadmin' AND (full_name LIKE :qA OR phone LIKE :qB OR username LIKE :qC)
                      ORDER BY id DESC LIMIT 4",
                    ['qA' => $like, 'qB' => $pLike, 'qC' => $like]
                );
            }
        } catch (Throwable $ex) {
            $askHits = [];
            $askTeam = [];
        }
    }
    // a short miss (a name, a number) is just "not found" — only a sentence goes on to the AI by itself
    $askFwd = $canAi && ($isCmd || ($askHits === [] && $askTeam === [] && count(preg_split('/\s+/', $askQ) ?: []) >= 3));
}
$lnk = static function (string $href, string $icon, string $ne, string $en, int $badge = 0, bool $big = false) use ($navOk, $e): string {
    if (!isset($navOk[$href])) {
        return '';
    }
    $url = str_starts_with($href, '/') ? $href : '/admin/' . $href;
    return '<a class="mg-a' . ($big ? ' big' : '') . '" href="' . $e($url) . '"><i aria-hidden="true">' . $icon . '</i><span><b>' . $e($ne) . '</b><small>' . $e($en) . '</small></span>'
         . ($badge > 0 ? '<em>' . $badge . '</em>' : '') . '</a>';
};

/* numbers — an agent sees only their own tickets, a counter only its desk (same rule as admin/hub.php) */
$scopeId = Auth::bookingScopeAdminId();
$deskSc  = null;
if ($scopeId === null) {
    require_once INCLUDE_PATH . '/counterdesk.php';
    $d = Auth::deskScopeCode();
    $deskSc = ($d !== null && CounterDesk::stampColumn()) ? $d : null;
}
$bkCount = static function (string $cond) use ($scopeId, $deskSc): int {
    $w = [$cond];
    $pr = [];
    if ($scopeId !== null) {
        $w[] = 'b.sold_by_admin_id = :sc';
        $pr['sc'] = $scopeId;
    } elseif ($deskSc !== null) {
        $w[] = CounterDesk::scopeClause('b', 'dk');
        $pr['dk'] = $deskSc;
    }
    try {
        return (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE ' . implode(' AND ', $w), $pr, 0);
    } catch (Throwable $ex) {
        return 0;
    }
};
$canTk   = Auth::can('bookings.view');
$tkToday = $canTk ? $bkCount("b.status IN ('confirmed','completed') AND DATE(b.created_at) = CURDATE()") : 0;
$tkPend  = $canTk ? $bkCount("b.status = 'pending'") : 0;

$trips = [];
if (Auth::can('schedules.view') && $scopeId === null) {
    try {
        $trips = Database::fetchAll(
            "SELECT s.id AS sid, s.travel_date, s.seats_booked, s.total_seats,
                    r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    bu.bus_number, bu.bus_name
               FROM schedules s
               JOIN routes r      ON r.id = s.route_id
               LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
              WHERE s.travel_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                AND r.is_active = 1 AND s.is_blocked = 0
              ORDER BY s.travel_date, COALESCE(s.dep_time_override, r.dep_time), s.id
              LIMIT 8"
        );
    } catch (Throwable $ex) {
        $trips = [];
    }
}
$nAgents = (int) ($counts['agents'] ?? 0);
$nApps   = (int) ($counts['applications'] ?? 0);
$nMoney  = (int) ($counts['withdrawals'] ?? 0) + (int) ($counts['deposits'] ?? 0);
$nLive   = (int) ($counts['liveBuses'] ?? 0);
$qk      = QuickUnlock::on() ? QuickUnlock::status($me) : ['pin' => true, 'pattern' => true];
$agentV  = $scopeId !== null;

$colT = $lnk('quick-ticket.php', '⚡', 'टिकट काट्ने', 'Cut a ticket (10 s)', 0, true)
      . $lnk('/index.php?counter=1#/', '💺', 'सिट हेरेर बुकिङ', 'Book on the seat map')
      . $lnk('bookings.php', '🔎', $agentV ? 'मेरा टिकट' : 'सबै टिकट', 'Find · edit · print')
      . $lnk('payments.php', '✅', 'भुक्तानी जाँच', 'Verify payments', $tkPend)
      . $lnk('reschedule.php', '🔁', 'मिति / सिट सार्ने', 'Change date · transfer')
      . $lnk('refunds.php', '↩️', 'रद्द / फिर्ता', 'Cancel & refund')
      . $lnk('scan.php', '📷', 'टिकट स्क्यान', 'Scan boarding QR')
      . $lnk('ticket-statement.php', '📊', 'कसले कति काट्यो', 'Ticket statement');
$colB = $lnk('bus-control.php', '🚌', 'बस कन्ट्रोल', 'Trips · seats · fleet · add bus', 0, true)
      . $lnk('manifest.php', '📋', 'चलानी', 'Chalani & seat chart')
      . $lnk('schedule.php', '🗓️', 'बस रुटिन', 'Which bus, which day')
      . $lnk('routes.php', '📍', 'स्टप र भाडा', 'Stops & fares')
      . $lnk('map.php', '🛰️', 'बस कहाँ छ', 'Live bus map', $nLive)
      . $lnk('drivers.php', '👨‍✈️', 'चालक / स्टाफ', 'Drivers & crew');
$colA = $lnk('agent-quick.php', '➕', 'नयाँ एजेन्ट', 'Quick agent + ID card', 0, true)
      . $lnk('people.php', '👥', 'एजेन्ट र स्टाफ', 'List · login · commission')
      . $lnk('team.php', '🪪', 'स्वीकृति र पैसा', 'Approvals · wallets · ID cards', $nApps + $nMoney)
      . $lnk('agent-360.php', '💰', 'एजेन्ट हिसाब', 'Per-agent money')
      . $lnk('counters.php', '🏪', 'काउन्टर', 'Counters & collection');
if ($agentV && isset($navOk['agent.php'])) {
    $colA = $lnk('agent.php', '💼', 'मेरो हिसाब', 'My wallet & commission', 0, true);
}
$cols = array_filter(
    ['t' => ['🎫', 'टिकट', 'Tickets', $colT], 'b' => ['🚌', 'बस', 'Bus', $colB], 'a' => ['👥', $agentV ? 'हिसाब' : 'एजेन्ट', $agentV ? 'Account' : 'Agents', $colA]],
    static fn(array $c): bool => $c[3] !== ''
);
$fmtT = static fn($t): string => $t ? date('g:i A', (int) strtotime((string) $t)) : '';

admin_header('Management', 'home.php');
?>
<style>
.mg{max-width:1120px;margin:0 auto}
.mg-me{display:flex;align-items:center;gap:10px;margin:0 0 10px}
.mg-av{width:40px;height:40px;border-radius:50%;object-fit:cover;flex:0 0 auto}
.mg-ini{width:40px;height:40px;border-radius:50%;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;background:#0054A8}
.mg-me div{flex:1;min-width:0}.mg-me h2{margin:0;font-size:16px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-me p{margin:1px 0 0;font-size:12px;color:var(--mut)}
.mg-ib{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:40px;padding:0 12px;border-radius:99px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
.mg-ib.lock{background:#0B1220;color:#fff;border-color:#0B1220}
.mg-bar{display:grid;gap:8px;margin:0 0 10px}
.mg-bar form{display:flex;gap:6px}
.mg-bar input{flex:1;min-width:0;min-height:44px;padding:0 14px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:inherit;font:inherit;font-size:15px}
.mg-bar button{min-height:44px;padding:0 14px;border-radius:12px;border:0;background:#0054A8;color:#fff;font:inherit;font-weight:800;cursor:pointer;white-space:nowrap}
.mg-bar .ai button{background:#F07C1F}
.mg-ask{margin:0 0 12px;padding:10px;border-radius:14px;border:1px solid var(--line);background:var(--card);display:grid;gap:8px}
.mg-ask p{margin:0;font-size:13.5px;opacity:.85}.mg-ask .more a{color:inherit}
.mg-ask>button{justify-self:start;min-height:40px;padding:0 14px;border-radius:10px;border:0;background:#F07C1F;color:#fff;font:inherit;font-weight:800}
.mg-hit{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:8px 10px;border-radius:10px;background:rgba(127,127,127,.08)}
.mg-hit b{font-size:15px}.mg-hit small{display:block;font-size:12.5px;opacity:.75;margin-top:2px}
.mg-hit .st{font-size:11px;font-weight:800;text-transform:uppercase;padding:2px 7px;border-radius:99px;background:rgba(127,127,127,.2);margin-left:4px}
.mg-hit .st.confirmed,.mg-hit .st.completed{background:#DCFCE7;color:#166534}.mg-hit .st.pending{background:#FEF3C7;color:#92400E}.mg-hit .st.cancelled{background:#FEE2E2;color:#991B1B}
.mg-hit .go{display:flex;gap:6px;flex-wrap:wrap}
.mg-hit .go a{display:inline-flex;align-items:center;min-height:38px;padding:0 12px;border-radius:99px;border:1px solid var(--line);color:inherit;text-decoration:none;font-weight:800;font-size:13px;white-space:nowrap}
.mg-hit .go a.wa{border-color:#128C4A;color:#128C4A}
.mg-strip{display:flex;gap:8px;overflow-x:auto;margin:0 0 10px;padding-bottom:2px;scrollbar-width:none}
.mg-strip a{flex:0 0 auto;max-width:86vw;padding:9px 13px;border-radius:99px;border:1px solid var(--line);background:var(--card);color:inherit;text-decoration:none;font-weight:700;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-strip a.hot{border-color:#F07C1F;color:#B4560C}
.mg-tabs{display:flex;gap:6px;margin:0 0 10px;padding:4px;border-radius:14px;background:var(--card);border:1px solid var(--line);position:sticky;top:6px;z-index:5}
.mg-tabs button{flex:1;min-height:42px;border:0;border-radius:10px;background:transparent;color:inherit;font:inherit;font-size:14px;font-weight:800;cursor:pointer}
.mg-tabs button.on{background:#0054A8;color:#fff}
.mg-cols{display:grid;gap:12px}
.mg-col{display:none;border:1px solid var(--line);border-radius:16px;background:var(--card);padding:12px;min-width:0}
.mg-col.on{display:block}
.mg-col>h3{display:none;margin:0 0 10px;font-size:15px}
@media (min-width:980px){.mg-tabs{display:none}.mg-cols{grid-template-columns:repeat(auto-fit,minmax(290px,1fr));align-items:start}.mg-col{display:block}.mg-col>h3{display:block}}
.mg-n{display:flex;gap:8px;margin:0 0 10px}
.mg-n a{flex:1;padding:9px 10px;border-radius:12px;background:rgba(0,84,168,.07);color:inherit;text-decoration:none}
.mg-n b{display:block;font-size:22px;line-height:1.1}.mg-n small{font-size:11.5px;color:var(--mut);font-weight:700}
.mg-n a.hot{background:rgba(240,124,31,.14)}
.mg-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mg-a{position:relative;display:flex;align-items:center;gap:9px;min-height:56px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);color:inherit;text-decoration:none;min-width:0}
.mg-a:active{background:rgba(0,84,168,.08)}
.mg-a i{font-style:normal;font-size:20px;flex:0 0 auto}
.mg-a span{min-width:0}.mg-a b{display:block;font-size:13.5px;line-height:1.2}.mg-a small{display:block;font-size:11px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-a.big{grid-column:1/-1;min-height:60px;background:#0054A8;border-color:#0054A8;color:#fff}
.mg-a.big b{font-size:16px}.mg-a.big small{color:rgba(255,255,255,.85)}
.mg-a em{position:absolute;top:-6px;right:-4px;min-width:22px;height:22px;padding:0 6px;border-radius:99px;background:#D7263D;color:#fff;font-style:normal;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center}
.mg-trips{display:grid;gap:6px;margin:0 0 10px}
.mg-trip{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);font-size:13px}
.mg-trip div{flex:1;min-width:0}.mg-trip b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-trip small{color:var(--mut)}
.mg-trip a{flex:0 0 auto;min-height:36px;display:inline-flex;align-items:center;padding:0 10px;border-radius:9px;border:1px solid var(--line);color:inherit;text-decoration:none;font-weight:700;font-size:12px}
.mg-bar2{height:4px;border-radius:9px;background:rgba(127,127,127,.2);margin-top:4px;overflow:hidden}.mg-bar2 u{display:block;height:100%;background:#1E9E5A}
details.mg-more{margin-top:12px}
details.mg-more>summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:center;min-height:44px;border-radius:12px;border:1.5px dashed var(--line);font-weight:800;font-size:13.5px}
details.mg-more>summary::-webkit-details-marker{display:none}
.mg-more .mg-list{margin-top:10px}
@media (min-width:760px){.mg-more .mg-list{grid-template-columns:repeat(4,minmax(0,1fr))}}
.mg-foot{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:16px 0 4px}
.mg-flash{padding:10px 13px;border-radius:11px;font-weight:700;margin-bottom:10px}
.mg-flash.ok{background:#DEF4E4;color:#14602E}.mg-flash.bad{background:#F7DCDC;color:#8A1F1F}
</style>
<div class="mg">
  <?php if ($flash !== null): ?><div class="mg-flash <?= $e($flash[0]) ?>" role="status"><?= $e($flash[1]) ?></div><?php endif; ?>

  <section class="mg-me" aria-label="You">
    <?php if ($photo !== ''): ?><img class="mg-av" src="<?= $e($photo) ?>" alt="" width="40" height="40">
    <?php else: ?><span class="mg-ini" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($name, 0, 1)) ?: '★') ?></span><?php endif; ?>
    <div><h2><?= $e($first) ?></h2><p><?= $e($roleL) ?><?= $idNo !== '' ? ' · ' . $e($idNo) : '' ?><?= $chip !== null && $chip[0] !== 'ok' ? ' · ' . $e($chip[1]) : '' ?></p></div>
    <?php if ($others > 0): ?>
    <form method="post" action="/admin/home.php"><?= Security::csrfField() ?><input type="hidden" name="action" value="switch">
      <button class="mg-ib" type="submit" title="ID बदल्नुहोस् · Switch ID" aria-label="Switch ID">🔁</button></form>
    <?php endif; ?>
    <a class="mg-ib lock" href="/admin/logout.php" title="Lock — opens again with your pattern / PIN">🔒 Lock</a>
  </section>

  <div class="mg-bar">
    <?php if ($canAi || $canFind): ?>
    <form method="get" action="/admin/home.php" class="ai">
      <input name="q" value="<?= $askFwd ? '' : $e($askQ) ?>" maxlength="120" placeholder="<?= $canFind ? 'PNR / मोबाइल / नाम खोज्नुहोस्' : 'AI लाई भन्नुहोस्' ?><?= $canAi ? ' — वा AI लाई: Surat ko bhada 2300' : '' ?>" aria-label="Find a ticket, customer or agent — or tell S Hari AI" autocomplete="off" enterkeyhint="search"><button type="submit"><?= $canAi ? 'AI ➜' : '🔎' ?></button></form>
    <?php endif; ?>
  </div>

  <?php if ($askFwd): ?>
  <form method="post" action="/admin/easy-settings.php" id="mgFwd" class="mg-ask">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd"><input type="hidden" name="cmd" value="<?= $e($askQ) ?>">
    <p>🤖 “<?= $e($askQ) ?>” — S Hari AI लाई पठाउँदै…</p><button type="submit">AI ➜</button>
  </form>
  <script>document.getElementById('mgFwd').submit();</script>
  <?php elseif ($askQ !== '' && mb_strlen($askQ) >= 2): ?>
  <section class="mg-ask" aria-label="Answer">
    <?php if ($askHits === [] && $askTeam === []): ?>
      <p>“<?= $e($askQ) ?>” — केही भेटिएन · nothing found.</p>
      <?php if ($canAi): ?><form method="post" action="/admin/easy-settings.php"><?= Security::csrfField() ?><input type="hidden" name="act" value="cmd"><input type="hidden" name="cmd" value="<?= $e($askQ) ?>"><button type="submit" style="min-height:40px;padding:0 14px;border-radius:10px;border:0;background:#F07C1F;color:#fff;font:inherit;font-weight:800">🤖 AI लाई सोध्ने</button></form><?php endif; ?>
    <?php endif; ?>
    <?php foreach ($askHits as $h):
        $cWa = $waOf((string) $h['contact_phone'], (string) ($h['contact_country_code'] ?? ''));
        $aWa = '';
        if ((int) ($h['agent_id'] ?? 0) > 0 && (int) $h['agent_id'] !== $me) {
            try {
                $p10 = TeamPortal::phone10((string) ($h['agent_phone'] ?? ''));
                $aWa = $p10 !== '' ? 'https://wa.me/' . TeamPortal::intlFor($p10, ['id' => (int) $h['agent_id'], 'phone' => (string) $h['agent_phone']]) : '';
            } catch (Throwable $ex) {
                $aWa = $waOf((string) ($h['agent_phone'] ?? ''));
            }
        }
        $st = (string) $h['status']; ?>
    <div class="mg-hit">
      <div><b><?= $e($h['pax_name'] ?: 'Passenger') ?><?= (int) $h['pax_n'] > 1 ? ' +' . ((int) $h['pax_n'] - 1) : '' ?></b>
        <span class="st <?= $e($st) ?>"><?= $e($st) ?></span>
        <small><?= $e($h['pnr']) ?><?= !empty($h['from_city']) ? ' · ' . $e($h['from_city'] . ' → ' . $h['to_city']) : '' ?><?= !empty($h['travel_date']) ? ' · ' . $e(formatDate((string) $h['travel_date'], 'j M')) : '' ?> · <?= $e(inr((float) $h['total_amount'])) ?><?= !empty($h['agent_name']) ? ' · बेच्ने: ' . $e($h['agent_name']) : '' ?></small></div>
      <div class="go">
        <a href="/admin/booking-view.php?pnr=<?= urlencode((string) $h['pnr']) ?>">टिकट</a>
        <?php if ($cWa !== ''): ?><a class="wa" target="_blank" rel="noopener" href="<?= $e($cWa) ?>">💬 ग्राहक</a><?php endif; ?>
        <?php if ($aWa !== ''): ?><a class="wa" target="_blank" rel="noopener" href="<?= $e($aWa) ?>">💬 एजेन्ट</a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php foreach ($askTeam as $t):
        $tWa = '';
        try {
            $p10 = TeamPortal::phone10((string) ($t['phone'] ?? ''));
            $tWa = $p10 !== '' ? 'https://wa.me/' . TeamPortal::intlFor($p10, ['id' => (int) $t['id'], 'phone' => (string) $t['phone']]) : '';
        } catch (Throwable $ex) {
            $tWa = '';
        } ?>
    <div class="mg-hit">
      <div><b><?= $e($t['full_name']) ?></b> <span class="st"><?= $e($t['role']) ?></span><small><?= $e($t['phone']) ?></small></div>
      <div class="go">
        <a href="/admin/team-member.php?id=<?= (int) $t['id'] ?>">खोल्ने</a>
        <a href="/admin/bookings.php?agent=<?= (int) $t['id'] ?>">टिकट</a>
        <?php if ($tWa !== ''): ?><a class="wa" target="_blank" rel="noopener" href="<?= $e($tWa) ?>">💬</a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="more"><a href="/admin/search.php?q=<?= urlencode($askQ) ?>">सबै नतिजा · all results</a> · <a href="/admin/home.php">✕ बन्द</a></p>
  </section>
  <?php endif; ?>

  <?php $needQk = !($qk['pin'] || $qk['pattern']);
  if ($strip !== [] || $needQk): ?>
  <div class="mg-strip">
    <?php if ($needQk): ?><a class="hot" href="/admin/quick-unlock.php">🔐 Pattern / PIN राख्नुहोस् — अर्को पटक छिटो खुल्छ · Set a pattern or PIN</a><?php endif; ?>
    <?php foreach ($strip as $s): ?><a class="<?= $s['hot'] ? 'hot' : '' ?>" href="<?= $e($s['href']) ?>"><?= $e($s['text']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($cols !== []): $firstKey = array_key_first($cols); ?>
  <div class="mg-tabs" role="tablist">
    <?php foreach ($cols as $k => $c): ?><button type="button" role="tab" data-mg="<?= $k ?>"<?= $k === $firstKey ? ' class="on"' : '' ?>><?= $c[0] ?> <?= $e($c[1]) ?></button><?php endforeach; ?>
  </div>
  <div class="mg-cols" style="--n:<?= count($cols) ?>">
    <?php foreach ($cols as $k => $c): ?>
    <section class="mg-col<?= $k === $firstKey ? ' on' : '' ?>" data-mgc="<?= $k ?>">
      <h3><?= $c[0] ?> <?= $e($c[1]) ?> · <?= $e($c[2]) ?></h3>
      <?php if ($k === 't' && $canTk): ?>
        <div class="mg-n">
          <a href="/admin/bookings.php"><b><?= $tkToday ?></b><small><?= $agentV ? 'मेरो आज · My tickets today' : 'आजको टिकट · Today' ?></small></a>
          <a class="<?= $tkPend > 0 ? 'hot' : '' ?>" href="/admin/<?= isset($navOk['payments.php']) ? 'payments.php' : 'bookings.php?status=pending' ?>"><b><?= $tkPend ?></b><small>भुक्तानी बाँकी · Pending</small></a>
        </div>
      <?php elseif ($k === 'b' && $trips !== []): ?>
        <div class="mg-trips">
          <?php foreach ($trips as $t):
              $tot  = max(1, (int) $t['total_seats']);
              $sold = (int) $t['seats_booked'];
              $day  = (string) $t['travel_date'] === date('Y-m-d') ? 'आज' : 'भोलि'; ?>
          <div class="mg-trip">
            <div><b><?= $e($t['from_city']) ?> → <?= $e($t['to_city']) ?></b>
              <small><?= $e($day) ?> <?= $e($fmtT($t['dep_time'])) ?> · <?= $e($t['bus_name'] ?: ($t['bus_number'] ?: 'Bus —')) ?> · <?= $sold ?>/<?= $tot ?> seat</small>
              <div class="mg-bar2"><u style="width:<?= min(100, (int) round($sold * 100 / $tot)) ?>%"></u></div></div>
            <?php if (isset($navOk['manifest.php'])): ?><a href="/admin/manifest.php?date=<?= $e($t['travel_date']) ?>&amp;sid=<?= (int) $t['sid'] ?>">चलानी</a><?php endif; ?>
            <a href="/admin/bus-control.php?date=<?= $e($t['travel_date']) ?>">सिट</a>
          </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($k === 'a' && !$agentV && $office): ?>
        <div class="mg-n">
          <a href="/admin/people.php"><b><?= $nAgents ?></b><small>सक्रिय एजेन्ट · Active</small></a>
          <a class="<?= $nApps + $nMoney > 0 ? 'hot' : '' ?>" href="/admin/team.php?tab=approvals"><b><?= $nApps + $nMoney ?></b><small>पर्खिरहेको काम · Waiting</small></a>
        </div>
      <?php endif; ?>
      <div class="mg-list"><?= $c[3] ?></div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php $rest = array_merge($tiles['hero'], $tiles['more']);
  if ($rest !== []): ?>
  <details class="mg-more"<?= $cols === [] ? ' open' : '' ?>>
    <summary>＋ अरू सबै · Everything else (<?= count($rest) ?>)</summary>
    <div class="mg-list">
      <?php foreach ($rest as $t): ?>
        <a class="mg-a" href="<?= $e($t['href']) ?>"><i aria-hidden="true"><?= $e($t['icon']) ?></i><span><b><?= $e($t['ne']) ?></b><small><?= $e($t['en']) ?></small></span><?= $t['badge'] > 0 ? '<em>' . (int) $t['badge'] . '</em>' : '' ?></a>
      <?php endforeach; ?>
      <?php if ($office): ?><a class="mg-a" href="/admin/index.php"><i aria-hidden="true">📈</i><span><b>ड्यासबोर्ड</b><small>Full dashboard</small></span></a>
      <a class="mg-a" href="/admin/hub.php"><i aria-hidden="true">🗂️</i><span><b>सबै पेज</b><small>All pages by category</small></span></a><?php endif; ?>
    </div>
  </details>
  <?php endif; ?>

  <div class="mg-foot">
    <?php if ((string) ($admin['role'] ?? '') !== 'viewer'): ?><a class="mg-ib" href="/me/#id">🪪 My ID</a><?php endif; ?>
    <a class="mg-ib" href="/admin/quick-unlock.php">🔐 Pattern / PIN</a>
    <?php if (!$viaOtp): ?><a class="mg-ib" href="/admin/change-password.php">🔑 Password</a><?php endif; ?>
  </div>
</div>
<script>
(function(){
  var tabs=document.querySelectorAll('[data-mg]'),cols=document.querySelectorAll('[data-mgc]');
  function show(k){var ok=false;cols.forEach(function(c){var on=c.getAttribute('data-mgc')===k;c.classList.toggle('on',on);ok=ok||on;});
    if(!ok)return;tabs.forEach(function(t){t.classList.toggle('on',t.getAttribute('data-mg')===k);});try{localStorage.setItem('shg_mg_tab',k);}catch(e){}}
  tabs.forEach(function(t){t.addEventListener('click',function(){show(t.getAttribute('data-mg'));});});
  try{var s=localStorage.getItem('shg_mg_tab');if(s&&document.querySelector('[data-mgc="'+s+'"]'))show(s);}catch(e){}
})();
</script>
<?php admin_footer();
