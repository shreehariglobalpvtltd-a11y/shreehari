<?php
/**
 * =====================================================================
 *  u11-Y2-aioffers-test.php — Master v2 unit Y2 (16 Nov 2026):
 *  AI Sahayak makes an offer / coupon (P3-2f + P8-10e) + four new
 *  Easy Settings switches.
 *
 *  1. OfferRules::validate() holds an offer to admin/offers.php's rules
 *     (code shape + unique, value > 0, percent ≤ 100 WITH a cap, end ≥
 *     start, route exists, max uses 1..1,000,000 and never below used,
 *     per-mobile 0..999, hours-ahead 0..720) with bilingual messages, and
 *     the rule text in admin/offers.php has not drifted from it.
 *  2. admin/easy-settings.php (each call its own PHP process with a
 *     hand-built staff session, as in u5): "coupon … 10% max 200 till …"
 *     → preview "created OFF", nothing written → "Yes" makes the coupon
 *     with is_active = 0 AND auto_apply = 0, audited offer.create; "offer
 *     band CODE" → OFF, audited offer.toggle; an offers-only manager
 *     (coupons.edit) may command, an operations login may not make an
 *     offer; the four new switches render and save (settings.easy).
 *  3. With SHG_TEST_BASE (a dev server) and curl: the same through the
 *     in-page bubble admin/api/ai-settings.php after a real sign-in.
 *
 *  shari_test only. Every coupon (ZY2…), admin (u11_y2_…), audit row and
 *  rate-limit bucket it makes is deleted; the two switches it saves are
 *  put back exactly (no row = no row).
 *
 *    php -c .claude/php-dev.ini tests/u11-Y2-aioffers-test.php
 *    SHG_TEST_BASE=http://localhost:8982 php -c <ini> -d extension=php_curl.dll tests/u11-Y2-aioffers-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['SCRIPT_NAME']    = '/admin/easy-settings.php';
    $_SERVER['REQUEST_URI']    = '/admin/easy-settings.php';
    $_SERVER['HTTP_HOST']      = 'localhost';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (is_array($cfg['es_prop'] ?? null)) {
        $_SESSION['es_prop'] = $cfg['es_prop'] + ['t' => time()];
    }
    if (($cfg['session_out'] ?? '') !== '') {
        $f = (string) $cfg['session_out'];
        register_shutdown_function(static function () use ($f): void { file_put_contents($f, json_encode($_SESSION['es_prop'] ?? null, JSON_UNESCAPED_UNICODE)); });
    }
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
    }
    require dirname(__DIR__) . '/admin/easy-settings.php';
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/aiops.php';
require_once INCLUDE_PATH . '/offerrules.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function page(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}
/** The bilingual rule message OfferRules throws, or '' when the row passes. */
function why(array $in): string {
    try { OfferRules::validate($in); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
}
$noErr = static fn(string $h): bool => preg_match('/(<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|PHP (Warning|Notice|Fatal|Deprecated)|(Warning|Notice|Deprecated): .* on line \d|Application error)/', $h) !== 1;

echo "\n=== U11 Y2: AI makes an offer / coupon ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }

$tag     = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTVWXYZ'), 0, 4));
$C       = static fn(string $s): string => 'ZY2' . $tag . $s;
$startId = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u11-y2-' . bin2hex(random_bytes(4)) . '.json';
$d10     = date('Y-m-d', strtotime(todayISO() . ' +10 days'));
$d20     = date('Y-m-d', strtotime(todayISO() . ' +20 days'));
$say     = static fn(string $iso): string => date('j M', strtotime($iso));
$keepSw  = [];
foreach (['agent_followups_on', 'helpdesk_cancel_escalate_inr'] as $k) {
    $keepSw[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]);
}
$cleanup = static function () use ($tag, $startId, $tmp, $keepSw): void {
    Database::delete('coupons', 'code LIKE :c', ['c' => 'ZY2' . $tag . '%']);
    foreach ($keepSw as $k => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
        if (is_array($row)) { Database::insert('settings', $row); }
    }
    Database::delete('audit_logs', "id > :s AND (entity_id LIKE :c1 OR new_value LIKE :c2 OR detail LIKE :c3 OR entity_id IN ('agent_followups_on', 'helpdesk_cancel_escalate_inr'))",
        ['s' => $startId, 'c1' => 'ZY2' . $tag . '%', 'c2' => '%ZY2' . $tag . '%', 'c3' => '%ZY2' . $tag . '%']);
    // the fixture logins (u11_y2_…): their sessions, sign-in events, audit rows and rate-limit buckets, then the rows
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'u11\\_y2\\_%'") as $r) {
        $id = (int) $r['id'];
        foreach (['admin_sessions', 'admin_login_events'] as $tbl) {
            try { Database::run('DELETE FROM ' . $tbl . ' WHERE admin_id = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('audit_logs', "id > :s AND ((actor_type = 'admin' AND actor_id = :a) OR (entity_type = 'admin' AND entity_id = :e))", ['s' => $startId, 'a' => $id, 'e' => (string) $id]);
        try { Database::run("DELETE FROM rate_limits WHERE identifier = :i", ['i' => 'admin:' . $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
    if (is_file($tmp)) { @unlink($tmp); }
};
register_shutdown_function($cleanup);

try {
    /* ================= 1. OfferRules = admin/offers.php's rules ================= */
    $base = ['code' => $C('R1'), 'discount_type' => 'percent', 'discount_value' => 10, 'max_discount' => 200];
    $row  = OfferRules::validate($base + ['title' => '']);
    check('a good percent offer passes; the code is upper-case, the title defaults to the code', $row['code'] === $C('R1') && $row['title'] === $C('R1')
        && $row['discount_type'] === 'percent' && (float) $row['discount_value'] === 10.0 && (float) $row['max_discount'] === 200.0);
    check('only the coupons table\'s real columns come back', array_diff(array_keys($row), array_keys(OfferRules::columns())) === []);
    check('lower-case code is upper-cased; - and _ allowed', OfferRules::validate(['code' => strtolower($C('a-b_c')), 'discount_value' => 50])['code'] === strtoupper($C('a-b_c')));
    check('flat with no cap passes; per mobile defaults to 1; usage blank = no limit',
        ($r = OfferRules::validate(['code' => $C('F1'), 'discount_type' => 'flat', 'discount_value' => 50]))['per_user_limit'] === 1 && $r['usage_limit'] === null && $r['max_discount'] === null);
    $w = why(['code' => 'AB', 'discount_value' => 10]);
    check('code too short refused (bilingual)', str_contains($w, '3 to 40') && preg_match('/\p{Devanagari}/u', $w) === 1 && str_contains($w, ' · '), $w);
    // as on the Offers page (Security::clean(…, 40)) a longer code is cut to 40, never stored longer
    check('code with a space / a % refused; a 41-letter code is cut to 40', why(['code' => 'A B C', 'discount_value' => 10]) !== '' && why(['code' => 'AB%C', 'discount_value' => 10]) !== ''
        && strlen(OfferRules::validate(['code' => 'ZY2' . $tag . str_repeat('Q', 34), 'discount_value' => 10])['code']) === 40);
    check('discount 0 refused', str_contains(why(['code' => $C('Z'), 'discount_value' => 0]), 'more than zero'));
    check('percent over 100 refused', str_contains(why(['code' => $C('Z'), 'discount_type' => 'percent', 'discount_value' => 101, 'max_discount' => 5]), 'over 100%'));
    check('percent without a max refused', str_contains(why(['code' => $C('Z'), 'discount_type' => 'percent', 'discount_value' => 10]), 'maximum discount'));
    check('end before start refused', str_contains(why($base + ['valid_from' => $d20, 'valid_until' => $d10]), 'end date is before'));
    check('a date that is not a date refused', str_contains(why($base + ['valid_until' => '2026-02-31']), 'not a real date'));
    check('unknown route refused', str_contains(why($base + ['route_id' => 99999999]), 'route was not found'));
    check('max uses 0 / 1,000,001 refused, 1 / 1,000,000 pass', why($base + ['usage_limit' => 0]) !== '' && why($base + ['usage_limit' => 1000001]) !== ''
        && why($base + ['usage_limit' => 1]) === '' && why($base + ['usage_limit' => 1000000]) === '');
    check('per mobile clamps to 0 … 999', OfferRules::validate($base + ['per_user_limit' => 5000])['per_user_limit'] === 999 && OfferRules::validate($base + ['per_user_limit' => -3])['per_user_limit'] === 0);
    if (OfferRules::hasColumn('min_hours_ahead')) {
        check('hours ahead clamps to 720, 0 = none', OfferRules::validate($base + ['min_hours_ahead' => 5000])['min_hours_ahead'] === 720 && OfferRules::validate($base)['min_hours_ahead'] === null);
    }
    if (OfferRules::hasColumn('first_booking_only')) {
        check('first booking only = 1 / null', OfferRules::validate($base + ['first_booking_only' => '1'])['first_booking_only'] === 1 && OfferRules::validate($base + ['first_booking_only' => '0'])['first_booking_only'] === null);
    }
    $fixId = (int) Database::insert('coupons', ['code' => $C('USED'), 'title' => 'u11 fixture', 'discount_type' => 'flat', 'discount_value' => 20, 'is_active' => 1,
        'auto_apply' => 0, 'per_user_limit' => 1, 'used_count' => 5]);
    check('a code already used refused', str_contains(why(['code' => $C('USED'), 'discount_value' => 10]), 'already exists'));
    check('edit: the code stays, max uses below used refused, a missing id refused',
        OfferRules::validate(['id' => $fixId, 'code' => 'OTHER', 'discount_value' => 30])['code'] === $C('USED')
        && str_contains(why(['id' => $fixId, 'discount_value' => 30, 'usage_limit' => 4]), 'already used 5')
        && str_contains(why(['id' => 99999999, 'discount_value' => 30]), 'no longer exists'));
    // the hand-made Offers page still enforces these same rules (drift guard: admin/offers.php is another unit's file)
    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/offers.php');
    check('admin/offers.php still has the rules OfferRules copies', str_contains($src, "'/^[A-Z0-9_-]{3,40}$/'") && str_contains($src, "\$value > 100")
        && str_contains($src, "\$maxUse < 1 || \$maxUse > 1000000") && str_contains($src, 'min(999,') && str_contains($src, 'min(720,')
        && str_contains($src, "\$type === 'percent' && \$maxD === null"));
    check('the AI\'s own ceiling: 50 %, ₹5,000', AiOps::COUPON_MAX_PCT === 50 && AiOps::COUPON_MAX_FLAT === 5000);

    /* ================= 1b. review fixes: the offer step never takes over another sentence ================= */
    $w = AiOps::parseWords('Diwali offer Surat ko bhada ₹2000');
    check('review: "Diwali offer Surat ko bhada ₹2000" is Surat\'s fare (was coupon SURAT ₹2,000 off on every route)',
        ($w[0]['type'] ?? '') === 'stop_fare' && (float) ($w[0]['fare'] ?? 0) === 2000.0, json_encode($w, JSON_UNESCAPED_UNICODE));
    if (AiOps::settingRow('btn_offer_ribbon_on') !== null) {
        $w = AiOps::parseWords('btn_offer_ribbon_on band');
        check('review: "btn_offer_ribbon_on band" is the home ribbon switch again (rule 8)', $w === [['type' => 'setting', 'key' => 'btn_offer_ribbon_on', 'value' => '0']],
            json_encode($w) . ' ' . AiOps::lastNote());
    }
    $w = AiOps::parseWords('offer ribbon band');
    check('review: "offer ribbon band" gets no offer note (goes on to the AI as before)', $w === [] && AiOps::lastNote() === '', AiOps::lastNote());
    $w = AiOps::parseWords('offer Rajkot route ₹100 off');
    check('review: "offer Rajkot route ₹100 off" → its one route or "which route?", never code RAJKOT on every route',
        ($w === [] && str_contains(AiOps::lastNote(), 'route')) || (($w[0]['type'] ?? '') === 'coupon' && $w[0]['route_id'] !== null && $w[0]['code'] !== 'RAJKOT'),
        json_encode($w) . ' ' . AiOps::lastNote());
    $w = AiOps::parseWords('offer dinus ₹100 off');
    check('review: "offer dinus ₹100 off" makes its own code, not DINUS', ($w[0]['type'] ?? '') === 'coupon' && $w[0]['code'] !== 'DINUS' && $w[0]['auto_code'] === true, json_encode($w));
    $off = Database::fetch("SELECT id, route_code FROM routes WHERE is_active = 0 AND route_code <> '' ORDER BY id LIMIT 1");
    if ($off !== null) {
        $w = AiOps::parseWords('offer 10% max 200 route ' . $off['route_code']);
        check('review: an OFF route said by its code stays that route, never every route', ($w[0]['route_id'] ?? 0) === (int) $off['id'], json_encode($w));
    }

    /* ================= 2. admin/easy-settings.php ================= */
    $super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1", [], 0);
    $mkAdmin = static function (string $u, string $role, array $perms): int {
        return (int) Database::insert('admins', ['username' => $u, 'password_hash' => password_hash('U11y2Pass!' . $u, PASSWORD_DEFAULT), 'full_name' => 'U11 ' . $u,
            'role' => $role, 'permissions' => json_encode($perms), 'is_active' => 1, 'must_change_pw' => 0]);
    };
    $offersOnly = $mkAdmin('u11_y2_offers_' . strtolower($tag), 'support', ['coupons.edit']);
    $opsOnly    = $mkAdmin('u11_y2_ops_' . strtolower($tag), 'operations', []);
    if ($super <= 0) {
        echo "  SKIP  no super-admin for the page tests\n";
    } else {
        $h = page(['admin' => $super]);
        check('page renders with the offer examples, no PHP warning', str_contains($h, 'id="esCmd"') && str_contains($h, 'naya coupon SHG50 ₹50 off 50 jana') && $noErr($h));
        check('the four new switches are on the page', str_contains($h, 'Agents see service follow-ups on their own passengers') && str_contains($h, 'Open a follow-up when a cancelled ticket at or above')
            && str_contains($h, 'pay-at-boarding tickets on WhatsApp') && str_contains($h, 'Offer link in the WhatsApp inbox'));

        $cmd = 'coupon ' . $C('PG') . ' 10% max 200 till ' . $say($d20) . ' 40 jana';
        $h   = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => $cmd], 'session_out' => $tmp]);
        $sp  = json_decode((string) @file_get_contents($tmp), true);
        check('act=cmd: preview with code, cap, end date, uses and "created OFF"', str_contains($h, 'Change this?') && str_contains($h, $C('PG')) && str_contains($h, '₹200')
            && str_contains($h, date('j M Y', strtotime($d20))) && str_contains($h, '40 पटक') && str_contains($h, 'created OFF') && $noErr($h));
        check('act=cmd writes nothing', !Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $C('PG')]) && is_array($sp) && ($sp['ops'][0]['type'] ?? '') === 'coupon');
        $h   = page(['admin' => $super, 'post' => ['act' => 'yes'], 'es_prop' => $sp]);
        $row = Database::fetch('SELECT * FROM coupons WHERE code = :c', ['c' => $C('PG')]);
        check('"Yes" makes it: is_active 0, auto_apply 0, 10 % max ₹200, 40 uses', $row !== null && (int) $row['is_active'] === 0 && (int) $row['auto_apply'] === 0
            && (float) $row['discount_value'] === 10.0 && (float) $row['max_discount'] === 200.0 && (int) $row['usage_limit'] === 40 && $row['valid_until'] === $d20, json_encode($row));
        check('the flash says created OFF', str_contains($h, 'created OFF') && str_contains($h, 'Switch it ON on Offers'));
        check('offer.create + settings.ai_command audited', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'offer.create' AND entity_id = :c", ['s' => $startId, 'c' => $C('PG')])
            && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_command' AND new_value LIKE :c", ['s' => $startId, 'c' => '%' . $C('PG') . '%']));

        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'offer 60% max 200 ' . $C('BIG')]]);
        check('60 % is refused as the flash, no proposal', str_contains($h, 'too big, use the Offers page') && !str_contains($h, 'Change this?'));
        // review fixes, through the page: a fare sentence with "offer" previews the fare; a named town asks for its route
        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'Diwali offer Surat ko bhada ₹2345']]);
        check('review: "Diwali offer Surat ko bhada ₹2345" previews Surat\'s fare, not a new offer', str_contains($h, 'Change this?') && str_contains($h, '2,345')
            && !str_contains($h, 'New offer') && !Database::exists("SELECT 1 FROM coupons WHERE code = 'SURAT'") && $noErr($h));
        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'offer Rajkot route ₹100 off']]);
        check('review: "offer Rajkot route ₹100 off" on the page asks which route, no proposal', str_contains($h, 'Which route') && !str_contains($h, 'Change this?'));
        if (AiOps::settingRow('btn_offer_ribbon_on') !== null) {
            $rib = Settings::getBool('btn_offer_ribbon_on', true);   // flip it, so the preview has a change to show (nothing is saved: no "Yes")
            $h   = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'btn_offer_ribbon_on ' . ($rib ? 'band' : 'on')]]);
            check('review: "btn_offer_ribbon_on ' . ($rib ? 'band' : 'on') . '" previews the ribbon switch, not an offer note', str_contains($h, 'Change this?')
                && str_contains($h, 'btn_offer_ribbon_on') && !str_contains($h, 'Which offer') && !str_contains($h, 'never switches an offer ON'));
        }

        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'offer on ' . $C('USED')]]);
        check('"offer on CODE": the AI never switches an offer ON', str_contains($h, 'never switches an offer ON') && !str_contains($h, 'Change this?')
            && (int) Database::scalar('SELECT is_active FROM coupons WHERE id = :i', ['i' => $fixId], 0) === 1);

        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'offer band ' . $C('USED')], 'session_out' => $tmp]);
        $sp = json_decode((string) @file_get_contents($tmp), true);
        check('"offer band CODE": preview ON → OFF', str_contains($h, 'Change this?') && str_contains($h, $C('USED')) && str_contains($h, '<s>ON 🟢</s>'));
        page(['admin' => $super, 'post' => ['act' => 'yes'], 'es_prop' => $sp]);
        check('"Yes" switches it OFF, audited offer.toggle', (int) Database::scalar('SELECT is_active FROM coupons WHERE id = :i', ['i' => $fixId], 1) === 0
            && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'offer.toggle' AND entity_id = :c", ['s' => $startId, 'c' => $C('USED')]));

        // an offers-only manager (coupons.edit, no settings): the command box is open and an offer can be made
        $h = page(['admin' => $offersOnly]);
        check('offers-only manager: the AI box is open (coupons.edit)', preg_match('/<input id="esCmd"[^>]*>/', $h, $m) === 1 && !str_contains($m[0], 'disabled'));
        $h  = page(['admin' => $offersOnly, 'post' => ['act' => 'cmd', 'cmd' => 'naya coupon ' . $C('OM') . ' ₹50 off 50 jana'], 'session_out' => $tmp]);
        $sp = json_decode((string) @file_get_contents($tmp), true);
        check('offers-only manager: a proposal', str_contains($h, 'Change this?') && ($sp['ops'][0]['type'] ?? '') === 'coupon');
        page(['admin' => $offersOnly, 'post' => ['act' => 'yes'], 'es_prop' => $sp]);
        $row = Database::fetch('SELECT is_active, auto_apply, discount_type, discount_value, usage_limit FROM coupons WHERE code = :c', ['c' => $C('OM')]);
        check('offers-only manager: "Yes" makes it OFF', $row !== null && (int) $row['is_active'] === 0 && (int) $row['auto_apply'] === 0 && $row['discount_type'] === 'flat' && (int) $row['usage_limit'] === 50);
        $h = page(['admin' => $offersOnly, 'post' => ['act' => 'toggle', 'key' => 'agent_followups_on']]);
        check('offers-only manager may not flip a switch', !Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.easy' AND entity_id = 'agent_followups_on'", ['s' => $startId]));

        // an operations login (routes / schedules, coupons.view only) may command but not make an offer
        $h = page(['admin' => $opsOnly, 'post' => ['act' => 'cmd', 'cmd' => 'coupon ' . $C('OP') . ' 10% max 200']]);
        check('operations login: an offer is "not allowed"', str_contains($h, 'Not allowed for your login') && str_contains($h, 'coupon') && !str_contains($h, 'Change this?'));

        // the new switches save from the page (settings.easy), and back
        $was = AiOps::easyCurrent('agent_followups_on');
        page(['admin' => $super, 'post' => ['act' => 'toggle', 'key' => 'agent_followups_on']]);
        check('one tap flips agent_followups_on (settings.easy)', (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'agent_followups_on'", [], '') === ($was === '1' ? '0' : '1')
            && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.easy' AND entity_id = 'agent_followups_on'", ['s' => $startId]));
        $grp = (string) Database::scalar("SELECT sgroup FROM settings WHERE skey = 'agent_followups_on'", [], '');
        check('agent_followups_on lives in the agent group', $grp === 'agent', $grp);
        page(['admin' => $super, 'post' => ['act' => 'setnum', 'key' => 'helpdesk_cancel_escalate_inr', 'value' => '2500']]);
        check('cancel follow-up ₹2,500 saved', (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'helpdesk_cancel_escalate_inr'", [], '') === '2500');
        $h = page(['admin' => $super, 'post' => ['act' => 'setnum', 'key' => 'helpdesk_cancel_escalate_inr', 'value' => '200000']]);
        check('cancel follow-up ₹2,00,000 refused (0 – 1,00,000)', str_contains($h, 'Not a valid value') && (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'helpdesk_cancel_escalate_inr'", [], '') === '2500');
        $h  = page(['admin' => $super, 'post' => ['act' => 'cmd', 'cmd' => 'cancel followup 0'], 'session_out' => $tmp]);
        check('"cancel followup 0" by sentence: 2500 → 0 · off', str_contains($h, 'Change this?') && str_contains($h, '0 · बन्द · off'));
    }

    /* ================= 3. the in-page bubble over HTTP (optional) ================= */
    $base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
    if ($base === '' || !function_exists('curl_init')) {
        echo "  SKIP  HTTP part (set SHG_TEST_BASE and load curl to run admin/api/ai-settings.php for real)\n";
    } else {
        $jar  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u11-y2-jar-' . getmypid() . '.txt';
        @unlink($jar);
        $http = static function (string $url, array $opt = []) use ($base, $jar): array {
            $ch = curl_init($base . $url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
            if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
            if (isset($opt['json'])) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CSRF-Token: ' . ($opt['csrf'] ?? '')]);
            }
            $raw  = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hl   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            return ['code' => $code, 'body' => substr($raw, $hl), 'headers' => substr($raw, 0, $hl)];
        };
        $csrfFrom = static function (string $h): string {
            if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
            return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) === 1 ? $m[1] : '';
        };
        $user = 'u11_y2_boss_' . strtolower($tag);
        $pw   = 'U11y2Boss!' . $tag;
        $boss = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => 'U11 Y2 Boss',
            'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
        require_once INCLUDE_PATH . '/quickunlock.php';
        QuickUnlock::set($boss, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
        try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
        $login = $http('/admin/login.php?classic=1&other=1');
        $r = $http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => $csrfFrom($login['body']), 'classic' => '1', 'username' => $user, 'password' => $pw]]);
        check('HTTP: super-admin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
        $pg   = $http('/admin/easy-settings.php');
        $tok  = $csrfFrom($pg['body']);
        check('HTTP: easy-settings page 200 with a CSRF token', $pg['code'] === 200 && $tok !== '', 'HTTP ' . $pg['code']);
        $r  = $http('/admin/api/ai-settings.php', ['json' => ['cmd' => 'coupon ' . $C('HT') . ' 5% max 100 ' . $say($d10) . ' to ' . $say($d20)], 'csrf' => $tok]);
        $j  = json_decode($r['body'], true);
        $rows = implode(' | ', array_map(static fn(array $x): string => $x['what'] . ' ' . $x['new'], (array) ($j['changes'] ?? [])));
        check('HTTP bubble: a proposal with "created OFF", nothing written', $r['code'] === 200 && is_string($j['proposal_id'] ?? null) && str_contains($rows, $C('HT')) && str_contains($rows, 'created OFF')
            && !Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $C('HT')]), $r['code'] . ' ' . mb_substr($r['body'], 0, 300));
        $r  = $http('/admin/api/ai-settings.php', ['json' => ['confirm' => (string) ($j['proposal_id'] ?? '')], 'csrf' => $tok]);
        $j2 = json_decode($r['body'], true);
        $row = Database::fetch('SELECT is_active, auto_apply, valid_from, valid_until FROM coupons WHERE code = :c', ['c' => $C('HT')]);
        check('HTTP bubble: confirm makes it OFF, never auto-applied', ($j2['success'] ?? false) === true && $row !== null && (int) $row['is_active'] === 0 && (int) $row['auto_apply'] === 0
            && $row['valid_from'] === $d10 && $row['valid_until'] === $d20, mb_substr($r['body'], 0, 300));
        $r  = $http('/admin/api/ai-settings.php', ['json' => ['cmd' => 'offer on ' . $C('HT')], 'csrf' => $tok]);
        $j3 = json_decode($r['body'], true);
        check('HTTP bubble: "offer on CODE" is refused with the note', is_array($j3) && array_key_exists('proposal_id', $j3) && $j3['proposal_id'] === null
            && (int) Database::scalar('SELECT is_active FROM coupons WHERE code = :c', ['c' => $C('HT')], 1) === 0 && str_contains((string) ($j3['message'] ?? ''), 'never switches an offer ON'), $r['code'] . ' ' . mb_substr($r['body'], 0, 300));
        @unlink($jar);
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$cleanup();
echo "\n  u11-Y2-aioffers-test: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
