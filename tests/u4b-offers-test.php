<?php
/**
 * =====================================================================
 *  u4b-offers-test.php — admin/offers.php (16 Nov 2026):
 *    P4-5b  route, per-mobile limit, first booking only on the form
 *    P4-5c  max uses; a used offer cannot be deleted (switch it OFF)
 *    P4-5g  read-only discount usage report by code / route / date + CSV
 *
 *  Database part only (shari_test; every coupon, booking and audit row it
 *  makes is deleted). Each page call runs in its own PHP process as a
 *  signed-in admin, like tests/ticket-requests-test.php.
 *
 *    php -c .claude/php-dev.ini tests/u4b-offers-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.78';
    $_SERVER['SCRIPT_NAME'] = '/admin/offers.php';
    $_SERVER['REQUEST_URI'] = '/admin/offers.php';
    $_GET  = (array) ($cfg['get'] ?? []);
    $post  = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/offers.php';
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}

echo "\n=== U4b offers form + usage report ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
$route = Database::fetch('SELECT id, from_city, to_city, route_code FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($super <= 0 || $route === null) {
    echo "  \033[33mSKIP\033[0m  needs an active super-admin and an active route\n";
    finish();
}
$hasFirst = Database::fetch("SHOW COLUMNS FROM coupons LIKE 'first_booking_only'") !== null;
$tag   = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$code  = 'U4BT' . $tag;
$code2 = 'U4BU' . $tag;
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$made = ['book' => [], 'admin' => []];

$cleanup = static function () use (&$made, $code, $code2, $startAudit): void {
    foreach ($made['book'] as $bid) {
        try { Database::delete('coupon_redemptions', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :b', ['b' => $bid]);
    }
    foreach ([$code, $code2] as $c) {
        Database::delete('coupons', 'code = :c', ['c' => $c]);
    }
    foreach ($made['admin'] as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $aid]);
    }
    Database::delete('audit_logs', "id > :s AND (entity_id IN (:a, :b) OR action = 'offer.report_export')", ['s' => $startAudit, 'a' => $code, 'b' => $code2]);
};
$coupon = static fn(string $c): ?array => Database::fetch('SELECT * FROM coupons WHERE code = :c', ['c' => $c]);

try {
    echo "-- The form (P4-5b / P4-5c)\n";
    $pg = child(['admin' => $super]);
    check('the form has Route, Per mobile and Max uses', str_contains($pg, 'name="route_id"') && str_contains($pg, 'name="per_user_limit"') && str_contains($pg, 'name="usage_limit"'));
    check('First booking only is offered when the column is there', $hasFirst === str_contains($pg, 'name="first_booking_only"'));
    check('the route list carries the active route', str_contains($pg, 'value="' . (int) $route['id'] . '"'));
    check('no PHP warning on the page', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $pg));

    $save = ['action' => 'save', 'id' => '0', 'code' => $code, 'title' => 'U4B test offer', 'discount_type' => 'flat', 'discount_value' => '100',
             'max_discount' => '', 'min_amount' => '0', 'valid_from' => '', 'valid_until' => '', 'min_hours_ahead' => '0',
             'route_id' => (string) (int) $route['id'], 'per_user_limit' => '0', 'usage_limit' => '50', 'first_booking_only' => '1', 'is_active' => '1'];
    $o = child(['admin' => $super, 'post' => $save, 'csrf' => 'bad']);
    check('a bad CSRF token saves nothing', str_contains($o, 'Session expired') && $coupon($code) === null);
    $o = child(['admin' => $super, 'post' => ['route_id' => '99999999'] + $save]);
    check('an unknown route is refused', str_contains($o, 'route was not found') && $coupon($code) === null);
    $o = child(['admin' => $super, 'post' => ['usage_limit' => '0'] + $save]);
    check('max uses 0 is refused (blank = no limit)', str_contains($o, 'Max uses must be 1 or more') && $coupon($code) === null);
    $o = child(['admin' => $super, 'post' => $save]);
    $row = $coupon($code);
    check('a new offer stores route, per-mobile 0 (no limit) and max uses 50',
        $row !== null && (int) $row['route_id'] === (int) $route['id'] && (int) $row['per_user_limit'] === 0 && (int) $row['usage_limit'] === 50, json_encode($row));
    check('auto-apply stays off when its box is not ticked', $row !== null && (int) $row['auto_apply'] === 0);
    if ($hasFirst) {
        check('first booking only is stored', $row !== null && (int) $row['first_booking_only'] === 1);
    }
    $aud = Database::fetch("SELECT * FROM audit_logs WHERE id > :s AND action = 'offer.create' AND entity_id = :c", ['s' => $startAudit, 'c' => $code]);
    check('the create is audited with its new values', $aud !== null && str_contains((string) ($aud['new_value'] ?? $aud['new_values'] ?? json_encode($aud)), '"usage_limit":50'), json_encode($aud));
    check('the list says the route, the per-mobile rule and first booking', str_contains($o, 'no per-mobile limit') && str_contains($o, 'रुट · ' . $route['from_city']));

    // edit: clear the route, blank max uses, per mobile 2, untick first booking
    $edit = ['id' => (string) (int) $row['id'], 'route_id' => '0', 'usage_limit' => '', 'per_user_limit' => '2'] + $save;
    unset($edit['first_booking_only']);
    child(['admin' => $super, 'post' => $edit]);
    $row = $coupon($code);
    check('an edit clears the route (all routes), max uses (no limit) and sets 2 per mobile',
        $row !== null && $row['route_id'] === null && $row['usage_limit'] === null && (int) $row['per_user_limit'] === 2, json_encode($row));
    if ($hasFirst) {
        check('unticked first booking is cleared', $row !== null && empty($row['first_booking_only']));
    }
    $aud = Database::fetch("SELECT * FROM audit_logs WHERE id > :s AND action = 'offer.update' AND entity_id = :c ORDER BY id DESC LIMIT 1", ['s' => $startAudit, 'c' => $code]);
    check('the edit is audited with old and new values', $aud !== null && str_contains(json_encode($aud) ?: '', 'usage_limit'), json_encode($aud));

    // a used offer: max uses may not go below the used count, and it cannot be deleted
    Database::update('coupons', ['used_count' => 3], 'code = :c', ['c' => $code]);
    $o = child(['admin' => $super, 'post' => ['usage_limit' => '2'] + $edit]);
    check('max uses below the used count (3) is refused', str_contains($o, 'already used 3') && $coupon($code)['usage_limit'] === null);
    $o = child(['admin' => $super, 'post' => ['action' => 'delete', 'id' => (string) (int) $row['id']]]);
    check('a used offer is not deleted — told to switch it OFF', str_contains($o, 'cannot be deleted') && str_contains($o, 'Switch it OFF') && $coupon($code) !== null);
    check('its row shows "used — OFF only" instead of Delete', str_contains($o, 'used — OFF only'));
    Database::update('coupons', ['used_count' => 0], 'code = :c', ['c' => $code]);
    $o = child(['admin' => $super, 'post' => ['action' => 'delete', 'id' => (string) (int) $row['id']]]);
    check('an unused offer is deleted, audited with its old row', $coupon($code) === null
        && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'offer.delete' AND entity_id = :c", ['s' => $startAudit, 'c' => $code], 0) === 1);

    $ctr = Database::insert('admins', ['username' => 'u4b-ctr-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'U4B Counter Test', 'role' => 'counter', 'is_active' => 1, 'must_change_pw' => 0]);
    $made['admin'][] = $ctr;
    $o = child(['admin' => $ctr, 'post' => $save]);
    check('a counter (no coupons.edit) cannot create an offer', $coupon($code) === null && (str_contains($o, 'Only a manager') || str_contains($o, 'not for your role')));

    echo "\n-- Usage report (P4-5g)\n";
    Database::insert('coupons', ['code' => $code2, 'title' => 'U4B report offer', 'discount_type' => 'flat', 'discount_value' => 150, 'is_active' => 1]);
    $mk = static function (string $sfx, array $extra) use (&$made, $tag): int {
        $id = Database::insert('bookings', $extra + ['pnr' => 'SHG-U4B-' . $tag . '-' . $sfx, 'contact_phone' => '9812345678', 'booking_mode' => 'sharing',
            'fare_per_seat' => 2500, 'base_total' => 2500, 'total_amount' => 2350, 'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
        $made['book'][] = $id;
        return $id;
    };
    $mk('A', ['coupon_code' => $code2, 'coupon_discount' => 150]);
    $mk('B', ['coupon_code' => $code2, 'coupon_discount' => 150, 'group_discount' => 125, 'total_amount' => 2225]);
    $mk('C', ['coupon_code' => $code2, 'coupon_discount' => 150, 'status' => 'cancelled']);   // left out
    $today = todayISO();
    $r = child(['admin' => $super, 'get' => ['rf' => $today, 'rt' => $today, 'rby' => 'code']]);
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    check('by code: the offer shows 2 bookings and ₹300 (the cancelled one is left out)',
        preg_match('/' . preg_quote($code2, '/') . '[^<]*<\/td>\s*<td[^>]*>2<\/td>\s*<td[^>]*>' . preg_quote($esc(inr(300.0)), '/') . '<\/td>/u', $r) === 1);
    check('by code: the group discount has its own row', str_contains($r, 'समूह छुट · GROUP'));
    check('no PHP warning on the report', !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $r));
    $rd = child(['admin' => $super, 'get' => ['rf' => $today, 'rt' => $today, 'rby' => 'date']]);
    check('by date: today is a row', str_contains($rd, $esc(formatDate($today, 'D, j M Y'))));
    $rr = child(['admin' => $super, 'get' => ['rf' => $today, 'rt' => $today, 'rby' => 'route']]);
    check('by route: a booking without a leg falls under "No route"', str_contains($rr, 'रुट छैन · No route'));
    $csv = child(['admin' => $super, 'get' => ['rf' => $today, 'rt' => $today, 'rby' => 'code', 'rcsv' => '1']]);
    check('CSV: header + the offer row (BOM, no page HTML)', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, '"Discount source",Bookings,"Discount (INR)"')
        && str_contains($csv, '"' . $code2 . ' — U4B report offer",2,300.00') && !str_contains($csv, '<html'), mb_substr($csv, 0, 300));
    check('the CSV download is audited', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'offer.report_export'", ['s' => $startAudit], 0) >= 1);
    $bad = child(['admin' => $super, 'get' => ['rf' => 'nonsense', 'rt' => ['x'], 'rby' => 'evil']]);
    check('junk dates / group-by fall back to this month by code, no 500', str_contains($bad, 'id="discount-report"') && !preg_match('/(Warning|Fatal error)/', $bad));
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}
check('cleanup left nothing behind', $coupon($code) === null && $coupon($code2) === null
    && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p', ['p' => 'SHG-U4B-' . $tag . '%'], 0) === 0);
finish();
