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
/* 12 Nov 2026 (owner: "app kholne bittikai setup boot jasto — PIN / pattern ra
   security question"): once per sign-in, an account that has not finished
   the two goes to admin/setup.php first ("Later" there skips it). */
/* 12 Nov 2026 (mg12, owner: "app khulne bittikai pattern / PIN setup hataideu —
   tyo nabhaye password matra aos"): the wizard is no longer forced. It runs
   only when the office switches it on (setting staff_setup_wizard, default
   off); a PIN / pattern stays a choice under "Me". No PIN = the door asks
   for the password, exactly as admin/login.php always did. */
if (PHP_SAPI !== 'cli' && Settings::getBool('staff_setup_wizard', false) && empty($_SESSION['setup_later']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && !isset($_GET['q']) && $flash === null && QuickUnlock::on()) {
    $suSt = QuickUnlock::status($me);
    if (!($suSt['pin'] || $suSt['pattern']) || (QuickUnlock::secReady() && QuickUnlock::secQuestion($me) === '')) {
        Response::redirect('admin/setup.php');
    }
}
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
    /* 13 Nov 2026 (mg20, owner: "AI lai agent jasto kam garne"): a line that reads as a
       ticket ORDER — a name and a mobile, e.g. "Ram 9812345678 Surat bholi 2 jana" —
       opens SmartBook with the order already read into the card (QuickBot). The person
       checks it and taps Book: the AI fills, a human sells. A bare mobile stays a search. */
    if (!$isCmd && Auth::isSellingStaff() && Auth::can('bookings.view') && preg_match('/\d{10}/', preg_replace('/[\s-]/', '', $askQ) ?? '') === 1) {
        try {
            require_once INCLUDE_PATH . '/smartbookbot.php';
            $ord = SmartBookBot::fields($askQ, 'admin');
            if ($ord['name'] !== '' && $ord['phone'] !== '') {
                Response::redirect('admin/smartbook.php?order=' . rawurlencode($askQ));
            }
        } catch (Throwable $ex) {
            // not an order after all: fall through to the search
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

/* =====================================================================
 *  12 Nov 2026 (mg12) — the home is a LAUNCHER, like a phone's home screen
 *  (owner: "open bhayepachhi main main widget haru ko list dekhiyos, tes
 *  bhitra gayera management garna milos — tracking chuttai, chalani
 *  chuttai, Android OS jasto"). One icon per job; a tap opens that app's
 *  own window (a sheet on a phone, a window on a desktop) with its few
 *  actions. Every link still comes from admin_nav(), so an app only shows
 *  what the menu would, and an app with nothing in it is not drawn.
 * ===================================================================== */
$fmtT = static fn($t): string => $t ? date('g:i A', (int) strtotime((string) $t)) : '';
$tripRows = static function (string $btn) use ($trips, $navOk, $e, $fmtT): string {
    if ($trips === []) {
        return '';
    }
    $h = '<h4 class="os-h">आज र भोलिका बस · Today &amp; tomorrow</h4><div class="mg-trips">';
    foreach ($trips as $t) {
        $tot  = max(1, (int) $t['total_seats']);
        $sold = (int) $t['seats_booked'];
        $day  = (string) $t['travel_date'] === date('Y-m-d') ? 'आज' : 'भोलि';
        $h .= '<div class="mg-trip"><div><b>' . $e($t['from_city']) . ' → ' . $e($t['to_city']) . '</b>'
            . '<small>' . $e($day) . ' ' . $e($fmtT($t['dep_time'])) . ' · ' . $e($t['bus_name'] ?: ($t['bus_number'] ?: 'Bus —')) . ' · ' . $sold . '/' . $tot . ' seat</small>'
            . '<div class="mg-bar2"><u style="width:' . min(100, (int) round($sold * 100 / $tot)) . '%"></u></div></div>';
        if ($btn === 'chalani' && isset($navOk['manifest.php'])) {
            $h .= '<a href="/admin/manifest.php?date=' . $e($t['travel_date']) . '&amp;sid=' . (int) $t['sid'] . '">चलानी</a>';
        }
        if ($btn === 'seat' && isset($navOk['bus-control.php'])) {
            $h .= '<a href="/admin/bus-control.php?date=' . $e($t['travel_date']) . '">सिट</a>';
        }
        if ($btn === 'live' && isset($navOk['map.php'])) {
            $h .= '<a href="/admin/map.php">🛰 Live</a>';
        }
        $h .= '</div>';
    }
    return $h . '</div>';
};
$nums = static function (array $pairs) use ($e): string {
    $h = '<div class="mg-n">';
    foreach ($pairs as [$href, $n, $label, $hot]) {
        $h .= '<a class="' . ($hot ? 'hot' : '') . '" href="' . $e($href) . '"><b>' . (int) $n . '</b><small>' . $e($label) . '</small></a>';
    }
    return $h . '</div>';
};
$raw = static fn(string $href, string $icon, string $ne, string $en): string
    => '<a class="mg-a" href="' . $e($href) . '"><i aria-hidden="true">' . $icon . '</i><span><b>' . $e($ne) . '</b><small>' . $e($en) . '</small></span></a>';

$apps = [];   // key => [icon, ne, en, colour, top widget html, links html, badge]
$apps['t'] = ['🎫', 'टिकट', 'Tickets', '#0054A8',
    $canTk ? $nums([
        ['/admin/bookings.php', $tkToday, $agentV ? 'मेरो आज · Mine today' : 'आजको टिकट · Today', false],
        ['/admin/' . (isset($navOk['payments.php']) ? 'payments.php' : 'bookings.php?status=pending'), $tkPend, 'भुक्तानी बाँकी · Pending', $tkPend > 0],
    ]) : '',
    $lnk('quick-ticket.php', '⚡', 'छिटो फारम', 'Fast form — ticket in 10 s', 0, true)
    . $lnk('/index.php?counter=1#/', '📝', 'पूरा फारम (सिट नक्सा)', 'Full form — seat map, all details')
    . $lnk('bookings.php', '🔎', $agentV ? 'मेरा टिकट' : 'सबै टिकट', 'Find · edit · print')
    . $lnk('payments.php', '✅', 'भुक्तानी जाँच', 'Verify payments', $tkPend)
    . $lnk('reschedule.php', '🔁', 'मिति / सिट सार्ने', 'Change date · transfer')
    . $lnk('refunds.php', '↩️', 'रद्द / फिर्ता', 'Cancel & refund')
    . $lnk('missed-bus.php', '⏰', 'बस छुटेको', 'Missed-bus rebooking')
    . $lnk('scan.php', '📷', 'टिकट स्क्यान', 'Scan boarding QR')
    . $lnk('wa-queue.php', '💬', 'WhatsApp टिकट', 'Tickets asked on WhatsApp')
    . $lnk('offers.php', '🏷️', 'अफर / छुट', 'Offers & discounts'),
    $tkPend];
$apps['c'] = ['📋', 'चलानी', 'Chalani', '#0F766E',
    $tripRows('chalani'),
    $lnk('manifest.php', '📋', 'चलानी र सिट चार्ट', 'Chalani — screen · print · PDF', 0, true)
    . $lnk('agent-passengers.php', '🧍', $agentV ? 'मेरा यात्रु' : 'एजेन्टका यात्रु', 'Passengers by agent')
    . ($scopeId === null && isset($navOk['manifest.php']) ? $raw('/chalani-live.php', '🔗', 'लाइभ चलानी लिंक', 'Public live chalani') : ''),
    0];
$apps['s'] = ['💺', 'सिट', 'Seats', '#7C3AED',
    $tripRows('seat'),
    $lnk('bus-control.php', '💺', 'आजको सिट (पैसा अनुसार)', 'Seats of a date — paid · due · free', 0, true)
    . $lnk('seatmap.php', '🪑', 'सिट नक्सा · सिट सार्ने', 'Seat layout & shift seat')
    . $lnk('extra-seats.php', '➕', 'थप सिट (EX)', 'Extra seats'),
    0];
$apps['b'] = ['🚌', 'बस', 'Bus', '#B45309',
    '',
    $lnk('bus-control.php', '🚌', 'बस कन्ट्रोल', 'Trips · fleet · add bus · on/off', 0, true)
    . $lnk('schedule.php', '🗓️', 'बस रुटिन', 'Which bus, which day')
    . $lnk('calendar.php', '📅', 'बस क्यालेन्डर', 'Bus calendar')
    . $lnk('trips.php', '🔄', 'बसको पालो', '3-bus rotation · trips board')
    . $lnk('buses.php', '🛠️', 'बस र layout', 'Fleet & seat layout')
    . $lnk('drivers.php', '👨‍✈️', 'चालक / स्टाफ', 'Drivers & crew'),
    0];
$apps['l'] = ['🛰️', 'ट्र्याकिङ', 'Tracking', '#0369A1',
    $tripRows('live'),
    $lnk('map.php', '🛰️', 'बस कहाँ छ (लाइभ)', 'Live bus map', $nLive, true)
    . $lnk('gps-trackers.php', '📡', 'बसमा GPS जोड्ने', 'Connect a GPS tracker to a bus')
    . $lnk('gps-center.php', '🧭', 'GPS कन्ट्रोल', 'GPS control center')
    . $lnk('/navigate.html', '🗺️', 'यात्रुको नक्सा', 'Passenger live map')
    . $lnk('nav-reports.php', '⚠️', 'बाटोको रिपोर्ट', 'Road reports'),
    $nLive];
$apps['p'] = ['📍', 'पिकअप / ड्रप', 'Pickup · Drop', '#BE123C',
    '',
    $lnk('routes.php', '📍', 'स्टप र भाडा', 'Pickup / drop stops & fares', 0, true)
    . $lnk('counters.php', '🏪', 'काउन्टर', 'Counters & collection')
    . $lnk('agreements.php', '🧭', 'ठाउँ अनुसार एजेन्ट', 'Agents by location')
    . $lnk('places.php', '🏞️', 'घुम्ने ठाउँ', 'Places to visit'),
    0];
$apps['a'] = ['👥', $agentV ? 'हिसाब' : 'एजेन्ट', $agentV ? 'Account' : 'Agents', '#15803D',
    !$agentV && $office ? $nums([
        ['/admin/people.php', $nAgents, 'सक्रिय एजेन्ट · Active', false],
        ['/admin/team.php?tab=approvals', $nApps + $nMoney, 'पर्खिरहेको काम · Waiting', $nApps + $nMoney > 0],
    ]) : '',
    $agentV
        ? $lnk('agent.php', '💼', 'मेरो हिसाब', 'My wallet & commission', 0, true)
        : $lnk('agent-quick.php', '➕', 'नयाँ एजेन्ट', 'Quick agent + ID card', 0, true)
          . $lnk('people.php', '👥', 'एजेन्ट र स्टाफ', 'List · login · commission')
          . $lnk('team.php', '🪪', 'स्वीकृति र ID कार्ड', 'Approvals · wallets · ID cards', $nApps + $nMoney)
          . $lnk('staff.php', '🔑', 'लगइन र पासवर्ड', 'Staff logins & passwords')
          . $lnk('permissions.php', '🛡️', 'अधिकार', 'Roles & permissions')
          . $lnk('team-invite.php', '✉️', 'निम्तो / नवीकरण', 'Invites & renewals'),
    $agentV ? 0 : $nApps + $nMoney];
$apps['h'] = ['📊', 'एजेन्ट ३६० · हिसाब', 'Agent 360 · Statement', '#4338CA',
    '',
    $lnk('ticket-statement.php', '📊', $agentV ? 'मेरो टिकट (मिति अनुसार)' : 'कसले कति काट्यो (मिति अनुसार)', 'Date-wise tickets · fix commission', 0, true)
    . $lnk('agent-statement.php', '📜', $agentV ? 'मेरो स्टेटमेन्ट' : 'एजेन्ट स्टेटमेन्ट', 'From–to date statement · PDF')
    . $lnk('agent-360.php', '💰', 'एजेन्ट ३६०', 'Everything about one agent')
    . $lnk('settlements.php', '🤝', 'हिसाब मिलान', 'Commission & wallet settlement')
    . $lnk('agent-sales.php', '🧾', $agentV ? 'मेरो बिक्री' : 'एजेन्ट बिक्री', 'Sales by agent')
    . $lnk('agent-ranking.php', '🏆', 'र्‍याङ्किङ', 'Agent rankings'),
    0];
$apps['m'] = ['💵', 'पैसा', 'Money', '#166534',
    '',
    $lnk('cash-book.php', '📒', 'क्यास बुक', 'Cash book', 0, true)
    . $lnk('payments.php', '✅', 'भुक्तानी जाँच', 'Verify payments', $tkPend)
    . $lnk('shift.php', '🕒', 'सिफ्ट र नगद', 'Shift & cash handover')
    . $lnk('accounting.php', '📚', 'लेखा / ऋण', 'Accounting, loans & ledgers')
    . $lnk('payroll.php', '💼', 'तलब', 'Salary / payroll')
    . $lnk('reconcile.php', '⚖️', 'पैसा मिलान', 'Money reconciliation')
    . $lnk('pnl.php', '📈', 'नाफा / घाटा', 'Profit & loss')
    . $lnk('analytics.php', '📉', 'रिपोर्ट', 'Analytics'),
    0];
$apps['w'] = ['💬', 'म्यासेज र AI', 'Messages & AI', '#128C4A',
    '',
    $lnk('wa-inbox.php', '💬', 'WhatsApp इनबक्स', 'Chats & groups', 0, true)
    . $lnk('wa-manager.php', '📲', 'WhatsApp म्यानेजर', 'Sending, wallet, API status')
    . $lnk('wa-outbox.php', '📤', 'पठाएको', 'Outbox')
    . $lnk('wa-templates.php', '🧩', 'टेम्प्लेट', 'WhatsApp templates')
    . $lnk('facebook.php', '📘', 'Messenger AI', 'Facebook Messenger')
    . $lnk('ai-brain.php', '🤖', 'AI ले के जवाफ दिन्छ', 'One AI — the WhatsApp brain')
    . $lnk('ai-activity.php', '🧠', 'AI ले के गर्‍यो', 'AI activity')
    . $lnk('messages-log.php', '🗒️', 'म्यासेज लग', 'WhatsApp & SMS logs')
    . (isset($navOk['settings.php']) ? $raw('/admin/settings.php#cat=messages', '🔑', 'API key र सेटिङ', 'WhatsApp API · Messenger · AI keys') : ''),
    0];
$apps['g'] = ['⚙️', 'सेटिङ', 'Settings', '#475569',
    '',
    $lnk('easy-settings.php', '🤖', 'AI लाई भनेर बदल्ने', 'Tell S Hari AI — it shows, then changes', 0, true)
    . $lnk('settings.php', '⚙️', 'सबै सेटिङ (५ भाग)', 'All settings in 5 groups')
    . $lnk('company.php', '🏢', 'कम्पनी र शाखा', 'Company & branches')
    . $lnk('sessions.php', '🛡️', 'लगइन सुरक्षा', 'Sessions & login security')
    . $lnk('backup.php', '💾', 'ब्याकअप', 'Backups')
    . $lnk('health.php', '🩺', 'सिस्टम ठीक छ?', 'System health')
    . $lnk('activity-log.php', '🧾', 'कसले के गर्‍यो', 'Activity log'),
    0];
$meLinks = '';
if ((string) ($admin['role'] ?? '') !== 'viewer') {
    $meLinks .= '<a class="mg-a big" href="/me/"><i aria-hidden="true">🪪</i><span><b>मेरो विवरण र कागजात</b><small>My details · ID card · documents</small></span></a>';
}
$meLinks .= $raw('/admin/quick-unlock.php', '🔐', 'लक: Pattern / PIN', ($qk['pin'] || $qk['pattern']) ? 'Change or remove' : 'Optional — opens faster next time');
if (!$viaOtp) {
    $meLinks .= $raw('/admin/change-password.php', '🔑', 'पासवर्ड', 'Change password');
}
$meLinks .= $raw('/admin/logout.php', '🔒', 'लक गर्ने', 'Lock this app now');
$apps['u'] = ['🙂', 'म', 'Me', '#0B1220', '', $meLinks, 0];

$rest = array_merge($tiles['hero'], $tiles['more']);
$restH = '';
foreach ($rest as $t) {
    $restH .= '<a class="mg-a" href="' . $e($t['href']) . '"><i aria-hidden="true">' . $e($t['icon']) . '</i><span><b>' . $e($t['ne']) . '</b><small>' . $e($t['en']) . '</small></span>'
            . ($t['badge'] > 0 ? '<em>' . (int) $t['badge'] . '</em>' : '') . '</a>';
}
if ($office) {
    $restH .= $raw('/admin/index.php', '📈', 'ड्यासबोर्ड', 'Full dashboard') . $raw('/admin/hub.php', '🗂️', 'सबै पेज', 'All pages by category');
}
$apps['x'] = ['🧩', 'अरू सबै', 'More', '#64748B', '', $restH, 0];
$apps = array_filter($apps, static fn(array $a): bool => $a[5] !== '');

/* the dock: the four things done all day, one tap, no window */
$dock = array_filter([
    isset($navOk['quick-ticket.php']) ? ['/admin/quick-ticket.php', '⚡', 'टिकट'] : null,
    isset($navOk['manifest.php']) ? ['/admin/manifest.php', '📋', 'चलानी'] : null,
    isset($navOk['map.php']) ? ['/admin/map.php', '🛰️', 'लाइभ'] : null,
    isset($navOk['bookings.php']) ? ['/admin/bookings.php', '🔎', 'खोज'] : null,
]);

admin_header('Management', 'home.php');
?>
<style>
.mg{max-width:1120px;margin:0 auto;padding-bottom:84px}
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
.mg-bar button{min-height:44px;padding:0 14px;border-radius:12px;border:0;background:#F07C1F;color:#fff;font:inherit;font-weight:800;cursor:pointer;white-space:nowrap}
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
.mg-n{display:flex;gap:8px;margin:0 0 10px}
.mg-n a{flex:1;padding:9px 10px;border-radius:12px;background:rgba(0,84,168,.07);color:inherit;text-decoration:none}
.mg-n b{display:block;font-size:22px;line-height:1.1}.mg-n small{font-size:11.5px;color:var(--mut);font-weight:700}
.mg-n a.hot{background:rgba(240,124,31,.14)}
.mg-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mg-a{position:relative;display:flex;align-items:center;gap:9px;min-height:56px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);color:inherit;text-decoration:none;min-width:0}
.mg-a:active{background:rgba(0,84,168,.08)}
.mg-a i{font-style:normal;font-size:20px;flex:0 0 auto}
.mg-a span{min-width:0}.mg-a b{display:block;font-size:13.5px;line-height:1.2}.mg-a small{display:block;font-size:11px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-a.big{grid-column:1/-1;min-height:60px;background:var(--osc,#0054A8);border-color:var(--osc,#0054A8);color:#fff}
.mg-a.big b{font-size:16px}.mg-a.big small{color:rgba(255,255,255,.85)}
.mg-a em{position:absolute;top:-6px;right:-4px;min-width:22px;height:22px;padding:0 6px;border-radius:99px;background:#D7263D;color:#fff;font-style:normal;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center}
.mg-trips{display:grid;gap:6px;margin:0 0 10px}
.mg-trip{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);font-size:13px}
.mg-trip div{flex:1;min-width:0}.mg-trip b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mg-trip small{color:var(--mut)}
.mg-trip a{flex:0 0 auto;min-height:36px;display:inline-flex;align-items:center;padding:0 10px;border-radius:9px;border:1px solid var(--line);color:inherit;text-decoration:none;font-weight:700;font-size:12px}
.mg-bar2{height:4px;border-radius:9px;background:rgba(127,127,127,.2);margin-top:4px;overflow:hidden}.mg-bar2 u{display:block;height:100%;background:#1E9E5A}
.mg-flash{padding:10px 13px;border-radius:11px;font-weight:700;margin-bottom:10px}
.mg-flash.ok{background:#DEF4E4;color:#14602E}.mg-flash.bad{background:#F7DCDC;color:#8A1F1F}
/* ---- launcher ---- */
.os-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px 8px;margin:14px 0 8px}
@media (min-width:620px){.os-grid{grid-template-columns:repeat(6,minmax(0,1fr));gap:22px 12px}}
@media (min-width:980px){.os-grid{grid-template-columns:repeat(8,minmax(0,1fr));gap:26px 14px;margin-top:22px}}
.os-app{position:relative;display:flex;flex-direction:column;align-items:center;gap:6px;padding:0;border:0;background:none;color:inherit;font:inherit;cursor:pointer;min-width:0;-webkit-tap-highlight-color:transparent;touch-action:manipulation}
.os-app i{font-style:normal;width:60px;height:60px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:28px;background:var(--c);background-image:linear-gradient(160deg,rgba(255,255,255,.28),rgba(255,255,255,0) 55%);box-shadow:0 6px 14px -6px var(--c),0 1px 0 rgba(255,255,255,.25) inset;transition:transform .12s ease}
.os-app:active i{transform:scale(.9)}
.os-app:focus-visible i{outline:3px solid #F07C1F;outline-offset:3px}
.os-app b{font-size:12.5px;font-weight:700;line-height:1.15;text-align:center;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.os-app small{font-size:10.5px;color:var(--mut);margin-top:-4px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.os-app em{position:absolute;top:-5px;left:calc(50% + 14px);min-width:22px;height:22px;padding:0 6px;border-radius:99px;background:#D7263D;color:#fff;font-style:normal;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg,#fff)}
@media (min-width:980px){.os-app i{width:68px;height:68px;font-size:32px;border-radius:20px}.os-app b{font-size:13.5px}}
.os-dock{position:fixed;left:50%;bottom:calc(10px + env(safe-area-inset-bottom,0px));transform:translateX(-50%);z-index:30;display:flex;gap:6px;padding:7px;border-radius:22px;background:rgba(11,18,32,.86);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);box-shadow:0 12px 30px -10px rgba(0,0,0,.5)}
.os-dock a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;width:68px;min-height:52px;border-radius:16px;color:#fff;text-decoration:none;font-size:11px;font-weight:700}
.os-dock a i{font-style:normal;font-size:21px;line-height:1}
.os-dock a:active{background:rgba(255,255,255,.16)}
.os-win{position:fixed;inset:0;z-index:60;display:flex;align-items:flex-end;justify-content:center;background:rgba(7,11,20,.55);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
.os-win[hidden]{display:none}
.os-sheet{width:100%;max-width:760px;max-height:88vh;max-height:88dvh;display:flex;flex-direction:column;border-radius:22px 22px 0 0;background:var(--bg,#fff);color:var(--ink,inherit);box-shadow:0 -18px 50px -18px rgba(0,0,0,.6);animation:osUp .2s ease-out}
.os-sheet header{display:flex;align-items:center;gap:10px;padding:12px 14px 10px;border-bottom:1px solid var(--line)}
.os-sheet header i{font-style:normal;width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;background:var(--osc)}
.os-sheet header h3{flex:1;margin:0;font-size:17px;font-weight:800;min-width:0}
.os-sheet header h3 small{display:block;font-size:12px;font-weight:600;color:var(--mut)}
.os-x{width:44px;height:44px;border-radius:50%;border:0;background:rgba(127,127,127,.16);color:inherit;font-size:20px;cursor:pointer}
.os-body{overflow-y:auto;overscroll-behavior:contain;padding:12px 14px calc(18px + env(safe-area-inset-bottom,0px))}
.os-body section[hidden]{display:none}
.os-h{margin:16px 0 8px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--mut)}
@media (min-width:760px){.os-win{align-items:center}.os-sheet{border-radius:20px;max-height:80vh;animation-name:osIn}.os-body .mg-list{grid-template-columns:repeat(3,minmax(0,1fr))}}
@keyframes osUp{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
@keyframes osIn{from{transform:scale(.96);opacity:.4}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){.os-sheet{animation:none}.os-app i{transition:none}}
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
    <a class="mg-ib lock" href="/admin/logout.php" title="Lock the app">🔒 Lock</a>
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
        <a href="/admin/agent-statement.php?agent=<?= (int) $t['id'] ?>">हिसाब</a>
        <a href="/admin/bookings.php?agent=<?= (int) $t['id'] ?>">टिकट</a>
        <?php if ($tWa !== ''): ?><a class="wa" target="_blank" rel="noopener" href="<?= $e($tWa) ?>">💬</a><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="more"><a href="/admin/search.php?q=<?= urlencode($askQ) ?>">सबै नतिजा · all results</a> · <a href="/admin/home.php">✕ बन्द</a></p>
  </section>
  <?php endif; ?>

  <?php if ($strip !== []): ?>
  <div class="mg-strip">
    <?php foreach ($strip as $s): ?><a class="<?= $s['hot'] ? 'hot' : '' ?>" href="<?= $e($s['href']) ?>"><?= $e($s['text']) ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <nav class="os-grid" aria-label="Apps">
    <?php foreach ($apps as $k => $a): ?>
    <button type="button" class="os-app" data-os="<?= $k ?>" style="--c:<?= $e($a[3]) ?>" aria-haspopup="dialog">
      <i aria-hidden="true"><?= $a[0] ?></i><b><?= $e($a[1]) ?></b><small><?= $e($a[2]) ?></small><?= $a[6] > 0 ? '<em>' . (int) $a[6] . '</em>' : '' ?>
    </button>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($dock !== []): ?>
<nav class="os-dock" aria-label="Quick actions">
  <?php foreach ($dock as $d): ?><a href="<?= $e($d[0]) ?>"><i aria-hidden="true"><?= $d[1] ?></i><?= $e($d[2]) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="os-win" id="osWin" hidden>
  <div class="os-sheet" role="dialog" aria-modal="true" aria-labelledby="osTitle">
    <header><i id="osIcon" aria-hidden="true"></i><h3 id="osTitle"></h3><button type="button" class="os-x" id="osX" aria-label="Close">✕</button></header>
    <div class="os-body">
      <?php foreach ($apps as $k => $a): ?>
      <section data-osw="<?= $k ?>" data-icon="<?= $a[0] ?>" data-ne="<?= $e($a[1]) ?>" data-en="<?= $e($a[2]) ?>" style="--osc:<?= $e($a[3]) ?>" hidden>
        <?php $numsTop = str_starts_with($a[4], '<div class="mg-n"'); /* live numbers lead; a trip list follows the actions */ ?>
        <?= $numsTop ? $a[4] : '' ?>
        <div class="mg-list"><?= $a[5] ?></div>
        <?= $numsTop ? '' : $a[4] ?>
      </section>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
(function(){
  var win=document.getElementById('osWin'),ttl=document.getElementById('osTitle'),ic=document.getElementById('osIcon'),last=null;
  if(!win)return;
  function secs(){return win.querySelectorAll('[data-osw]');}
  function open(k,push){
    var hit=null;secs().forEach(function(s){var on=s.getAttribute('data-osw')===k;s.hidden=!on;if(on)hit=s;});
    if(!hit)return;
    ttl.textContent=hit.getAttribute('data-ne');
    var sm=document.createElement('small');sm.textContent=hit.getAttribute('data-en');ttl.appendChild(sm);
    ic.textContent=hit.getAttribute('data-icon');
    win.querySelector('.os-sheet').style.setProperty('--osc',hit.style.getPropertyValue('--osc'));
    win.hidden=false;document.documentElement.style.overflow='hidden';
    win.querySelector('.os-body').scrollTop=0;
    if(push!==false){try{history.pushState({os:k},'','#app-'+k);}catch(e){}}
    var f=hit.querySelector('a');if(f&&window.matchMedia('(min-width:760px)').matches)f.focus();
  }
  function shut(){win.hidden=true;document.documentElement.style.overflow='';if(last){try{last.focus();}catch(e){}}}
  function close(){if(win.hidden)return;if(/^#app-/.test(location.hash)){try{history.back();return;}catch(e){}}shut();}
  document.querySelectorAll('[data-os]').forEach(function(b){b.addEventListener('click',function(){last=b;open(b.getAttribute('data-os'));});});
  document.getElementById('osX').addEventListener('click',close);
  win.addEventListener('click',function(ev){if(ev.target===win)close();});
  document.addEventListener('keydown',function(ev){if(ev.key==='Escape')close();});
  window.addEventListener('popstate',function(){var m=location.hash.match(/^#app-([a-z])$/);if(m)open(m[1],false);else shut();});
  var m=location.hash.match(/^#app-([a-z])$/);if(m)open(m[1],false);
})();
</script>
<?php admin_footer();
