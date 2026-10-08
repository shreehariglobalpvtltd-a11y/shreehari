<?php
/**
 * =====================================================================
 *  admin-consistency-test.php — the company app looks the same and reads
 *  well on every page, phone and desk, light / dark / AMOLED (8 Oct 2026,
 *  premium upgrade Phase 14, audit D9 + D8-06 on the booking page)
 *
 *  Presentation only — nothing here touches a permission, a role, a menu
 *  link or a business rule. This suite pins:
 *
 *    1. a [hidden] flash stays hidden; the Bus Calendar message box can
 *       never be removed out from under its flash() (D9-01)
 *    2. caution buttons are amber and destructive ones red, above
 *       depth.css's royal face, with AA white text (D9-02)
 *    3. trip-status colours carry white text at >= 4.5:1 and are drawn as
 *       one solid pill (D9-05); dark-mode text uses tokens (D9-04)
 *    4. phone layout rules: settings labels wrap, KPI tiles two-up, the
 *       counter bar is not sticky, the top bar scrolls away, nothing sits
 *       on the bottom bar, no desktop hamburger, Poppins in fields
 *       (D9-03/07/09/10/13/14/17), one selected-tab look (D9-06), AMOLED
 *       --orange = royal, no promo glow (D9-12)
 *    5. booking page: '⚠ UTR also on …' / '⚠ Screenshot also on …' pills
 *       (display only, never a block) (D8-06)
 *    6. over HTTP (SHG_TEST_BASE): every touched page still answers 200
 *       for a super-admin, and the reuse pills appear exactly where they
 *       should
 *
 *    php -c .claude/php-dev.ini tests/admin-consistency-test.php
 *    SHG_TEST_BASE=http://localhost:8899 php … (adds section 6)
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/tripstatus.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** WCAG 2.x contrast ratio of two #RRGGBB colours. */
function contrast(string $a, string $b): float {
    $lum = static function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $c = array_map(static fn($v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $l1 = $lum($a); $l2 = $lum($b);
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
}
/** The body of the first `@media (…)` block that contains $needle. */
function mediaBlockWith(string $css, string $mediaHead, string $needle): string {
    $off = 0;
    while (($p = strpos($css, $mediaHead, $off)) !== false) {
        $open = strpos($css, '{', $p); if ($open === false) { break; }
        $depth = 0; $i = $open;
        for ($n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') { $depth++; }
            elseif ($css[$i] === '}') { $depth--; if ($depth === 0) { break; } }
        }
        $body = substr($css, $open, $i - $open + 1);
        if (str_contains($body, $needle)) { return $body; }
        $off = $i;
    }
    return '';
}

$root  = dirname(__DIR__);
$read  = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);
$css   = $read('assets/css/admin.css');
$guard = $read('admin/_guard.php');
$cal   = $read('admin/calendar.php');
$bk    = $read('admin/bookings.php');
$set   = $read('admin/settings.php');
$bus   = $read('admin/buses.php');
$bv    = $read('admin/booking-view.php');
$sch   = $read('admin/schedule.php');
$idx   = $read('admin/index.php');
$ag    = $read('admin/agents.php');
$qt    = $read('admin/quick-ticket.php');
$wam   = $read('admin/wa-manager.php');
$cop   = $read('admin/ai-copilot.php');
$tdb   = $read('admin/trip-dashboard.php');
$stf   = $read('admin/staff.php');
$aiw   = $read('includes/aiwatch.php');
$ctr   = $read('assets/js/14-counter.js');
$depth = $read('assets/css/depth.css');

echo "\n=== Company app consistency (D9) ===\n\n";

echo "-- 0. admin.css is sound --\n";
check('admin.css braces balance', substr_count($css, '{') === substr_count($css, '}'), substr_count($css, '{') . ' blocks');
check('depth.css still loads after admin.css (never edited for this)', strpos($guard, '/assets/css/depth.css?v=') > strpos($guard, '/assets/css/admin.css?v='));
check('admin.css is CRLF like the rest of the repo', substr_count($css, "\r\n") === substr_count($css, "\n"));

echo "\n-- 1. Hidden flashes stay hidden; calendar errors always show (D9-01) --\n";
check('.flash[hidden] is display:none !important', (bool) preg_match('~\.flash\[hidden\]\s*\{\s*display:\s*none\s*!important~', $css));
check('footer x / fade script skips a [hidden] flash', str_contains($guard, 'if(f.hidden||f.querySelector(".flash-x"))return;'));
check('a flash with an id is hidden by x, never removed', str_contains($guard, 'x.onclick=function(){if(f.id){f.hidden=true}else{f.remove()}};'));
check('only an id-less success flash with text auto-fades', str_contains($guard, 'if(f.classList.contains("ok")&&!f.id&&t){setTimeout('));
check('calendar flash() re-creates a missing box', str_contains($cal, "if (!f) { f = document.createElement('div'); f.id = 'calFlash'; }"));
check('calendar flash() shows inside the open day panel', str_contains($cal, 'var inPanel = panel && !panel.hidden && body;'));
check('calendar message box starts hidden', str_contains($cal, '<div id="calFlash" class="flash ok cal-msg" hidden></div>'));
check('bulk-cancel error box starts hidden', str_contains($bk, 'id="bulkErr" class="flash bad" hidden'));

echo "\n-- 2. Caution = amber, destructive = red (D9-02) --\n";
check('amber variant out-ranks depth.css on specificity (body.shg-adm …)', str_contains($css, 'body.shg-adm .btn.warn, body.shg-adm .btn-warn {'));
check('amber variant is !important (depth.css paints .btn with !important)', (bool) preg_match('~body\.shg-adm \.btn\.warn, body\.shg-adm \.btn-warn \{\s*background: linear-gradient\(180deg, #B45309 0%, #92400E 100%\) !important; color: #FFFFFF !important~', $css));
foreach (['#B45309', '#92400E'] as $c) {
    $r = contrast('#FFFFFF', $c);
    check("white on amber $c >= 4.5:1", $r >= 4.5, sprintf('%.2f:1', $r));
}
check('depth.css still has no warn face of its own (no edit there)', !str_contains($depth, '.btn.warn') && !str_contains($depth, '.btn-warn'));
check("master service switch: class danger / ok", str_contains($bus, "<button class=\"btn <?= \$svcOn ? 'danger' : 'ok' ?>\" type=\"submit\""));
check('master service switch: no inline background', !preg_match('~background:<\?= \$svcOn~', $bus));
check('bus Delete is a danger button with no inline #C00', str_contains($bus, '<button type="submit" class="btn danger btn-sm"') && !str_contains($bus, 'background:#C00'));
check('bus Activate is green, Deactivate amber', str_contains($bus, "class=\"btn <?= \$isActive ? 'btn-warn' : 'btn-ok' ?> btn-sm\""));
check('booking Delete Forever is a danger button', (bool) preg_match('~class="btn danger btn-sm" style="width:auto"\s*\R\s*onclick="return confirm\(\'PERMANENTLY DELETE~', $bv));
check('booking page has no inline #C00 button face left', !str_contains($bv, 'background:#C00'));
check('agent application Reject (deletes it) is red', (bool) preg_match('~class="btn danger btn-sm"\s*\R\s*onclick="return confirm\(\'Reject and delete~', $stf));
check('dashboard QuickBot keeps the royal action face (not caution)', str_contains($idx, '<a class="btn" href="\' . $base . \'/admin/quick-ticket.php">'));

echo "\n-- 3. Trip status + dark-mode text (D9-05, D9-04) --\n";
$states = [TripStatus::STATE_UPCOMING, TripStatus::STATE_DEPARTING, TripStatus::STATE_BOARDING, TripStatus::STATE_DEPARTED, TripStatus::STATE_ON_ROUTE,
           TripStatus::STATE_ARRIVED, TripStatus::STATE_COMPLETED, TripStatus::STATE_DELAYED, TripStatus::STATE_SOLD_OUT, TripStatus::STATE_CANCELLED];
foreach ($states as $s) {
    $m = TripStatus::meta($s);
    $ok = isset($m['state'], $m['label'], $m['color']) && $m['state'] === $s && preg_match('~^#[0-9A-Fa-f]{6}$~', $m['color']);
    $r = $ok ? contrast('#FFFFFF', $m['color']) : 0.0;
    check("trip state '{$s}' ({$m['label']}): white on {$m['color']} >= 4.5:1", $ok && $r >= 4.5, sprintf('%.2f:1', $r));
}
check("trip-state labels unchanged ('Departing Soon', 'Sold Out')", TripStatus::meta(TripStatus::STATE_DEPARTING)['label'] === 'Departing Soon' && TripStatus::meta(TripStatus::STATE_SOLD_OUT)['label'] === 'Sold Out');
$fallback = TripStatus::meta('no-such-state');
check('unknown state falls back to an AA colour', contrast('#FFFFFF', $fallback['color']) >= 4.5, $fallback['color']);
check('Schedule Manager draws the same solid pill (white on the colour)', str_contains($sch, "style=\"background:' . \$color . ';color:#fff;border:1px solid ' . \$color . '\""));
check('…and no 10 % tint pill is left there', !str_contains($sch, "1a;color:' . \$color"));
check('dashboard live pill is still solid', str_contains($idx, 'class="state-pill" data-live-pill style="background:'));
check('Ask AI Sahayak: dark ink on violet (not #fff)', str_contains($aiw, 'background:var(--violet);color:var(--on-royal)') && !str_contains($aiw, 'background:var(--violet);color:#fff'));
check('bookings KPI Confirmed / Pending use tokens', str_contains($bk, 'style="color:var(--success)"><?= number_format($confirmedCount)') && str_contains($bk, 'style="color:var(--warning)"><?= number_format($pendingCount)'));
check('bookings rows-per-page active uses royal / on-royal', str_contains($bk, '.pp-pick a.on{background:var(--royal);color:var(--on-royal)'));
check('QuickBot NPR aid in a token (was #0863b8, 2.5:1 in dark)', str_contains($qt, '.qt-npr{display:block;font-size:12.5px;font-weight:700;color:var(--royal-strong);'));
check('QuickBot badge text pairs with royal', str_contains($qt, 'background:var(--orange);color:var(--on-royal);padding:5px 10px'));
check('agents menu Activate / Deactivate in tokens', str_contains($ag, '.ag-menu .danger{color:var(--danger)}.ag-menu .ok{color:var(--success)}'));
check('agents: no #b02a2a text left', !str_contains($ag, 'color:#b02a2a'));
$r = contrast('#FFFFFF', '#C2410C');
check('WhatsApp manager IN chip: white on #C2410C >= 4.5:1', str_contains($wam, 'color:#fff;background:#C2410C}') && $r >= 4.5, sprintf('%.2f:1', $r));
check('WhatsApp manager status words in tokens', str_contains($wam, '.wm-st.read{color:var(--royal)}.wm-st.delivered{color:var(--success)}.wm-st.failed{color:var(--danger)}'));
check('seat floor range chip darkens its ground (was white 22 %)', str_contains($guard, '.floor-head .fh-range{margin-left:auto;font-family:var(--f-mono);font-size:12px;background:rgba(0,0,0,.28);'));
foreach (['#1E5AA8' => 'light lower floor', '#15803D' => 'light upper floor', '#2F6FD0' => 'dark lower floor', '#187A43' => 'dark upper floor'] as $c => $what) {
    $r = contrast('#FFFFFF', $c);
    check("floor head ($what $c): white >= 4.5:1", str_contains($guard, '--fl:' . $c) && $r >= 4.5, sprintf('%.2f:1', $r));
}
preg_match_all("~'badge' => '(#[0-9A-Fa-f]{6})'~", $bus, $bm);
$badLow = array_values(array_filter($bm[1], static fn($c) => contrast('#FFFFFF', $c) < 4.5));
check('bus seat-model badges: white text >= 4.5:1', count($bm[1]) === 5 && $badLow === [], $badLow ? implode(',', $badLow) : count($bm[1]) . ' badges');
preg_match_all('~^\s*\.cp-(?:send|act)\{[^\n]*~m', $cop, $cpRules);
check('AI copilot Send / action keys royal (no orange-to-gold fade)', str_contains($cop, '.cp-send{flex:0 0 auto;padding:0 18px;border-radius:12px;border:0;background:var(--royal);color:var(--on-royal);')
    && count($cpRules[0]) === 2 && !str_contains(implode('', $cpRules[0]), '#FFB703'));
check('AI copilot Send says why when it is off', str_contains($cop, 'disabled title="AI Sahayak is switched off - see the note above"'));
check('trip dashboard KPI figures in tokens', str_contains($tdb, '.td-card .v.amber{color:var(--warning)}') && str_contains($tdb, '.td-card .v.orange{color:var(--warm-ink)}'));
check('staff: pending-applications heading in a token', str_contains($stf, '<h2 style="color:var(--warning)">'));
check('booking page power-panel labels readable in dark', str_contains($bv, '.power-panel label{font-size:12px;font-weight:700;color:var(--muted);'));

echo "\n-- 4. Layout rules (D9-03/06/07/09/10/12/13/14/17) --\n";
$m820 = mediaBlockWith($set, '@media (max-width: 820px)', '.set-table th');
check('settings labels wrap on a phone', str_contains($m820, '.set-table th { white-space: normal !important; text-transform: none;'));
$cards = mediaBlockWith($css, '@media (max-width: 820px)', '.shg-adm .cards');
check('KPI .cards two across on a phone', str_contains($cards, '.shg-adm .cards { grid-template-columns: repeat(2, minmax(0, 1fr)) !important;'));
check('…the seat map strip keeps its own three-up rule', str_contains($css, '.cards:has(#count-open) { grid-template-columns: repeat(3, minmax(0, 1fr)) !important;'));
check('desktop hides the hamburger', str_contains($css, '@media (min-width: 821px) { .tb .menu { display: none !important; } }'));
check('form fields inherit the panel font (zero specificity)', str_contains($css, ':where(.shg-adm) :where(input, select, textarea, button) { font-family: inherit; }'));
$chrome = mediaBlockWith($css, '@media (max-width: 820px)', '--mnav-h: calc(');
check('bottom-bar height token includes the home-indicator area', str_contains($chrome, ':root { --mnav-h: calc(76px + env(safe-area-inset-bottom, 0px)); }'));
check('phone top bar scrolls away (the admin .tb, never the customer .nav)', str_contains($chrome, 'body.shg-adm .tb { position: relative; }') && !preg_match('~(^|[\s,}])\.nav\s*\{[^}]*position~', $css));
check('phone drawer opens from the very top', str_contains($chrome, 'body.shg-adm .side { top: 0;'));
check('bulk bar + backup sheet sit above the bottom bar', str_contains($chrome, 'body.has-mnav .bulk-bar, body.has-mnav .bkp { bottom: calc(var(--mnav-h) + 8px); }'));
check('save bar rests on the bottom bar (no fixed 64px)', str_contains($css, 'body.has-mnav .save-sticky, body.has-mnav form .form-actions, body.has-mnav form .actions-row { bottom: var(--mnav-h, 76px);') && !str_contains($css, 'actions-row { bottom: 64px; }'));
check('one selected-tab look (payments tab visible in dark)', (bool) preg_match('~\.shg-adm :is\(\.pay-tabs a\.active, \.hd-filters a\.on, \.hd-cats a\.on, \.cd-chips a\.on, \.pp-pick a\.on\) \{\s*background: var\(--dp-on-face, var\(--royal\)\) !important; color: var\(--dp-on-ink, var\(--on-royal\)\) !important;~', $css));
check('payment tabs 3 x 2 on a phone', str_contains($css, '.shg-adm .pay-tabs { display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)); }'));
check('AMOLED: the --orange alias stays royal (0,2,1 beats depth.css)', str_contains($css, 'html:root[data-display="amoled"] { --orange: var(--royal);'));
check('no old-brand glow behind the dashboard promo', str_contains($css, '.promo::before { display: none !important; }'));
$np = mediaBlockWith($css, '@media(max-width:560px)', '.np-strip__p');
check('Nepal office strip: one shape per contact on a phone', str_contains($np, '.np-strip__p{display:grid;grid-template-columns:minmax(0,1fr) auto;') && str_contains($np, '.np-strip__who{grid-column:1/-1}'));
check('counter bar is not sticky on a phone', str_contains($ctr, "@media (max-width:600px){#counterBar{position:relative;"));
check('counter bar links swipe in one row on a phone', str_contains($ctr, '#counterBar .cb-links{flex-wrap:nowrap;overflow-x:auto;width:100%;'));
check('counter mode never sets position on the customer .nav', !preg_match('~[\'"\s}]\.nav\s*\{[^}]*position~', $ctr));
check('calendar: dark map for chips + legend in tokens', str_contains($cal, ':root[data-theme="dark"] .chip.dir2,:root[data-theme="dark"] .cal-legend .lg-ret{--c-bg:var(--success-soft);--c-fg:var(--success)}'));
check('calendar: legend swatches are classes, not hard-coded pastels', !str_contains($cal, '<i style="background:#') && str_contains($cal, '<i class="lg-out"></i>'));
check('calendar: phone chips show the count only', str_contains($cal, '.chip b,.chip .t{display:none}') && str_contains($cal, '<span class="t">'));

echo "\n-- 5. Booking page: same UTR / screenshot on another booking (D8-06) --\n";
check('UTR reuse query (indexed equality, other bookings only)', str_contains($bv, 'WHERE p2.utr_number = :u AND p2.booking_id <> :b'));
check('screenshot reuse query on sha256', str_contains($bv, 'JOIN payment_screenshots s2 ON s2.sha256 = s1.sha256 AND s2.booking_id <> s1.booking_id'));
check('shown to the payments.view audience only', (bool) preg_match('~if \(Auth::can\(\'payments\.view\'\) && !empty\(\$b\[\'id\'\]\)\) \{\s*\R\s*\$utrHere~', $bv));
check('pills under the UTR and the proof', str_contains($bv, "<?= \$alsoOnPill('UTR', \$utrAlsoOn) ?>") && str_contains($bv, "<?= \$alsoOnPill('Screenshot', \$shotAlsoOn) ?>"));
check('display only: no verify / reject decision tied to it', !preg_match('~utrAlsoOn[^;]*(disabled|reject|verify)~i', $bv) && !preg_match('~shotAlsoOn[^;]*(disabled|reject|verify)~i', $bv));

echo "\n-- 6. Over HTTP --\n";
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
if ($base === '' || !function_exists('curl_init')) {
    echo "  (skipped: " . ($base === '' ? 'SHG_TEST_BASE not set' : 'curl not loaded') . ")\n";
} else {
    $jar = sys_get_temp_dir() . '/shg_admcons_' . getmypid() . '.cookies'; @unlink($jar);
    $req = static function (string $method, string $url, array $form = []) use ($base, $jar): array {
        $ch = curl_init($base . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 90]);
        if ($form !== []) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
        $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['code' => $code, 'body' => $body];
    };
    $user = 'adm-cons-qa'; $pw = 'AdmCons@' . bin2hex(random_bytes(4));
    $adminId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
    if ($adminId === 0) {
        $adminId = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
            'full_name' => 'Consistency QA', 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0]);
    } else {
        Database::update('admins', ['password_hash' => password_hash($pw, PASSWORD_BCRYPT), 'role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $adminId]);
    }
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
    $saved = []; $shotIds = [];
    try {
        $lp = $req('GET', '/admin/login.php');
        preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
        $li = $req('POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
        check('a super-admin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);

        $pages = ['index.php', 'calendar.php', 'bookings.php', 'settings.php', 'buses.php', 'schedule.php', 'agents.php', 'quick-ticket.php',
                  'wa-manager.php', 'ai-copilot.php', 'trip-dashboard.php', 'staff.php', 'payroll.php', 'analytics.php', 'payments.php', 'trips.php'];
        foreach ($pages as $p) {
            $r = $req('GET', '/admin/' . $p);
            check("/admin/$p answers 200", $r['code'] === 200 && str_contains($r['body'], '</html>'), 'HTTP ' . $r['code']);
        }
        $cal = $req('GET', '/admin/calendar.php');
        check('Bus Calendar ships its message box hidden', str_contains($cal['body'], '<div id="calFlash" class="flash ok cal-msg" hidden></div>'));
        $bp = $req('GET', '/admin/buses.php');
        check('Bus Fleet master switch renders as a danger / ok button', (bool) preg_match('~<button class="btn (danger|ok)" type="submit" style="min-height:44px;font-weight:800">~', $bp['body']));
        check('admin.css is linked on every page', str_contains($bp['body'], '/assets/css/admin.css?v='));

        /* D8-06: the latest payment of three bookings, none with a UTR yet. */
        $rows = Database::fetchAll(
            "SELECT p.id, p.booking_id, p.utr_number, b.pnr FROM payments p JOIN bookings b ON b.id = p.booking_id
              WHERE p.id = (SELECT MAX(p3.id) FROM payments p3 WHERE p3.booking_id = p.booking_id)
                AND (p.utr_number IS NULL OR p.utr_number = '')
              ORDER BY p.id DESC LIMIT 3"
        );
        if (count($rows) < 3) {
            echo "  (D8-06 HTTP part skipped: fewer than 3 bookings with a payment and no UTR)\n";
        } else {
            $tag = 'QAREUSE' . strtoupper(bin2hex(random_bytes(4)));
            foreach ($rows as $i => $r) {
                $saved[(int) $r['id']] = $r['utr_number'];
                Database::update('payments', ['utr_number' => $i < 2 ? $tag : $tag . 'U'], 'id = :i', ['i' => (int) $r['id']]);
            }
            $hash = hash('sha256', $tag);
            foreach ([0, 1] as $i) {
                $shotIds[] = (int) Database::insert('payment_screenshots', ['payment_id' => (int) $rows[$i]['id'], 'booking_id' => (int) $rows[$i]['booking_id'],
                    'file_path' => 'qa/admin-consistency-' . $i . '.png', 'mime_type' => 'image/png', 'file_size' => 1, 'sha256' => $hash]);
            }
            $p0 = $req('GET', '/admin/booking-view.php?pnr=' . urlencode((string) $rows[0]['pnr']));
            $p1 = $req('GET', '/admin/booking-view.php?pnr=' . urlencode((string) $rows[1]['pnr']));
            $p2 = $req('GET', '/admin/booking-view.php?pnr=' . urlencode((string) $rows[2]['pnr']));
            check('booking A shows "UTR also on" booking B', $p0['code'] === 200 && str_contains($p0['body'], 'UTR also on <a href="/admin/booking-view.php?pnr=' . rawurlencode((string) $rows[1]['pnr'])));
            check('booking B shows "UTR also on" booking A', str_contains($p1['body'], 'UTR also on <a href="/admin/booking-view.php?pnr=' . rawurlencode((string) $rows[0]['pnr'])));
            check('both show "Screenshot also on" the other', str_contains($p0['body'], 'Screenshot also on <a') && str_contains($p1['body'], 'Screenshot also on <a'));
            check('a unique UTR shows no pill', $p2['code'] === 200 && !str_contains($p2['body'], 'also on <a href'));
        }
    } catch (Throwable $e) {
        check('unexpected exception: ' . $e->getMessage(), false);
    } finally {
        foreach ($saved as $pid => $old) {
            try { Database::update('payments', ['utr_number' => $old], 'id = :i', ['i' => $pid]); } catch (Throwable $e) {}
        }
        foreach ($shotIds as $sid) {
            try { Database::delete('payment_screenshots', 'id = :i', ['i' => $sid]); } catch (Throwable $e) {}
        }
        try { Database::delete('admin_login_events', 'username = :u', ['u' => $user]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :i', ['i' => $adminId]); } catch (Throwable $e) {}
        @unlink($jar);
    }
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
