<?php
/**
 * =====================================================================
 *  role-gates-test.php — "one login door" + role gates, over real HTTP.
 *
 *  Module 1 of the 3 Sep 2026 upgrade:
 *    - /admin/login.php with no ?portal shows the 3-way chooser
 *      (Customer / Agent / Admin) and still accepts username + password;
 *    - ?portal=agent (and a deep link next=agent.php) opens the agent
 *      code + OTP form, no chooser;
 *    - a counter AGENT can no longer rewrite the fleet rotation, rename a
 *      bus, reassign a driver, set a delay or swap the bus under a trip
 *      (those now need schedules.manage) — but can still mark milestones;
 *    - a MANAGER can do all of the above, and can block a customer.
 *
 *  REQUIREMENTS — same as e2e-booking-test.php:
 *    1. dev server on :8899   (Claude Code: preview_start "shari-php")
 *    2. php -c .claude/php-dev.ini -d extension=php_curl.dll tests/role-gates-test.php
 *
 *  Provisions three throwaway staff accounts (rg-agent / rg-manager /
 *  rg-super) against the LOCAL database and deletes them at the end.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

// SHG_BASE overrides the dev-server origin (a git worktree served on another port).
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: getenv('SHG_BASE') ?: 'http://localhost:8899'), '/'));

function req(string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $opt['jar'],
        CURLOPT_COOKIEFILE     => $opt['jar'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw  = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    $loc  = preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    return ['code' => $code, 'body' => substr($raw, $hsz), 'location' => $loc];
}

function pdo(): PDO {
    static $p = null;
    if ($p === null) {
        $p = new PDO('mysql:host=127.0.0.1;port=3307;dbname=shari_test;charset=utf8mb4', 'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label" . ($detail ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail ? " — $detail" : '') . "\n"; }
}
function csrfOf(string $html): string {
    return preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

const RG_PW = 'RoleGate@12345';
$ACCOUNTS = ['rg-agent' => 'agent', 'rg-manager' => 'manager', 'rg-super' => 'superadmin'];

function ensureStaff(string $username, string $role): void {
    $hash = password_hash(RG_PW, PASSWORD_BCRYPT);
    $q = pdo()->prepare('SELECT id FROM admins WHERE username = ?');
    $q->execute([$username]);
    if ($id = $q->fetchColumn()) {
        pdo()->prepare('UPDATE admins SET password_hash=?, role=?, is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=?')
            ->execute([$hash, $role, $id]);
    } else {
        pdo()->prepare('INSERT INTO admins (username, password_hash, full_name, phone, role, is_active, must_change_pw) VALUES (?,?,?,?,?,1,0)')
            ->execute([$username, $hash, 'RoleGate ' . ucfirst($role), null, $role]);
    }
}
function jar(string $name): string {
    $p = sys_get_temp_dir() . '/shg_rg_' . $name . '_' . getmypid() . '.cookies';
    @unlink($p);
    return $p;
}
function login(string $user, string $jar): bool {
    $lp = req('GET', '/admin/login.php', ['jar' => $jar]);
    $li = req('POST', '/admin/login.php', ['jar' => $jar, 'form' => [
        'shg_csrf' => csrfOf($lp['body']), 'username' => $user, 'password' => RG_PW,
    ]]);
    return $li['code'] === 302;
}

try { pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
// The public Quick Ticket buckets are per IP; repeated local runs from
// 127.0.0.1 would otherwise hit the sale limit and fail with 429.
try { pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'quick_ticket%' OR bucket LIKE 'login_quick%' OR bucket LIKE 'cancel%'"); } catch (Throwable $e) {}
foreach ($ACCOUNTS as $u => $r) { ensureStaff($u, $r); }

/* ---------------------------------------------------------------- */
echo "\n=== 1. One login door — role chooser ===\n";
$guest = jar('guest');
$door = req('GET', '/admin/login.php', ['jar' => $guest]);
check('bare /admin/login.php renders', $door['code'] === 200, 'HTTP ' . $door['code']);
check('chooser shown when no portal is chosen', str_contains($door['body'], 'class="choose"'));
check('chooser links to the agent portal', str_contains($door['body'], '?portal=agent'));
check('chooser links to the admin portal', str_contains($door['body'], '?portal=admin'));
check('chooser links the customer back to the website', preg_match('~class="ch ch-customer" href="[^"]*/#/my"~', $door['body']) === 1);
check('username + password form still present under the chooser', str_contains($door['body'], 'name="username"'));
check('the dead /?portal=switch link is gone', !str_contains($door['body'], '?portal=switch'));

/* The agent door changed on 2026-09-04: the DEFAULT is now email +
   username + password (Auth::agentPasswordLogin), and the older
   agent-code + mobile-OTP door moved behind ?auth=otp. These two checks
   still asserted name="agent_code" on the default portal, so they had
   been failing since that change — unnoticed, because role-gates-test is
   not in the documented eight-suite battery and e2e-booking-test (which
   is) exited 0 no matter what. Updated to the current intent, with the
   OTP door still covered rather than dropped. */
$ag = req('GET', '/admin/login.php?portal=agent', ['jar' => $guest]);
check('?portal=agent shows the password form (email+username+password)',
    str_contains($ag['body'], 'name="email"')
    && str_contains($ag['body'], 'name="username"')
    && str_contains($ag['body'], 'name="password"'));
check('?portal=agent hides the chooser (existing URL unchanged)', !str_contains($ag['body'], 'class="choose"'));

$agOtp = req('GET', '/admin/login.php?portal=agent&auth=otp', ['jar' => $guest]);
check('?auth=otp still offers the legacy agent-code + mobile OTP door',
    str_contains($agOtp['body'], 'name="agent_code"')
    && str_contains($agOtp['body'], 'name="mobile"'));

$deep = req('GET', '/admin/login.php?next=agent.php', ['jar' => $guest]);
check('next=agent.php infers the agent portal (password form)',
    str_contains($deep['body'], 'name="username"')
    && str_contains($deep['body'], 'name="password"'));
check('inferred agent portal keeps the deep link', str_contains($deep['body'], 'name="next" value="agent.php"'));

$adm = req('GET', '/admin/login.php?portal=admin', ['jar' => $guest]);
check('?portal=admin shows username + password, no chooser',
    str_contains($adm['body'], 'name="username"') && !str_contains($adm['body'], 'class="choose"'));

$bounce = req('GET', '/admin/agent.php', ['jar' => $guest]);
check('signed-out /admin/agent.php redirects to the login door with next=', $bounce['code'] === 302 && str_contains($bounce['location'], 'login.php?next=agent.php'),
    'HTTP ' . $bounce['code'] . ' → ' . $bounce['location']);

/* ---------------------------------------------------------------- */
echo "\n=== 2. Role-based redirect ===\n";
$agentJar = jar('agent');
check('agent signs in with username + password (fallback path)', login('rg-agent', $agentJar));
$idx = req('GET', '/admin/index.php', ['jar' => $agentJar]);
check('agent is bounced off the finance dashboard to agent.php', $idx['code'] === 302 && str_contains($idx['location'], 'agent.php'),
    'HTTP ' . $idx['code'] . ' → ' . $idx['location']);
$agHome = req('GET', '/admin/agent.php', ['jar' => $agentJar]);
check('agent dashboard opens', $agHome['code'] === 200, 'HTTP ' . $agHome['code']);

$mgrJar = jar('manager');
check('manager signs in', login('rg-manager', $mgrJar));
$mIdx = req('GET', '/admin/index.php', ['jar' => $mgrJar]);
check('manager lands on the dashboard', $mIdx['code'] === 200, 'HTTP ' . $mIdx['code']);

/* ---------------------------------------------------------------- */
echo "\n=== 3. Agent cannot manage the fleet (schedules.manage) ===\n";
$tb = req('GET', '/admin/trips.php', ['jar' => $agentJar]);
check('agent can open the Trips Board (schedules.view)', $tb['code'] === 200, 'HTTP ' . $tb['code']);
check('rotation / bus-details panel is hidden from the agent', !str_contains($tb['body'], 'name="action" value="rotation"'));
check('driver-assign form is hidden from the agent', !str_contains($tb['body'], 'name="action" value="assign_driver"'));
check('delay form is hidden from the agent', !str_contains($tb['body'], 'name="action" value="set_delay"'));
$csrfA = csrfOf($tb['body']);
check('agent page carries a CSRF token to test the POST gates', $csrfA !== '');

foreach (['rotation', 'bus', 'assign_driver', 'set_delay'] as $act) {
    $r = req('POST', '/admin/trips.php', ['jar' => $agentJar, 'form' => [
        'shg_csrf' => $csrfA, 'action' => $act, 'schedule_id' => 0, 'bus_id' => 0, 'delay_minutes' => 30,
    ]]);
    check("agent POST trips.php action=$act is refused (403)", $r['code'] === 403, 'HTTP ' . $r['code']);
}
$ms = req('POST', '/admin/trips.php', ['jar' => $agentJar, 'form' => [
    'shg_csrf' => $csrfA, 'action' => 'milestone', 'schedule_id' => 0, 'event' => 'departed',
]]);
check('agent may still press a journey milestone (not 403; bad id → flash)', $ms['code'] === 200, 'HTTP ' . $ms['code']);

$td = req('GET', '/admin/trip-dashboard.php', ['jar' => $agentJar]);
check('agent can open the Date View', $td['code'] === 200, 'HTTP ' . $td['code']);
check('bus-swap dropdown is hidden from the agent', !str_contains($td['body'], 'name="action" value="assign_bus"'));
$sw = req('POST', '/admin/trip-dashboard.php', ['jar' => $agentJar, 'form' => [
    // The agent's Date View now renders no form at all, so borrow the CSRF
    // token from the same session's Trips Board page (token is per session).
    'shg_csrf' => $csrfA, 'action' => 'assign_bus', 'schedule_id' => 0, 'bus_id' => 0,
]]);
check('agent POST assign_bus is refused (403)', $sw['code'] === 403, 'HTTP ' . $sw['code']);

/* ---------------------------------------------------------------- */
echo "\n=== 4. Manager keeps fleet management + can block a customer ===\n";
$mtb = req('GET', '/admin/trips.php', ['jar' => $mgrJar]);
check('manager sees the rotation panel', str_contains($mtb['body'], 'name="action" value="rotation"'));
$csrfM = csrfOf($mtb['body']);
$md = req('POST', '/admin/trips.php', ['jar' => $mgrJar, 'form' => [
    'shg_csrf' => $csrfM, 'action' => 'set_delay', 'schedule_id' => 0, 'delay_minutes' => 0,
]]);
check('manager POST set_delay passes the gate (200, bad id → flash)', $md['code'] === 200, 'HTTP ' . $md['code']);
$mtd = req('GET', '/admin/trip-dashboard.php', ['jar' => $mgrJar]);
check('manager sees the bus-swap control', str_contains($mtd['body'], 'name="action" value="assign_bus"') || str_contains($mtd['body'], 'name="bus_id"'));

$cu = req('GET', '/admin/customers.php', ['jar' => $mgrJar]);
check('manager opens Customers', $cu['code'] === 200, 'HTTP ' . $cu['code']);
$blk = req('POST', '/admin/customers.php', ['jar' => $mgrJar, 'form' => [
    'shg_csrf' => csrfOf($cu['body']), 'action' => 'block', 'uid' => 0, 'reason' => 'role-gate test',
]]);
check('manager block attempt is no longer refused as super-admin-only',
    $blk['code'] === 200 && !str_contains($blk['body'], 'super-admin can block'), 'HTTP ' . $blk['code']);
check('… it reaches the customer lookup (uid 0 → "Customer not found")', str_contains($blk['body'], 'Customer not found'));

/* ---------------------------------------------------------------- */
echo "\n=== 5. Customer app exposes the staff door ===\n";
$home = req('GET', '/', ['jar' => $guest]);
check('customer home renders', $home['code'] === 200, 'HTTP ' . $home['code']);
check('footer / menu link to /admin/login.php present', substr_count($home['body'], 'href="/admin/login.php"') >= 2,
    'links=' . substr_count($home['body'], 'href="/admin/login.php"'));
check('no splash agent/admin pills came back', !str_contains($home['body'], 'data-portal="agent"'));

/* ---------------------------------------------------------------- */
echo "\n=== 7. Quick Ticket desk (6 Sep 2026) ===\n";
$qtGuest = req('GET', '/admin/quick-ticket.php', ['jar' => $guest]);
check('signed-out /admin/quick-ticket.php bounces to the login door',
    $qtGuest['code'] === 302 && str_contains($qtGuest['location'], 'login.php'), 'HTTP ' . $qtGuest['code']);
$qtAgent = req('GET', '/admin/quick-ticket.php', ['jar' => $agentJar]);
check('an agent opens the QuickBot Ticket desk', $qtAgent['code'] === 200 && str_contains($qtAgent['body'], 'QuickBot') && str_contains($qtAgent['body'], 'id="qtLine"'), 'HTTP ' . $qtAgent['code']);
$qtApiGuest = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'plan']]);
check('signed-out /api/quick-ticket.php is refused', in_array($qtApiGuest['code'], [401, 403, 419], true), 'HTTP ' . $qtApiGuest['code']);
$qtCsrf = csrfOf($qtAgent['body']);
$qtPlan = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'plan'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
$qtJson = json_decode($qtPlan['body'], true);
check('an agent gets a plan (next bus · pickup · seat · fare)',
    $qtPlan['code'] === 200 && is_array($qtJson) && ($qtJson['ok'] ?? false) === true && !empty($qtJson['data']['seats']),
    'HTTP ' . $qtPlan['code'] . ' ' . substr($qtPlan['body'], 0, 120));

/* Quick Ticket for everyone + the AI Ticket Bot (6 Sep 2026): the customer
   door is public (CSRF from the app page), the bot is staff-only, and a
   staff session may NOT use the customer door (its sales must be attributed). */
$appHome = req('GET', '/', ['jar' => $guest]);
$appCsrf = preg_match('/"csrf":"([a-f0-9]+)"/', $appHome['body'], $mm) ? $mm[1] : '';
check('the app page hands a guest a CSRF token', $appCsrf !== '', 'HTTP ' . $appHome['code']);
$cPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$cJson = json_decode($cPlan['body'], true);
check('a signed-out passenger gets a customer plan (public caps)',
    $cPlan['code'] === 200 && is_array($cJson) && ($cJson['ok'] ?? false) === true && !empty($cJson['data']['seats']),
    'HTTP ' . $cPlan['code'] . ' ' . substr($cPlan['body'], 0, 120));
$cBot = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'bot', 'text' => 'Ram 9876543210'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
check('the AI Ticket Bot is staff-only (guest refused)', in_array($cBot['code'], [401, 403], true), 'HTTP ' . $cBot['code']);
$aBot = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'bot', 'text' => 'Ram Bahadur 9876543210 2 seats'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
$aJson = json_decode($aBot['body'], true);
check('an agent gets a bot proposal (prefill from the typed line + live plan)',
    $aBot['code'] === 200 && is_array($aJson) && ($aJson['ok'] ?? false) === true
    && (int) ($aJson['data']['prefill']['seats'] ?? 0) === 2 && ($aJson['data']['prefill']['name'] ?? '') === 'Ram Bahadur',
    'HTTP ' . $aBot['code'] . ' ' . substr($aBot['body'], 0, 160));
$aCust = req('POST', '/api/quick-ticket.php', ['jar' => $agentJar, 'form' => ['action' => 'customer_plan'], 'headers' => ['X-CSRF-Token: ' . $qtCsrf]]);
check('staff cannot use the customer door (sales must be attributed)', $aCust['code'] === 403, 'HTTP ' . $aCust['code']);

/* A real passenger sale over HTTP: the sale itself signs the passenger in
   (no second request, no race with the session regeneration), so My
   Bookings and the keyless ticket image are authorised straight away. */
$rgPhone = '9100006001';
try { pdo()->prepare("DELETE FROM users WHERE phone = ?")->execute([$rgPhone]); } catch (Throwable $e) {}
/* One click (6 Sep 2026): the sale is PINNED to the card — a card that showed
   another fare / day / pickup is refused with plan_changed + the fresh plan,
   never quietly sold. Checked before this passenger holds any ticket. */
$cj = $cJson['data'] ?? [];
$stale = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_sell', 'name' => 'Role Gate Pax', 'phone' => $rgPhone, 'date' => $cj['date'] ?? '', 'direction' => $cj['direction'] ?? '', 'seats' => 1,
    'expect' => ['date' => $cj['date'] ?? '', 'direction' => $cj['direction'] ?? '', 'boardingCode' => $cj['boardingCode'] ?? '', 'seats' => 1, 'total' => (float) ($cj['fare']['total'] ?? 0) + 999]], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$sJ = json_decode($stale['body'], true);
check('a stale card (other fare shown) is refused with plan_changed + the fresh plan', $stale['code'] === 409 && ($sJ['fields']['code'] ?? '') === 'plan_changed' && !empty($sJ['fields']['plan']['seats']), 'HTTP ' . $stale['code'] . ' ' . substr($stale['body'], 0, 120));
/* QuickBot (7 Sep 2026): the natural line is read by the same plan call. */
$lPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan', 'seats' => 0, 'text' => 'Ram Gate ' . $rgPhone . ', 2 seats, tomorrow shg-27'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$lJ = json_decode($lPlan['body'], true);
check('a passenger\'s natural line is read: name, mobile, 2 seats, tomorrow, agent code',
    $lPlan['code'] === 200 && ($lJ['data']['parsed']['name'] ?? '') === 'Ram Gate' && ($lJ['data']['parsed']['phone'] ?? '') === $rgPhone
    && (int) ($lJ['data']['seatCount'] ?? 0) === 2 && ($lJ['data']['prefill']['agentCode'] ?? '') === 'SHG-0027' && ($lJ['data']['date'] ?? '') === date('Y-m-d', strtotime('+1 day')),
    'HTTP ' . $lPlan['code'] . ' ' . json_encode($lJ['data']['parsed'] ?? null));
$cSell = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_sell', 'name' => 'Role Gate Pax', 'phone' => $rgPhone, 'agentCode' => 'SHG-9999'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$sJson = json_decode($cSell['body'], true);
$rgPnr = (string) ($sJson['data']['pnr'] ?? '');
check('a signed-out passenger sells themselves a Quick Ticket', $cSell['code'] === 200 && ($sJson['ok'] ?? false) === true && $rgPnr !== '', 'HTTP ' . $cSell['code'] . ' ' . substr($cSell['body'], 0, 140));
check('  an unknown agent code never blocks it (direct sale, notice says so)', ($sJson['data']['agent']['applied'] ?? true) === false, json_encode($sJson['data']['agent'] ?? null));
check('  the response carries the app payload + the signed-in user', !empty($sJson['data']['customer']['pnr']) && ($sJson['data']['user']['phone'] ?? '') === $rgPhone, json_encode($sJson['data']['user'] ?? null));
$myB = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('  My Bookings works at once on the same session', $myB['code'] === 200 && str_contains($myB['body'], $rgPnr), 'HTTP ' . $myB['code']);
$img = req('GET', '/download-ticket.php?pnr=' . rawurlencode($rgPnr) . '&img=1&view=1', ['jar' => $guest]);
check('  the keyless ticket image is served to its owner (no 302)', $img['code'] === 200 && str_starts_with($img['body'], "\x89PNG"), 'HTTP ' . $img['code'] . ' ' . $img['location']);
$pdf = req('GET', '/download-ticket.php?pnr=' . rawurlencode($rgPnr), ['jar' => $guest]);
check('  …and so is the PDF', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code']);
/* One-click defaults: the next plan on the SAME (now signed-in) session is
   personalised from this number's own verified ticket; a fresh guest sees
   nothing of it. */
$pPlan = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_plan', 'seats' => 0], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$pJson = json_decode($pPlan['body'], true);
check('  the next customer plan is personalised from the passenger\'s own history',
    $pPlan['code'] === 200 && (int) ($pJson['data']['personal']['trips'] ?? 0) === 1 && ($pJson['data']['personal']['name'] ?? '') === 'Role Gate Pax',
    'HTTP ' . $pPlan['code'] . ' ' . json_encode($pJson['data']['personal'] ?? null));
$other = sys_get_temp_dir() . '/rg-other.jar'; @unlink($other);
$oHome = req('GET', '/', ['jar' => $other]);
$oCsrf = preg_match('/"csrf":"([a-f0-9]+)"/', $oHome['body'], $mm2) ? $mm2[1] : '';
$oPlan = req('POST', '/api/quick-ticket.php', ['jar' => $other, 'form' => ['action' => 'customer_plan', 'phone' => $rgPhone], 'headers' => ['X-CSRF-Token: ' . $oCsrf]]);
$oJson = json_decode($oPlan['body'], true);
check('  a different visitor typing that number learns NOTHING of its history', $oPlan['code'] === 200 && empty($oJson['data']['personal']), json_encode($oJson['data']['personal'] ?? null));
@unlink($other);
/* One click (6 Sep 2026): a mistaken tap is undone free; "Not you?" ends the
   server session. (The pinned-sale check runs above, before the passenger
   holds a ticket, or the "already booked" guard answers first.) */
$undo = req('POST', '/api/quick-ticket.php', ['jar' => $guest, 'form' => ['action' => 'customer_undo', 'pnr' => $rgPnr], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$uJ = json_decode($undo['body'], true);
$uStatus = (string) pdo()->query('SELECT status FROM bookings WHERE pnr = ' . pdo()->quote($rgPnr))->fetchColumn();
check('the owner undoes the one-click ticket free (cancelled, nothing owed)', $undo['code'] === 200 && ($uJ['ok'] ?? false) === true && $uStatus === 'cancelled' && (float) ($uJ['data']['refundAmount'] ?? 1) === 0.0, 'HTTP ' . $undo['code'] . ' status ' . $uStatus);
$out = req('POST', '/api/otp.php', ['jar' => $guest, 'form' => ['action' => 'logout'], 'headers' => ['X-CSRF-Token: ' . $appCsrf]]);
$myAfter = req('GET', '/api/my-bookings.php?tab=all', ['jar' => $guest]);
check('"Not you?" signs the server session out', $out['code'] === 200 && $myAfter['code'] === 401, 'HTTP ' . $out['code'] . ' then my-bookings ' . $myAfter['code']);
if ($rgPnr !== '') {
    try {
        $bid = (int) pdo()->query("SELECT id FROM bookings WHERE pnr = " . pdo()->quote($rgPnr))->fetchColumn();
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
            try { pdo()->prepare("DELETE FROM $t WHERE booking_id = ?")->execute([$bid]); } catch (Throwable $e) {}
        }
        pdo()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$bid]);
        pdo()->prepare('DELETE FROM users WHERE phone = ?')->execute([$rgPhone]);
        foreach (glob(dirname(__DIR__) . '/tickets/ticket_' . $rgPnr . '.*') ?: [] as $f) { @unlink($f); }
    } catch (Throwable $e) {}
}

/* ---------------------------------------------------------------- */
foreach (array_keys($ACCOUNTS) as $u) {
    try { pdo()->prepare('DELETE FROM admins WHERE username = ?')->execute([$u]); } catch (Throwable $e) {}
}
foreach ([$guest, $agentJar, $mgrJar] as $j) { @unlink($j); }

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
