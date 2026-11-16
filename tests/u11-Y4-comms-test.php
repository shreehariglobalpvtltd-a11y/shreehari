<?php
/**
 * 16 Nov 2026 — Master v2.0 unit Y4 (comms):
 *
 *   P6-9a  the comms strip has "💬 FB कमेन्ट · Comments" (WaInbox::channelTabs)
 *          and admin/fb-comments.php shows it under its page head
 *   P6-9d  CustomerDirectory's exact 'phone' filter; the WhatsApp inbox chat
 *          header "🎁 अफर · Offer" link only with wa_inbox_promo_link_on = 1 and
 *          marketing.send; admin/campaigns.php?new=1&aud=phone%3D<n> previews
 *          exactly that one number (nothing is saved or sent)
 *   P7-9c  AI Centre: "Could not answer" counts new + needs_owner; the read-only
 *          "Last 7 days" block (WaInbox::quality) - 👎 by channel / branch, open
 *          questions, practice %; a missing table gives null ('—'), never a 500
 *   P8-14b the "🤖 AI" link in admin_footer (not with the settings bubble, not on
 *          ai-copilot.php / print), and the bubble's own "AI Sahayak" footer link;
 *          review fix: the link hides while ANY page bottom bar is up
 *          (admin_ai_link_bars(): payments.php .pq-bulk, agent-ranking .cmp-bar,
 *          ...), a scan fails on a new bottom bar it does not know, and a
 *          headless Edge / Chrome at 360 px checks every point of "✓ Approve
 *          picked" / "⚖️ Compare" taps the button (SKIP when no browser)
 *
 * Seeds two far-apart bookings on test numbers, one 👎 + its ai_turns row, one
 * open question and three staff accounts; removes all of them and puts the
 * switch back exactly as found.
 *
 *   SHG_TEST_BASE=http://localhost:8984 php -c <ini> -d extension=php_curl.dll tests/u11-Y4-comms-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/admin/_guard.php';      // admin_footer(), admin_ai_link_ok(), and the app
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/customerdirectory.php';
require_once INCLUDE_PATH . '/quickunlock.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8984'), '/'));
const PH       = '9867100981';
const PH_NEAR  = '9867100982';
const PNR_A    = 'U11Y4-OFFER-0001';
const PNR_B    = 'U11Y4-OFFER-0002';
const BOSS     = 'u11_y4_boss';
const ACCT     = 'u11_y4_acct';
const PW       = 'U11Y4Comms!77';
const MSG_ID   = 990011401;
const OWNERKEY = 'u11y4u11y4u11y4u11y4u11y4u11y4u11y4u11y4u11y4u11y4u11y4u11y4zzzz';
const QMARK    = 'zzu11y4marker how far is the moon bus';
const PROMO_KEY   = 'wa_inbox_promo_link_on';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JARS = [];
function jar(string $who): string {
    global $JARS;
    return $JARS[$who] ??= sys_get_temp_dir() . '/shg_u11y4_' . $who . '_' . getmypid() . '.txt';
}
function http(string $who, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => jar($who), CURLOPT_COOKIEFILE => jar($who), CURLOPT_TIMEOUT => 60]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function where(array $r): string {
    return 'HTTP ' . $r['code'] . (preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) ? ' -> ' . $m[1] : '');
}
function login(string $who, string $user): array {
    $page = http($who, '/admin/login.php?classic=1&other=1');
    return http($who, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($page['body']), 'classic' => '1', 'username' => $user, 'password' => PW]]);
}
/** Be someone in THIS process (CLI checks of Auth-gated helpers). */
function actAs(?int $id, string $role = 'superadmin', array $perms = []): void {
    if ($id === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => 'u11', 'role' => $role, 'permissions' => $perms, 'last_seen' => time(), 'logged_in_at' => time()];
}
function cleanup(): void {
    foreach ([PNR_A, PNR_B] as $pnr) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr]) as $r) {
            Database::delete('booking_passengers', 'booking_id = :b', ['b' => (int) $r['id']]);
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    try { Database::delete('ai_feedback_log', 'owner_key = :k', ['k' => OWNERKEY]); } catch (Throwable $e) {}
    try { Database::delete('ai_turns', 'owner_key = :k', ['k' => OWNERKEY]); } catch (Throwable $e) {}
    try { Database::delete('ai_unanswered_questions', 'normalized_question = :q', ['q' => QMARK]); } catch (Throwable $e) {}
    foreach ([BOSS, ACCT] as $u) {
        foreach (Database::fetchAll('SELECT id FROM admins WHERE username = :u', ['u' => $u]) as $a) {
            Database::delete('admins', 'id = :i', ['i' => (int) $a['id']]);   // the PIN lives on the admins row
        }
    }
}

/**
 * Every CSS rule on a staff page (admin/*.php, includes/*.php) that pins an
 * element (fixed / sticky) within 58px of the screen bottom - the band the
 * "🤖 AI" link sits in - that the link does NOT know about: none of its
 * classes is in $bars (the link hides for it) or lifts the link
 * (--admin-fab-bottom), and it does not paint above the link (z-index > 34).
 * Returns "file: selector" per miss. Inline style="" attributes are not seen.
 */
function bottomBarsUnhandled(array $bars): array
{
    $known = ['os-dock', 'sb-bar'];   // admin_css() raises --admin-fab-bottom over these: the link moves up instead
    foreach ($bars as $s) {
        if (preg_match('/^\.([\w-]+)/', $s, $m) === 1) { $known[] = $m[1]; }
    }
    $root = dirname(__DIR__);
    $out  = [];
    foreach (array_merge(glob($root . '/admin/*.php') ?: [], glob($root . '/includes/*.php') ?: []) as $f) {
        preg_match_all('/([^{};\n]{1,200})\{([^{}]*)\}/', (string) file_get_contents($f), $mm, PREG_SET_ORDER);
        foreach ($mm as [, $sel, $body]) {
            $b = strtolower(trim((string) preg_replace(['/\s*([:;,])\s*/', '/\s+/'], ['$1', ' '], $body)));
            if (str_contains($b, '=') || preg_match('/(?:^|;)position:(?:fixed|sticky)\b/', $b) !== 1) { continue; }   // '=': a JS block, not CSS
            if (str_contains($sel, 'adm-ai-go') || str_contains($sel, 'shg-aib')) { continue; }                      // the link / the bubble
            $bottom = null;
            if (preg_match('/(?:^|;)bottom:([^;]+)/', $b, $bm) === 1) {
                $bottom = $bm[1];
            } elseif (preg_match('/(?:^|;)inset:([^;]+)/', $b, $im) === 1) {
                $p = explode(' ', trim($im[1]));
                $bottom = $p[count($p) <= 2 ? 0 : 2];
            }
            $px = $bottom === null ? null
                : (preg_match('/(-?\d+(?:\.\d+)?)px/', $bottom, $pm) === 1 ? (float) $pm[1] : (preg_match('/^-?0(?:\D|$)/', $bottom) === 1 ? 0.0 : null));
            if ($px === null || $px > 58) { continue; }                                         // not pinned to the bottom band
            if (preg_match('/(?:^|;)z-index:(\d+)/', $b, $zm) === 1 && (int) $zm[1] > 34) { continue; }   // paints above the link
            preg_match_all('/\.([a-z][\w-]*)/i', $sel, $cm);
            if (array_intersect($cm[1], $known) === []) {
                $out[] = basename($f) . ': ' . trim($sel, " \t.'");
            }
        }
    }
    return $out;
}

/** Edge / Chrome for the 360 px check (SHG_TEST_BROWSER overrides), or null to skip it. */
function findBrowser(): ?string
{
    foreach ([(string) getenv('SHG_TEST_BROWSER'), 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
              'C:\Program Files\Microsoft\Edge\Application\msedge.exe', 'C:\Program Files\Google\Chrome\Application\chrome.exe',
              '/usr/bin/microsoft-edge', '/usr/bin/google-chrome', '/usr/bin/chromium'] as $p) {
        if ($p !== '' && is_file($p)) { return $p; }
    }
    return null;
}

function rmTree(string $dir): void
{
    for ($try = 0; $try < 5 && is_dir($dir); $try++) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
        if (is_dir($dir)) { usleep(400000); }   // the browser may still hold its profile for a moment
    }
}

/**
 * The real thing at 360 x 740: $html (a page this test fetched signed in) in a
 * headless browser, inside a 360 x 740 iframe (a headless window will not go
 * below ~500px wide). The bar at $needle is pushed 2400px down and $show
 * brings it up; the page is scrolled mid-list so the bar sits in the link's
 * corner - pinned there when it is sticky (payments.php, the reviewed case),
 * scrolled there when its box cannot stick (agent-ranking's .panel clips).
 * Measures the link with no bar, the link FORCED visible over the bar (what
 * the old rule did: overlap + what a tap there hits), the link under the real
 * rule, and every 6px point inside the button's rounded shape. Null when the
 * browser gave nothing back. Own profile folder; the user's browser is untouched.
 */
function geo(string $browser, string $html, string $needle, string $barId, string $btnId, string $show): ?array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_u11y4_geo_' . getmypid() . '_' . bin2hex(random_bytes(3));
    @mkdir($tmp . DIRECTORY_SEPARATOR . 'profile', 0777, true);
    $probe = <<<'JS'
window.addEventListener('load', function () { setTimeout(function () {
  var r = {vw: document.documentElement.clientWidth, vh: document.documentElement.clientHeight}, a = document.getElementById('admAiGo'), bar = document.getElementById('__BAR__'), go = document.getElementById('__BTN__');
  function box(el) { var b = el.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top), Math.round(b.right), Math.round(b.bottom)]; }
  function shown(el) { return getComputedStyle(el).display !== 'none'; }
  r.idle = a ? shown(a) : null;
  __SHOW__
  window.scrollTo(0, 600);
  var vh = document.documentElement.clientHeight, b0 = bar.getBoundingClientRect();
  r.pinned = window.scrollY > 0 && b0.top >= 0 && b0.bottom <= vh && b0.bottom >= vh - 60;
  if (!r.pinned) { window.scrollBy(0, b0.bottom - (vh - 12)); }
  var gb = go.getBoundingClientRect(), bb = bar.getBoundingClientRect();
  r.btn = box(go); r.bar = box(bar); r.scrollY = Math.round(window.scrollY);
  r.inCorner = bb.top >= 0 && bb.bottom <= vh && bb.bottom >= vh - 60;
  var rad = Math.min(parseFloat(getComputedStyle(go).borderTopLeftRadius) || 0, gb.height / 2, gb.width / 2);
  function inShape(x, y) {   // inside the button's rounded outline (hit-testing follows border-radius)
    if (rad < 3) { return true; }
    var cx = Math.min(Math.max(x, gb.left + rad), gb.right - rad), cy = Math.min(Math.max(y, gb.top + rad), gb.bottom - rad);
    return (x - cx) * (x - cx) + (y - cy) * (y - cy) <= (rad - 2) * (rad - 2);
  }
  function grid() {          // [points inside the button, points whose tap does NOT reach the button]
    var n = 0, miss = 0;
    for (var x = gb.left + 3; x < gb.right - 2; x += 6) { for (var y = gb.top + 3; y < gb.bottom - 2; y += 6) {
      if (!inShape(x, y)) { continue; }
      n++; var e = document.elementFromPoint(x, y); if (!e || !(e === go || go.contains(e))) { miss++; }
    } }
    return [n, miss];
  }
  if (a) {
    a.style.setProperty('display', 'inline-flex', 'important');   // what the old rule left on screen
    var ab = a.getBoundingClientRect(), x1 = Math.max(bb.left, ab.left), y1 = Math.max(bb.top, ab.top), x2 = Math.min(bb.right, ab.right), y2 = Math.min(bb.bottom, ab.bottom);
    r.link = box(a);
    r.overlap = x2 > x1 && y2 > y1 ? Math.round((x2 - x1) * (y2 - y1)) : 0;
    var h = r.overlap ? document.elementFromPoint((x1 + x2) / 2, (y1 + y2) / 2) : null;
    r.forcedHit = h ? (h.id || h.tagName) : null;
    r.missForced = grid()[1];
    a.style.removeProperty('display');
    r.barUp = shown(a);
  }
  var g = grid();
  r.points = g[0]; r.miss = g[1];
  parent.postMessage(JSON.stringify(r), '*');
}, 300); });
JS;
    $outer = '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">'
        . '<iframe src="page.html" style="display:block;border:0;width:360px;height:740px"></iframe><pre id="u11geo"></pre>'
        . '<script>addEventListener("message", function (e) { document.getElementById("u11geo").textContent = String(e.data); });</script></body></html>';
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'outer.html', $outer);
    $probe = str_replace(['__BAR__', '__BTN__', '__SHOW__'], [$barId, $btnId, $show], $probe);
    $page  = (string) preg_replace('/<head([^>]*)>/i', '<head$1><base href="' . BASE . '/admin/">', $html, 1);
    $page  = str_replace($needle, '<div style="height:2400px" aria-hidden="true"></div>' . $needle, $page);
    $page  = str_replace('</body>', '<script>' . $probe . '</script></body>', $page);
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'page.html', $page);
    $cmd = [$browser, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
            '--user-data-dir=' . $tmp . DIRECTORY_SEPARATOR . 'profile', '--window-size=800,1100', '--hide-scrollbars',
            '--virtual-time-budget=8000', '--dump-dom', 'file:///' . str_replace('\\', '/', $tmp) . '/outer.html'];
    $out  = $tmp . DIRECTORY_SEPARATOR . 'out.html';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $tmp . DIRECTORY_SEPARATOR . 'err.txt', 'w']], $pipes);
    $res  = null;
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $until = time() + 90;
        while (proc_get_status($proc)['running'] && time() < $until) { usleep(200000); }
        if (proc_get_status($proc)['running']) { proc_terminate($proc); }
        proc_close($proc);
        if (preg_match('/<pre id="u11geo">(.*?)<\/pre>/s', (string) @file_get_contents($out), $m) === 1) {
            $res = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
        }
    }
    rmTree($tmp);
    return is_array($res) ? $res : null;
}

/* the one switch this test turns on, put back exactly as found */
$prior   = Database::fetch('SELECT svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => PROMO_KEY]);
$restore = static function () use ($prior): void {
    if ($prior === null) { Database::delete('settings', 'skey = :k', ['k' => PROMO_KEY]); }
    else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $prior['svalue'], 'k' => PROMO_KEY]); }
    Settings::flush();
};
$setSwitch = static function (bool $on) use ($prior): void {
    if ($prior === null) { Settings::set(PROMO_KEY, $on ? '1' : '0', 'bool', 'whatsapp', false); }
    else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $on ? '1' : '0', 'k' => PROMO_KEY]); }
    Settings::flush();
};

echo "\n=== Y4 comms: comments tab, offer link, AI Centre quality, AI link ===\n\n";
cleanup();
try {
    /* ---------------- seed ---------------- */
    $mkAdmin = static function (string $user, string $role, string $name): int {
        $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
            'full_name' => $name, 'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
        QuickUnlock::set($id, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
        return $id;
    };
    $bossId = $mkAdmin(BOSS, 'superadmin', 'U11 Y4 Boss');
    $acctId = $mkAdmin(ACCT, 'accountant', 'U11 Y4 Accounts');
    foreach ([[PNR_A, PH, 'U11 Offer Pax'], [PNR_B, PH_NEAR, 'U11 Near Pax']] as [$pnr, $ph, $nm]) {
        $bid = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $ph, 'status' => 'confirmed', 'source' => 'admin', 'total_amount' => 0]);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'passenger_ref' => $pnr . '-1', 'seat_no' => 'Z1', 'full_name' => $nm]);
    }

    /* ---------------- P6-9d: the exact phone filter ---------------- */
    $f = CustomerDirectory::filters(['phone' => PH]);
    check('filters() keeps the 10-digit phone', $f['phone'] === PH);
    check('filters() cuts +91 / 977 prefixes to the last 10 digits', CustomerDirectory::filters(['phone' => '+91 ' . PH])['phone'] === PH);
    $one = CustomerDirectory::phones($f, true, 5);
    check('phones(phone=n) is exactly that one number', count($one) === 1 && $one[0]['ph'] === PH && $one[0]['name'] === 'U11 Offer Pax',
        count($one) . ' row(s)');
    $fuzzy = CustomerDirectory::phones(CustomerDirectory::filters(['q' => substr(PH, 0, 9)]), false, 50);
    $fuzzyPh = array_column($fuzzy, 'ph');
    check('  …the near number is NOT matched (exact, not the fuzzy q= search that finds both)',
        in_array(PH_NEAR, $fuzzyPh, true) && in_array(PH, $fuzzyPh, true) && !in_array(PH_NEAR, array_column($one, 'ph'), true));
    check('a short / bad phone matches nobody (never "all customers")',
        CustomerDirectory::phones(CustomerDirectory::filters(['phone' => substr(PH, 0, 9)]), false, 50) === []
        && CustomerDirectory::phones(CustomerDirectory::filters(['phone' => 'abc']), false, 50) === []
        && CustomerDirectory::phones(CustomerDirectory::filters(['phone' => ['x']]), false, 50) === []);
    check('no phone = no filter (the old behaviour)', CustomerDirectory::filters([])['phone'] === ''
        && count(CustomerDirectory::phones(CustomerDirectory::filters([]), false, 5000)) >= 2);
    check('qs() carries the phone (a campaign audience survives a round trip)', str_contains(CustomerDirectory::qs($f), 'phone=' . PH));
    check('promoLink() builds the exact audience', WaInbox::promoLink('91' . PH) === 'campaigns.php?new=1&aud=phone%3D' . PH
        && WaInbox::promoLink('12345') === '');

    /* promoLinkOn(): the switch AND the campaigns permission */
    $setSwitch(false);
    actAs($bossId);
    check('promoLinkOn(): OFF while the switch is 0, even for the owner', WaInbox::promoLinkOn() === false);
    $setSwitch(true);
    check('promoLinkOn(): ON with the switch 1 for the owner', WaInbox::promoLinkOn() === true);
    actAs($acctId, 'accountant');
    check('promoLinkOn(): never without marketing.send (accountant)', WaInbox::promoLinkOn() === false);
    actAs($acctId, 'operations');
    check('promoLinkOn(): never without marketing.send (operations, has customers.view)', WaInbox::promoLinkOn() === false);
    $setSwitch(false);

    /* ---------------- P6-9a: channelTabs ---------------- */
    actAs($bossId);
    $tabs = WaInbox::channelTabs('comments');
    check('channelTabs() has the FB comments tab', str_contains($tabs, 'href="fb-comments.php"') && str_contains($tabs, '💬 FB कमेन्ट · Comments'));
    check('  …marked current on the comments page', (bool) preg_match('/href="fb-comments\.php" class="on" aria-current="page"/', $tabs));
    check('  …before Sent', strpos($tabs, 'fb-comments.php') < (int) strpos($tabs, 'tab=sent'));
    check('  …the strip wraps (flex-wrap) for a 360 px phone', str_contains($tabs, 'flex-wrap:wrap') && str_contains($tabs, 'max-width:100%'));
    actAs($acctId, 'accountant');
    check('no comments tab for someone fb-comments.php would refuse (no customers.view)', !str_contains(WaInbox::channelTabs('whatsapp'), 'fb-comments.php'));
    actAs(null);

    /* ---------------- P7-9c: WaInbox::quality() and its guards ---------------- */
    $turnsOk = true;
    try {
        Database::insert('ai_turns', ['created_at' => date('Y-m-d H:i:s'), 'channel' => 'whatsapp', 'role' => 'customer', 'owner_key' => OWNERKEY,
            'router' => 'wafaq', 'tools' => '', 'ok' => 1, 'ms' => 900, 'reply_log_id' => MSG_ID]);
    } catch (Throwable $e) { $turnsOk = false; }
    $fbOk = true;
    try {
        $row = ['owner_key' => OWNERKEY, 'channel' => 'whatsapp', 'verdict' => 'down', 'user_text' => null, 'reply_text' => 'u11 test reply'];
        if (WaInbox::feedbackReady() === 2) { $row['msg_id'] = MSG_ID; $row['admin_id'] = $bossId; }
        Database::insert('ai_feedback_log', $row);
    } catch (Throwable $e) { $fbOk = false; }
    $qOk = true;
    try {
        Database::insert('ai_unanswered_questions', ['question_hash' => hash('sha256', QMARK), 'normalized_question' => QMARK, 'language' => 'en',
            'frequency' => 9999, 'status' => 'needs_owner']);
    } catch (Throwable $e) { $qOk = false; }
    check('seeded a 👎, its ai_turns row and an open question', $turnsOk && $fbOk && $qOk);

    $qa = WaInbox::quality(7, 14);
    $hit = array_values(array_filter($qa['down'] ?? [], static fn(array $r): bool => $r['channel'] === 'whatsapp' && $r['router'] === 'wafaq'));
    check('quality(): 👎 grouped by channel + branch (LEFT JOIN ai_turns on the reply id)', $hit !== [] && $hit[0]['n'] >= 1,
        json_encode($qa['down'], JSON_UNESCAPED_UNICODE));
    check('quality(): the 👎 total counts it', (int) $qa['down_total'] >= 1);
    check('quality(): the open question is in the top 10 (needs_owner counts)', in_array(QMARK, array_column($qa['unanswered'] ?? [], 'question'), true));
    $miss = WaInbox::quality(7, 14, ['feedback' => 'zz_u11_no_such_table', 'unanswered' => 'zz_u11_no_such_table', 'practice' => 'zz_u11_no_such_table']);
    check('guard: a missing feedback table gives null (shown as —), no exception', $miss['down'] === null && $miss['down_total'] === null);
    check('guard: a missing questions / practice table gives null', $miss['unanswered'] === null && $miss['practice'] === null);
    $noTurns = WaInbox::quality(7, 14, ['turns' => 'zz_u11_no_such_table']);
    check('guard: no ai_turns -> 👎 by channel only (branch empty), not null', is_array($noTurns['down'])
        && in_array('whatsapp', array_column($noTurns['down'], 'channel'), true) && array_unique(array_column($noTurns['down'], 'router')) === ['']);
    check('guard: a table name is never taken raw into SQL', WaInbox::quality(7, 14, ['feedback' => 'x; DROP TABLE y'])['down'] !== null);
    $haveP = true;
    try { Database::scalar('SELECT 1 FROM ap_ai_practice LIMIT 1', [], null); } catch (Throwable $e) { $haveP = false; }
    check('practice: ' . ($haveP ? 'a list (table present)' : 'null (table missing here)'), $haveP ? is_array($qa['practice']) : $qa['practice'] === null);

    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/ai-center.php');
    check('ai-center: "Could not answer" reads new + needs_owner (no status \'open\')',
        str_contains($src, "WHERE status IN ('new', 'needs_owner')") && !str_contains($src, "status = 'open'"));

    /* ---------------- P8-14b: the footer link, in this process ---------------- */
    $footer = static function (string $script, array $get = []): string {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_GET = $get;
        ob_start();
        try { admin_footer(); } finally { $out = (string) ob_get_clean(); }
        return $out;
    };
    actAs($acctId, 'accountant');
    $o = $footer('/admin/payments.php');
    check('footer: the 🤖 AI link on a normal page (accountant: bookings.view, no bubble)', str_contains($o, 'id="admAiGo"')
        && str_contains($o, 'href="/admin/ai-copilot.php"') && str_contains($o, 'aria-label="एआई सहायक · AI Sahayak"') && str_contains($o, '>🤖 AI</a>'));
    check('  …bottom: var(--admin-fab-bottom, 16px), right 16px, ≥ 40px tall, theme tokens',
        str_contains($o, 'right:16px;bottom:var(--admin-fab-bottom,16px)') && str_contains($o, 'min-height:42px')
        && str_contains($o, 'background:var(--card') && str_contains($o, 'color:var(--ink'));
    $hideList = preg_match('/body:has\(([^{}]+)\) \.adm-ai-go\{display:none\}/', $o, $hm) === 1 ? explode(',', $hm[1]) : [];
    check('  …steps aside while ANY page bottom bar is up (one :has() list = admin_ai_link_bars())', $hideList !== [] && $hideList === admin_ai_link_bars(),
        implode(' ', $hideList));
    foreach (['.bulk-bar:not([hidden])' => 'Tickets / Agents bulk bar', '.pq-bulk:not([hidden])' => 'payments.php ✓ Approve picked',
              '.cmp-bar.on' => 'agent-ranking.php ⚖️ Compare', '[data-adm-bottom-bar]:not([hidden])' => 'the shared data-adm-bottom-bar marker',
              '.ap-save' => 'agreement-points save bar', '.co-save' => 'company save bar', '.ct-save' => 'places / tours save bar'] as $sel => $what) {
        check('    …hides for ' . $what . ' (' . $sel . ')', in_array($sel, $hideList, true));
    }
    check('  …the open menu hides it in a rule of its own (works without :has())', str_contains($o, 'body.nav-open .adm-ai-go{display:none}')
        && !str_contains($o, ',body.nav-open .adm-ai-go'));
    check('  …a browser without :has() never shows it (cannot tell when a bar is up)', str_contains($o, '@supports not selector(:has(a)){.adm-ai-go{display:none}}'));
    check('  …never prints', str_contains($o, '@media print{.adm-ai-go{display:none!important}}'));
    check('  …no settings bubble on that page', !str_contains($o, 'admin-ai-bubble.js'));
    check('footer: no AI link on ai-copilot.php itself', !str_contains($footer('/admin/ai-copilot.php'), 'admAiGo'));
    check('footer: no AI link on the login / PIN / lock pages', !str_contains($footer('/admin/login.php'), 'admAiGo')
        && !str_contains($footer('/admin/pin-setup.php'), 'admAiGo') && !str_contains($footer('/admin/quick-unlock.php'), 'admAiGo'));
    check('footer: no AI link on a ?print view', !str_contains($footer('/admin/payments.php', ['print' => '1']), 'admAiGo'));
    actAs($bossId);
    $o = $footer('/admin/bookings.php');
    check('footer: with the settings bubble there is no second floating button', str_contains($o, 'admin-ai-bubble.js') && !str_contains($o, 'id="admAiGo"'));
    check('  …the bubble tag is unchanged and a hidden copilot marker follows it (its sheet links AI Sahayak)',
        (bool) preg_match('#<script src="/assets/js/admin-ai-bubble\.js\?v=\d+" defer></script><i hidden data-shg-copilot="1"></i>#', $o));
    actAs($acctId, 'u11_custom', ['dashboard.view', 'settings.manage']);   // a bubble person without bookings.view
    $o = $footer('/admin/bookings.php');
    check('  …no copilot marker for someone the AI page would refuse', !str_contains($o, 'data-shg-copilot')
        && str_contains($o, 'admin-ai-bubble.js'));
    actAs(null);
    $o = $footer('/admin/payments.php');
    check('footer: nobody signed in -> no AI link', !str_contains($o, 'admAiGo'));
    $_GET = [];
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/admin-ai-bubble.js');
    check('bubble JS: "🤖 एआई सहायक · AI Sahayak" -> /admin/ai-copilot.php beside "Full page →"',
        str_contains($js, "'🤖 एआई सहायक · AI Sahayak'") && str_contains($js, "sahayak.href = '/admin/ai-copilot.php'")
        && (bool) preg_match('/links\.appendChild\(full\);\s*if \(COPILOT\) \{/', $js));
    check('  …only when the page carries the copilot marker', str_contains($js, "document.querySelector('[data-shg-copilot=\"1\"]')"));
    check('  …the two sit in one no-wrap row (never stacked at 360 px)', str_contains($js, '.shg-aib-ftl{display:flex;flex-wrap:nowrap'));

    /* ---------------- P8-14b review: no page's own bottom bar is left under the link ---------------- */
    $unh = bottomBarsUnhandled(admin_ai_link_bars());
    check('scan: every bottom-pinned bar on a staff page is known to the 🤖 AI link (it hides, is lifted, or the bar paints above it)',
        $unh === [], implode(' | ', $unh));
    $old = bottomBarsUnhandled(['.bulk-bar:not([hidden])']);
    check('  …the scan catches the reviewed miss: against the old bulk-bar-only rule it flags payments.php .pq-bulk + agent-ranking.php .cmp-bar',
        in_array('payments.php: pq-bulk', $old, true) && in_array('agent-ranking.php: cmp-bar', $old, true), implode(' | ', $old));

    /* ---------------- HTTP: sign in ---------------- */
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
    $r = login('boss', BOSS);
    check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), where($r));

    /* P6-9a on the page */
    $fc = http('boss', '/admin/fb-comments.php');
    check('fb-comments.php renders (logged in)', $fc['code'] === 200, where($fc));
    check('  …with the comms strip, Comments current', str_contains($fc['body'], '<nav class="wi-chan" aria-label="Channels">')
        && (bool) preg_match('/href="fb-comments\.php" class="on" aria-current="page">💬 FB कमेन्ट · Comments</u', $fc['body']));
    check('  …the strip sits right after the page head, before the page\'s own content',
        strpos($fc['body'], 'class="wi-chan"') !== false && strpos($fc['body'], 'class="wi-chan"') < (int) strpos($fc['body'], 'class="fc-card"'));
    check('  …superadmin page carries the bubble + copilot marker, no extra AI link', str_contains($fc['body'], '<i hidden data-shg-copilot="1"></i>')
        && !str_contains($fc['body'], 'id="admAiGo"'));
    $wi = http('boss', '/admin/wa-inbox.php?tab=inbox&p=91' . PH);
    check('wa-inbox.php renders with the comments tab in the strip', $wi['code'] === 200 && str_contains($wi['body'], 'href="fb-comments.php"'), where($wi));

    /* P6-9d on the page */
    $setSwitch(false);
    $wi = http('boss', '/admin/wa-inbox.php?tab=inbox&p=91' . PH);
    check('switch 0: no 🎁 Offer link in the chat header', $wi['code'] === 200 && !str_contains($wi['body'], 'id="wiPromo"') && str_contains($wi['body'], 'Book for them'), where($wi));
    $setSwitch(true);
    $wi = http('boss', '/admin/wa-inbox.php?tab=inbox&p=91' . PH);
    check('switch 1: the 🎁 अफर · Offer link is there', str_contains($wi['body'], 'id="wiPromo"') && str_contains($wi['body'], '🎁 अफर · Offer'));
    check('  …pointing at a new campaign for exactly this number', str_contains($wi['body'], 'href="campaigns.php?new=1&amp;aud=phone%3D' . PH . '"'));
    $setSwitch(false);
    $wi = http('boss', '/admin/wa-inbox.php?tab=inbox&p=91' . PH);
    check('switch back to 0: the link is gone again', !str_contains($wi['body'], 'id="wiPromo"'));

    $cp = http('boss', '/admin/campaigns.php?new=1&aud=' . rawurlencode('phone=' . PH));
    check('campaigns ?new&aud=phone=<n> renders', $cp['code'] === 200, where($cp));
    check('  …the audience is pre-filled exactly', str_contains($cp['body'], 'name="audience" value="phone=' . PH . '"')
        && str_contains($cp['body'], 'One number ' . PH));
    check('  …the preview shows 1 recipient: that number and name', str_contains($cp['body'], 'data-n="1"')
        && str_contains($cp['body'], '1 recipient') && str_contains($cp['body'], 'U11 Offer Pax · +91' . PH));
    check('  …and not the near number', !str_contains($cp['body'], PH_NEAR));
    check('  …the message preview uses their name', (bool) preg_match('/id="mkPrev">[^<]*U11/u', $cp['body']));
    check('  …nothing was saved by opening it', (int) Database::scalar("SELECT COUNT(*) FROM mk_campaigns WHERE audience = :a", ['a' => 'phone=' . PH], 0) === 0);
    $cp0 = http('boss', '/admin/campaigns.php?new=1&aud=' . rawurlencode('phone=12345'));
    check('a bad number previews 0 recipients (never everybody)', $cp0['code'] === 200 && str_contains($cp0['body'], 'data-n="0"') && str_contains($cp0['body'], '0 recipients'));

    /* P7-9c on the page */
    $ac = http('boss', '/admin/ai-center.php');
    check('ai-center.php renders', $ac['code'] === 200, where($ac));
    check('  …with the read-only "पछिल्लो ७ दिन · Last 7 days" block', str_contains($ac['body'], 'id="acLast7"') && str_contains($ac['body'], 'पछिल्लो ७ दिन · Last 7 days'));
    check('  …👎 by channel + branch (FAQ)', (bool) preg_match('/data-q7="down".*?data-label="Branch">FAQ</su', $ac['body']));
    check('  …the open question, linked to Knowledge', (bool) preg_match('/<a href="\/admin\/ai-knowledge\.php">' . preg_quote(QMARK, '/') . '<\/a>/', $ac['body'])
        && str_contains($ac['body'], 'Needs your answer'));
    check('  …practice: ' . ($haveP ? 'shown' : '— for the missing ap_ai_practice table (no 500)'),
        $haveP ? (str_contains($ac['body'], 'data-q7="practice"') || str_contains($ac['body'], 'No practice run')) : str_contains($ac['body'], 'data-q7="practice-missing"'));
    $open = (int) Database::scalar("SELECT COUNT(*) FROM ai_unanswered_questions WHERE status IN ('new', 'needs_owner')", [], 0);
    check('  …"Questions it could not answer" = new + needs_owner (' . $open . ')',
        (bool) preg_match('/<b>' . preg_quote(number_format($open), '/') . '<\/b><span>Questions it could not answer/', $ac['body']));
    check('  …tables are card-tables for a 360 px phone', substr_count($ac['body'], 'class="dt card-table" data-q7=') >= 2);

    /* P8-14b on the page: an accountant has no settings bubble */
    $r = login('acct', ACCT);
    check('accountant signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), where($r));
    $pg = http('acct', '/admin/ai-center.php');
    check('a normal page prints the 🤖 AI link (no bubble for this role)', $pg['code'] === 200 && str_contains($pg['body'], 'id="admAiGo"')
        && !str_contains($pg['body'], 'admin-ai-bubble.js'), where($pg));
    $cop = http('acct', '/admin/ai-copilot.php');
    check('ai-copilot.php does not print it', $cop['code'] === 200 && !str_contains($cop['body'], 'id="admAiGo"'), where($cop));

    /* P8-14b review: payments.php "✓ Approve picked" (and agent-ranking "⚖️ Compare") never under the link */
    $pay = http('acct', '/admin/payments.php');
    check('payments.php renders for the accountant: its bulk bar + the 🤖 AI link (no bubble)', $pay['code'] === 200
        && str_contains($pay['body'], '<div class="pq-bulk" id="pqBulk" hidden>') && str_contains($pay['body'], 'id="admAiGo"')
        && !str_contains($pay['body'], 'admin-ai-bubble.js'), where($pay));
    check('  …the page carries the hide rule for that bar', (bool) preg_match('/body:has\([^{}]*\.pq-bulk:not\(\[hidden\]\)[^{}]*\) \.adm-ai-go\{display:none\}/', $pay['body']));
    $rank = http('acct', '/admin/agent-ranking.php');
    check('agent-ranking.php renders: its ⚖️ Compare bar, the link and the hide rule for .cmp-bar.on', $rank['code'] === 200
        && str_contains($rank['body'], 'id="cmpBar"') && str_contains($rank['body'], 'id="admAiGo"')
        && (bool) preg_match('/body:has\([^{}]*\.cmp-bar\.on[^{}]*\) \.adm-ai-go\{display:none\}/', $rank['body']), where($rank));

    $browser = findBrowser();
    if ($browser === null) {
        echo "  SKIP  360 px browser check (no Edge / Chrome here; set SHG_TEST_BROWSER to run it)\n";
    } else {
        $cases = [   // what => [page, bar markup, bar id, button id, bring the bar up, the bar is sticky-pinned mid-list]
            'payments.php ✓ Approve picked' => [$pay['body'], '<div class="pq-bulk" id="pqBulk" hidden>', 'pqBulk', 'pqBulkGo',
                "bar.hidden = false; go.textContent = '✓ Approve 3';", true],
            'agent-ranking.php ⚖️ Compare' => [$rank['body'], '<div class="cmp-bar" id="cmpBar">', 'cmpBar', 'cmpGo',
                "bar.classList.add('on'); go.disabled = false;", false],   // its .panel clips sticky: it scrolls through the corner
        ];
        foreach ($cases as $what => [$html, $needle, $barId, $btnId, $show, $sticky]) {
            $g = geo($browser, $html, $needle, $barId, $btnId, $show);
            check('360 px, ' . $what . ': the browser measured the page at phone width', $g !== null && (int) $g['vw'] >= 340 && (int) $g['vw'] <= 360,
                $g === null ? 'no result from ' . basename($browser) : 'viewport ' . $g['vw'] . 'x' . $g['vh']);
            if ($g === null) { continue; }
            $info = 'scrollY ' . $g['scrollY'] . ' bar ' . json_encode($g['bar']) . ' button ' . json_encode($g['btn']) . ' link ' . json_encode($g['link'] ?? null);
            check('  …no bar up: the 🤖 AI link is shown', $g['idle'] === true);
            check($sticky ? '  …the bar is sticky-pinned to the screen bottom mid-list (the reviewed case)' : '  …the bar is scrolled into the link\'s corner',
                ($sticky ? $g['pinned'] === true : true) && $g['inCorner'] === true, $info);
            check('  …the old rule would cover it: link forced visible overlaps the bar and takes the tap there',
                (int) ($g['overlap'] ?? 0) > 0 && ($g['forcedHit'] ?? '') === 'admAiGo', 'overlap ' . ($g['overlap'] ?? 0) . 'px², hit ' . ($g['forcedHit'] ?? '-'));
            if ($sticky) {
                check('  …under the old rule taps on the button itself landed on the link (the reviewed bug, reproduced)', (int) ($g['missForced'] ?? 0) > 0,
                    ($g['missForced'] ?? 0) . ' of ' . $g['points'] . ' button points went to the link');
            }
            check('  …with the bar up the link is hidden', ($g['barUp'] ?? true) === false);
            check('  …every point of the bar\'s button taps the button', (int) $g['points'] > 0 && (int) $g['miss'] === 0,
                $g['miss'] . ' of ' . $g['points'] . ' points missed');
        }
    }
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
actAs(null);
cleanup();
$restore();
foreach ($JARS as $j) { @unlink($j); }
check('switch ' . PROMO_KEY . ' is back as found', (string) (Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => PROMO_KEY])['svalue'] ?? 'none')
    === (string) ($prior['svalue'] ?? 'none'));
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
