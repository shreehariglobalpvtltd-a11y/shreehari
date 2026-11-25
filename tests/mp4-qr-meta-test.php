<?php
/**
 * =====================================================================
 *  mp4-qr-meta-test.php — the payment QR library, the Google client JSON
 *  and the WhatsApp ticket path after the 25 Nov Meta diagnosis (mp4).
 *
 *  Owner: "qr code haru sab upload garne system bandinius, admin panel baata
 *  update garn milos, bank ko gmail ni setup garn milos admin panel batoi,
 *  meta suspition kine hok yr ticket aautio ko mildeu ytei anusar".
 *
 *   M  before the migration the old single QR still prints; the migration
 *      creates pay_qr_codes and copies nepal_esewa_qr (+ id / name) in as the
 *      NP default - twice applied = one row; importLegacy is idempotent
 *   Q  QR CRUD: re-encoded PNG upload (bad file refused), add / edit / default
 *      (one per country) / replace picture (old file removed) / delete (file
 *      removed), nepal_esewa_qr follows the NP default
 *   D  desk override: the issuing desk's own QR (the frozen counter_code, else
 *      the seller's profile desk - one rule for the ticket and pay.php's
 *      SELECT * row) prints, else the NP default; a desk QR never becomes the
 *      country default; ONE resolver (PayQr::resolve) for picture + payee + kind;
 *      Ticket::paymentQr + the pay page carry that row's picture, payee and
 *      wallet word ("Global IME:", never "eSewa:" for another wallet); an
 *      inactive desk QR falls back; a default switched off / moved to India
 *      stops printing (nepal_esewa_qr cleared)
 *   P  admin/payment-center.php: both tabs show "🔳 QR कोडहरू", the grid, which
 *      one prints on tickets, the JSON upload box; default / edit / delete posts
 *      need CSRF + super-admin and are audited
 *   G  GoogleOAuth::parseClientJson: web + installed, bad JSON, missing secret,
 *      redirect URI not in the file -> warning
 *   W  WhatsApp (fake HTTP, nothing leaves this machine): free picture inside
 *      the 24 h window, outside it the template in the EXACT language the sync
 *      stored (en_US), a 132001 refusal -> the rescue template, then no template
 *      at all -> the ticket waits (wa.me), a permanently failed message is never
 *      re-queued, 131042 holds every template (free still goes) until
 *      "Meta fixed", 131070 stops free messages to that number, ONE office
 *      alert per parked booking, the retry cron parks these codes
 *   H  admin/wa-templates.php "Meta स्वास्थ्य" card: counts by code, the red
 *      billing notice, the Nepali owner steps, "Meta ठीक भयो" (super-admin,
 *      CSRF, audited)
 *
 *    php -c .claude/php-dev.ini tests/mp4-qr-meta-test.php
 *  Creates its own bookings (phone 90000441xx), desk MP4T, QR rows (label
 *  "MP4TEST …") and admins, and removes them and every setting / kv it touched.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);

/* ---------- child: one admin page as a signed-in admin ---------- */
if (($argv[1] ?? '') === '--page') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $script = basename((string) ($cfg['script'] ?? 'payment-center.php'));
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.74';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $script;
    $_SERVER['REQUEST_URI'] = '/admin/' . $script;
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once $ROOT . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time()];
    if (isset($cfg['post']) && is_array($cfg['post'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $cfg['post'] + (empty($cfg['nocsrf']) ? [CSRF_TOKEN_NAME => Security::csrfToken()] : [CSRF_TOKEN_NAME => 'forged-token']);
    }
    register_shutdown_function(static function (): void { echo "\n<!--HTTP:" . http_response_code() . "-->\n"; });
    require $ROOT . '/admin/' . $script;
    exit(0);
}

define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/payqr.php';
require_once INCLUDE_PATH . '/payadapter.php';
require_once INCLUDE_PATH . '/googleoauth.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/wasend.php';
require_once INCLUDE_PATH . '/watplmeta.php';
require_once INCLUDE_PATH . '/wahealth.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  FAIL  UNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    if (function_exists('mp4_cleanup')) { try { mp4_cleanup(); } catch (Throwable $x) {} }
    echo "\n  0 passed, 1 failed\n";
    exit(1);
});

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 500) : '') . "\n"; }
}

const MP4_PHONE = '90000441';     // + two digits
const MP4_DESK  = 'MP4T';
$tag = strtoupper(bin2hex(random_bytes(3)));

/* ---------- what this run touches, restored at the end ---------- */
$keep = [];
function setKeep(string $k, string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, 'payments', false);
    Settings::flush();
}
foreach (['nepal_esewa_qr', 'nepal_esewa_id', 'nepal_esewa_name', PayQr::LEGACY_FLAG, 'counter_locations', 'ticket_identity_changed_at'] as $k) {
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
}
$kvKeep = Database::fetchAll("SELECT * FROM kv_store WHERE kscope IN ('wa_tpl_meta', 'wa_health') OR (kscope = 'global' AND kkey = 'wa_meta.billing_ok_at')");
$hadTable = Database::fetch("SHOW TABLES LIKE 'pay_qr_codes'") !== null;
$tableKeep = $hadTable ? Database::fetchAll('SELECT * FROM pay_qr_codes') : [];
$files = [];
$madeAdmins = [];
$bookingIds = [];
$bellFloor  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
$auditFloor = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

function mp4_cleanup(): void {
    global $keep, $kvKeep, $hadTable, $tableKeep, $files, $madeAdmins, $bookingIds, $bellFloor, $auditFloor;
    WaSend::$http = null; WaSend::$creds = null; WaSend::$window = null; WaSend::$clock = null; WaSend::$aisensy = null; WaSend::$sleeper = null;
    WaRoute::$transport = null;
    foreach (Database::fetchAll("SELECT file FROM pay_qr_codes WHERE label LIKE 'MP4TEST%'") as $r) { $files[] = ROOT_PATH . '/' . $r['file']; }
    try { Database::run("DELETE FROM pay_qr_codes WHERE label LIKE 'MP4TEST%'"); } catch (Throwable $e) {}
    if ($hadTable) {
        Database::run('DELETE FROM pay_qr_codes');
        foreach ($tableKeep as $r) { Database::insert('pay_qr_codes', $r); }
    }
    foreach (array_unique($files) as $f) { if (is_file($f)) { @unlink($f); } }
    foreach ($bookingIds as $b) {
        foreach (['message_logs', 'wa_outbox', 'notifications', 'wa_ticket_queue'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => $b]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => $b]);
    }
    try { Database::run('DELETE FROM wa_outbox WHERE to_number LIKE :p', ['p' => '91' . MP4_PHONE . '%']); } catch (Throwable $e) {}
    Database::run('DELETE FROM message_logs WHERE to_number LIKE :p', ['p' => '%' . MP4_PHONE . '%']);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE :p", ['p' => '91' . MP4_PHONE . '%']);
    Database::run("DELETE FROM kv_store WHERE kscope IN ('wa_tpl_meta', 'wa_health') OR (kscope = 'global' AND kkey = 'wa_meta.billing_ok_at')");
    foreach ($kvKeep as $r) { Database::insert('kv_store', $r); }
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_outbox' AND kkey LIKE :d", ['d' => date('Y-m-d') . '%']);
    Database::run('DELETE FROM notifications WHERE id > :f AND (title LIKE :t OR body LIKE :p)', ['f' => $bellFloor, 't' => '%SHG-2099-%', 'p' => '%' . MP4_PHONE . '%']);
    foreach ($bookingIds as $b) { try { Database::run('DELETE FROM automation_log WHERE dedupe_key = :k OR dedupe_key LIKE :p', ['k' => 'alert:wa_perm:' . $b, 'p' => 'alert:wa_perm:' . $b . ':%']); } catch (Throwable $e) {} }
    Database::run("DELETE FROM audit_logs WHERE id > :f AND (action LIKE 'pay_qr.%' OR action IN ('whatsapp.meta_fixed', 'whatsapp.phone_health', 'settings.google_oauth'))", ['f' => $auditFloor]);
    try { Database::delete('counter_locations', 'code = :c', ['c' => MP4_DESK]); } catch (Throwable $e) {}
    foreach ($madeAdmins as $a) { try { Database::delete('admin_profiles', 'admin_id = :i', ['i' => $a]); } catch (Throwable $e) {} Database::delete('admins', 'id = :i', ['i' => $a]); }
    foreach ($keep as $k => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
        if (is_array($row)) { Database::insert('settings', $row); }
    }
    Settings::flush();
    CounterDesk::flush();
    PayQr::reset();
}

$applyMigration = static function () use ($ROOT): void {
    $sql = (string) file_get_contents($ROOT . '/database/upgrade-2026-11-25-mp4-qr-library.sql');
    $sql = (string) preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $stmt) {
        Database::pdo()->exec(rtrim($stmt, ';'));
    }
    PayQr::reset();
};
$png = static function (int $w, int $h, int $r = 0, string $type = 'png') use (&$files): string {
    $p = sys_get_temp_dir() . '/mp4qr-' . bin2hex(random_bytes(4)) . '.' . $type;
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, 80, 160));
    $type === 'jpg' ? imagejpeg($im, $p, 80) : imagepng($im, $p);
    imagedestroy($im);
    $files[] = $p;
    return $p;
};
$same = static fn(string $a, string $b): bool => $a !== '' && $b !== '' && realpath($a) === realpath($b);

try {
    /* ================= M. before / after the migration ================= */
    echo "\n-- M migration + the old single QR\n";
    Database::pdo()->exec('DROP TABLE IF EXISTS pay_qr_codes');
    PayQr::reset();
    $qrDir = PayQr::dir();
    $legacy = 'uploads/payqr/esewa-npj-mp4test' . strtolower($tag) . '.png';
    $im = imagecreatetruecolor(12, 12); imagepng($im, ROOT_PATH . '/' . $legacy); imagedestroy($im);
    $files[] = ROOT_PATH . '/' . $legacy;
    setKeep('nepal_esewa_qr', $legacy, 'string');
    setKeep('nepal_esewa_id', '9800000777', 'string');
    setKeep('nepal_esewa_name', 'MP4 Branch', 'string');
    Database::delete('settings', 'skey = :k', ['k' => PayQr::LEGACY_FLAG]);
    Settings::flush();
    check('M before the migration: no table, the old single QR still prints (Ticket::esewaQrFile)',
        !PayQr::ready() && $same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $legacy) && PayQr::pick(['counter_code' => 'X'], 'NP') === null);
    $applyMigration();
    $applyMigration();   // twice = still one copy
    $rows = PayQr::all('NP');
    check('M the migration (applied twice) creates pay_qr_codes and copies the old QR ONCE as the NP default, payee = the id only (the name in the label)',
        PayQr::ready() && count($rows) === 1 && (string) $rows[0]['file'] === $legacy && (int) $rows[0]['is_default'] === 1
        && (string) $rows[0]['payee'] === '9800000777' && str_contains((string) $rows[0]['label'], 'MP4 Branch') && (string) $rows[0]['kind'] === 'esewa', json_encode($rows, JSON_UNESCAPED_UNICODE));
    $legacyId = (int) ($rows[0]['id'] ?? 0);
    Database::run('UPDATE pay_qr_codes SET label = :l WHERE id = :i', ['l' => 'MP4TEST legacy', 'i' => $legacyId]);
    PayQr::reset();
    check('M PayQr::importLegacy finds the file already in the library: nothing added, the flag set', PayQr::importLegacy(0) === null && count(PayQr::all('NP')) === 1 && Settings::getBool(PayQr::LEGACY_FLAG, false));
    check('M the ticket reads the library now (same file)', $same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $legacy));
    /* the PHP import (first page view, no migration copy yet) writes the same: payee = id only */
    Database::run('DELETE FROM pay_qr_codes WHERE id = :i', ['i' => $legacyId]);
    Database::delete('settings', 'skey = :k', ['k' => PayQr::LEGACY_FLAG]);
    Settings::flush();
    PayQr::reset();
    $impId = (int) (PayQr::importLegacy(0) ?? 0);
    $imp = PayQr::get($impId) ?? [];
    check('M PayQr::importLegacy (no migration copy): the NP default, payee = the id only, name in the label',
        $impId > 0 && (int) ($imp['is_default'] ?? 0) === 1 && (string) ($imp['payee'] ?? '') === '9800000777' && str_contains((string) ($imp['label'] ?? ''), 'MP4 Branch'), json_encode($imp, JSON_UNESCAPED_UNICODE));
    $legacyId = $impId;
    Database::run('UPDATE pay_qr_codes SET label = :l WHERE id = :i', ['l' => 'MP4TEST legacy', 'i' => $legacyId]);
    PayQr::reset();

    /* ================= Q. CRUD ================= */
    echo "\n-- Q QR CRUD\n";
    $bad = sys_get_temp_dir() . '/mp4qr-bad-' . bin2hex(random_bytes(3)) . '.png';
    file_put_contents($bad, 'not an image at all');
    $files[] = $bad;
    $err = '';
    try { PayQr::storeImageFile($bad); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
    check('Q a text file named .png is refused (PNG / JPG / WebP)', str_contains($err, 'PNG / JPG / WebP'), $err);
    $f1 = PayQr::storeImageFile($png(1200, 600, 10, 'jpg'));
    $abs1 = ROOT_PATH . '/' . $f1;
    $files[] = $abs1;
    $info = @getimagesize($abs1);
    check('Q a JPG upload is re-encoded to a PNG under uploads/payqr with a random name, at most 900 px',
        preg_match('#^uploads/payqr/qr-[a-f0-9]{16}\.png$#', $f1) === 1 && is_array($info) && $info['mime'] === 'image/png' && max($info[0], $info[1]) === 900, $f1 . ' ' . json_encode($info));
    [$fld, $errs] = PayQr::clean(['country' => 'NP', 'kind' => 'fonepay', 'label' => 'MP4TEST Fonepay', 'payee' => '9811122233', 'is_active' => '1']);
    $fpId = PayQr::create($fld, $f1, 0);
    check('Q add a Fonepay QR (not the default: the country has one)', $errs === [] && $fpId > 0 && (int) (PayQr::get($fpId)['is_default'] ?? 1) === 0);
    [, $errs2] = PayQr::clean(['country' => 'XX', 'kind' => 'paypal', 'desk_code' => 'NOPE9']);
    check('Q bad country / kind / unknown desk are refused with reasons', count($errs2) === 3, json_encode($errs2, JSON_UNESCAPED_UNICODE));
    PayQr::setDefault($fpId);
    $np = PayQr::all('NP');
    check('Q "make default": one default per country, nepal_esewa_qr follows the NP default',
        count(array_filter($np, static fn($r) => (int) $r['is_default'] === 1)) === 1 && (int) PayQr::defaultFor('NP')['id'] === $fpId
        && Settings::getString('nepal_esewa_qr', '') === $f1);
    [$fld2] = PayQr::clean(['country' => 'NP', 'kind' => 'gibl', 'label' => 'MP4TEST GIBL', 'payee' => '0123 4567 8901', 'is_active' => '1', 'sort' => '5']);
    $chg = PayQr::update($fpId, $fld2);
    $rowE = PayQr::get($fpId) ?? [];
    check('Q edit: kind / label / payee / sort changed and listed', in_array('kind', $chg, true) && in_array('payee', $chg, true) && (string) $rowE['kind'] === 'gibl' && (int) $rowE['sort'] === 5, json_encode($chg));
    $f2 = PayQr::storeImageFile($png(300, 300, 200));
    $files[] = ROOT_PATH . '/' . $f2;
    $old = PayQr::replaceFile($fpId, $f2);
    check('Q replace the picture: the old file is removed, the new one used, the setting follows', $old === $f1 && !is_file($abs1) && (string) (PayQr::get($fpId)['file'] ?? '') === $f2 && Settings::getString('nepal_esewa_qr', '') === $f2);
    [$fin] = PayQr::clean(['country' => 'IN', 'kind' => 'upi', 'label' => 'MP4TEST UPI', 'payee' => 'mp4@icici', 'is_active' => '1']);
    $f3 = PayQr::storeImageFile($png(200, 200, 90));
    $files[] = ROOT_PATH . '/' . $f3;
    $inId = PayQr::create($fin, $f3, 0);
    check('Q the first India QR becomes the IN default by itself; NP untouched', (int) (PayQr::defaultFor('IN')['id'] ?? 0) === $inId && (int) PayQr::defaultFor('NP')['id'] === $fpId);
    check('Q the IN default is only the static fallback: pay.php uses it when the UPI QR cannot be drawn', str_contains((string) file_get_contents($ROOT . '/pay.php'), "\$inQ   = PayQr::resolve(\$booking, 'IN');")
        && str_contains((string) file_get_contents($ROOT . '/pay.php'), "<?php if (\$upiQrStatic !== ''):")   // review fix: its own UPI id under the static picture
        && str_starts_with(PayQr::dataUri(PayQr::pick(null, 'IN')), 'data:image/png;base64,'));

    /* ================= D. desk override + the ticket ================= */
    echo "\n-- D desk QR on the ticket\n";
    CounterDesk::save(['code' => MP4_DESK, 'name' => 'MP4 Test Desk', 'country' => 'NP']);
    [$fd, $errD] = PayQr::clean(['country' => 'NP', 'kind' => 'esewa', 'label' => 'MP4TEST desk eSewa', 'payee' => '9844455566', 'desk_code' => strtolower(MP4_DESK), 'is_active' => '1']);
    $f4 = PayQr::storeImageFile($png(240, 240, 40));
    $files[] = ROOT_PATH . '/' . $f4;
    $deskId = PayQr::create($fd, $f4, 0);
    $deskAbs = ROOT_PATH . '/' . $f4;
    check('D a desk QR (desk code normalised to MP4T) is not the default', $errD === [] && (string) (PayQr::get($deskId)['desk_code'] ?? '') === MP4_DESK && (int) PayQr::defaultFor('NP')['id'] === $fpId);
    check('D a ticket cut at MP4T prints the desk QR; another desk / online prints the NP default',
        $same(Ticket::esewaQrFile(['counter_code' => MP4_DESK]), $deskAbs) && $same(Ticket::esewaQrFile(['counter_code' => 'OTHER']), ROOT_PATH . '/' . $f2)
        && $same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $f2));
    /* ONE desk rule everywhere (review fix): counter_code frozen on the sale, else the seller's profile
       desk - the ticket (loadBooking joins agent_counter_code) and pay.php (SELECT * FROM bookings:
       only sold_by_admin_id) must land on the same desk */
    $seller = (int) Database::insert('admins', ['username' => 'mp4-seller-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'MP4 seller', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
    $madeAdmins[] = $seller;
    Database::insert('admin_profiles', ['admin_id' => $seller, 'counter_code' => MP4_DESK, 'counter_name' => 'MP4 Test Desk']);
    PayQr::reset();
    check('D no desk frozen on the sale: the seller\'s profile desk decides - from agent_counter_code (the ticket\'s row) AND from sold_by_admin_id (pay.php\'s SELECT * row) alike',
        PayQr::deskOf(['counter_code' => '', 'agent_counter_code' => MP4_DESK]) === MP4_DESK && PayQr::deskOf(['counter_code' => '', 'sold_by_admin_id' => $seller]) === MP4_DESK
        && $same(Ticket::esewaQrFile(['counter_code' => '', 'agent_counter_code' => MP4_DESK]), $deskAbs) && $same(Ticket::esewaQrFile(['counter_code' => '', 'sold_by_admin_id' => $seller]), $deskAbs));
    check('D the frozen desk wins over the seller\'s profile desk; no desk at all = the NP default',
        PayQr::deskOf(['counter_code' => 'OTHER', 'agent_counter_code' => MP4_DESK, 'sold_by_admin_id' => $seller]) === 'OTHER'
        && $same(Ticket::esewaQrFile(['counter_code' => 'OTHER', 'sold_by_admin_id' => $seller]), ROOT_PATH . '/' . $f2) && PayQr::deskOf(['counter_code' => '', 'sold_by_admin_id' => null]) === '');
    $errDef = '';
    try { PayQr::setDefault($deskId); } catch (RuntimeException $ex) { $errDef = $ex->getMessage(); }
    check('D a desk\'s own QR can never be made the country default (it would print on every desk)', $errDef !== '' && (int) PayQr::defaultFor('NP')['id'] === $fpId, $errDef);
    $fake = ['pnr' => 'SHG-2099-00001', 'status' => 'pending', 'total_amount' => 1000.0, 'fx_currency' => 'NPR', 'fx_total' => 1650.0, 'is_cod' => 0,
             'currency' => 'INR', 'counter_code' => MP4_DESK, 'entity' => 'NP'];
    $pq = Ticket::paymentQr($fake);
    check('D Ticket::paymentQr (NPR, money due): the desk QR picture + ITS payee printed beside it', ($pq['mode'] ?? '') === 'npr' && $same((string) $pq['esewaImg'], $deskAbs) && (string) $pq['esewa'] === '9844455566', json_encode($pq, JSON_UNESCAPED_UNICODE));
    $pq2 = Ticket::paymentQr(['counter_code' => ''] + $fake);
    check('D the same ticket from no desk: the NP default picture + its payee', $same((string) $pq2['esewaImg'], ROOT_PATH . '/' . $f2) && (string) $pq2['esewa'] === '0123 4567 8901', json_encode($pq2, JSON_UNESCAPED_UNICODE));
    check('D the wallet word printed before the id is the row\'s own: eSewa for the desk eSewa, "Global IME" for the GIBL default (never "eSewa: <bank no.>")',
        ($pq['esewaKind'] ?? '') === 'eSewa' && ($pq2['esewaKind'] ?? '') === 'Global IME' && ($pq2['qrKind'] ?? '') === 'Global IME'
        && str_contains((string) $pq2['caption'], 'Global IME') && !str_contains((string) $pq2['caption'], 'eSewa'), json_encode([$pq['esewaKind'] ?? null, $pq2['caption'] ?? null], JSON_UNESCAPED_UNICODE));
    $tsrc = (string) file_get_contents($ROOT . '/includes/ticket.php');
    check('D the PNG and the PDF print that word (no fixed "eSewa:" left)', str_contains($tsrc, "(\$payQ['esewaKind'] ?? 'eSewa') . ': '") && str_contains($tsrc, "(\$payPdf['esewaKind'] ?? 'eSewa') . ':'") && !str_contains($tsrc, "['eSewa:', 38.0, 7.0]"));
    $st = (new ManualQrAdapter())->start(['counter_code' => MP4_DESK] + $fake, 500.0);
    check('D the pay page (ManualQrAdapter) shows the same desk picture inline, with ITS payee (not nepal_esewa_id)',
        ($st['esewa']['qr'] ?? '') === PayQr::dataUri(PayQr::get($deskId)) && ($st['esewa']['id'] ?? '') === '9844455566' && ($st['esewa']['kind'] ?? '') === 'eSewa', json_encode($st['esewa'] ?? null, JSON_UNESCAPED_UNICODE));
    $st2 = (new ManualQrAdapter())->start(['counter_code' => ''] + $fake, 500.0);
    check('D no desk: the pay page shows the NP default picture with the default\'s payee and wallet word (same as the ticket)',
        ($st2['esewa']['qr'] ?? '') === PayQr::dataUri(PayQr::get($fpId)) && ($st2['esewa']['id'] ?? '') === '0123 4567 8901' && ($st2['esewa']['kind'] ?? '') === 'Global IME' && ($st2['esewa']['name'] ?? 'x') === '', json_encode($st2['esewa'] ?? null, JSON_UNESCAPED_UNICODE));
    $psrc = (string) file_get_contents($ROOT . '/pay.php');
    check('D pay.php prints picture + payee + wallet word from the one resolver (ManualQrAdapter::nepalQr -> PayQr::resolve)',
        str_contains($psrc, 'ManualQrAdapter::nepalQr($booking, $esewaId,') && str_contains($psrc, "'id'   => \$npQ['payee'],") && str_contains($psrc, "'kind' => \$npQ['kind'],")
        && str_contains($psrc, "ManualQrAdapter::qrSrc(\$npQ['file'])") && str_contains($psrc, "\$e(\$esKind) . ' ID: <code>'") && !str_contains($psrc, 'PayQr::pick('));
    $rs = PayQr::resolve(['counter_code' => MP4_DESK], 'NP', '9811111111', 'Other wallet');
    check('D PayQr::resolve: a library row never borrows the caller\'s id / name (its own payee + kind)', $rs['source'] === 'desk' && $rs['payee'] === '9844455566' && $rs['name'] === '' && $rs['kind'] === 'eSewa' && $same($rs['file'], $deskAbs), json_encode($rs));
    /* a library QR typed without a payee: the picture only, never nepal_esewa_id beside it */
    PayQr::update($deskId, ['payee' => ''] + $fd);
    $pqN = Ticket::paymentQr($fake);
    $stN = (new ManualQrAdapter())->start(['counter_code' => MP4_DESK] + $fake, 500.0);
    check('D a desk QR without a payee: ticket + pay page show its picture with NO id (not nepal_esewa_id 9800000777)',
        $same((string) $pqN['esewaImg'], $deskAbs) && (string) $pqN['esewa'] === '' && ($stN['esewa']['id'] ?? 'x') === '' && ($stN['esewa']['qr'] ?? '') === PayQr::dataUri(PayQr::get($deskId)),
        json_encode([$pqN['esewa'] ?? null, $stN['esewa']['id'] ?? null]));
    PayQr::update($deskId, $fd);
    PayQr::update($deskId, ['is_active' => 0] + $fd);
    check('D the desk QR switched off -> that desk\'s ticket falls back to the NP default', $same(Ticket::esewaQrFile(['counter_code' => MP4_DESK]), ROOT_PATH . '/' . $f2));
    /* review fix: the NP default switched off (or moved to India) must stop printing - the old
       setting nepal_esewa_qr may not keep pointing at it */
    $fpRow = PayQr::get($fpId) ?? [];
    $fpFields = ['country' => 'NP', 'kind' => (string) $fpRow['kind'], 'label' => (string) $fpRow['label'], 'payee' => (string) $fpRow['payee'],
                 'desk_code' => null, 'is_active' => 1, 'sort' => (int) $fpRow['sort']];
    $fpUri = PayQr::dataUri($fpRow);
    PayQr::update($fpId, ['is_active' => 0] + $fpFields);
    check('D the NP default switched off -> it is no longer the default, nepal_esewa_qr cleared, no ticket / pay page prints it',
        (int) (PayQr::get($fpId)['is_default'] ?? 1) === 0 && PayQr::defaultFor('NP') === null && Settings::getString('nepal_esewa_qr', 'x') === ''
        && !$same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $f2) && !$same(Ticket::esewaQrFile(['counter_code' => 'OTHER']), ROOT_PATH . '/' . $f2)
        && ManualQrAdapter::esewaQrSrc() !== $fpUri, Settings::getString('nepal_esewa_qr', ''));
    setKeep('nepal_esewa_qr', $f2, 'string');   // an old reader wrote the switched-off file back into the setting
    PayQr::reset();
    check('D even if nepal_esewa_qr points at that switched-off library QR again, the old fallback never prints it',
        !$same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $f2) && !$same(PayQr::legacyFile(), ROOT_PATH . '/' . $f2));
    PayQr::update($fpId, $fpFields);
    PayQr::setDefault($fpId);
    check('D switched on + made default again -> it prints again', $same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $f2) && Settings::getString('nepal_esewa_qr', '') === $f2);
    PayQr::update($fpId, ['country' => 'IN'] + $fpFields);
    check('D the NP default moved to India -> Nepal tickets stop printing it (setting cleared)',
        PayQr::defaultFor('NP') === null && Settings::getString('nepal_esewa_qr', 'x') === '' && !$same(Ticket::esewaQrFile(), ROOT_PATH . '/' . $f2));
    PayQr::update($fpId, $fpFields);
    PayQr::setDefault($fpId);
    check('D (back to Nepal and the default for the next checks)', (int) (PayQr::defaultFor('NP')['id'] ?? 0) === $fpId && Settings::getString('nepal_esewa_qr', '') === $f2);
    PayQr::update($fpId, ['desk_code' => MP4_DESK] + $fpFields);
    check('D the NP default given to one desk -> it stops being the country default (setting cleared)',
        (int) (PayQr::get($fpId)['is_default'] ?? 1) === 0 && PayQr::defaultFor('NP') === null && Settings::getString('nepal_esewa_qr', 'x') === '');
    PayQr::update($fpId, $fpFields);
    [$fdIn] = PayQr::clean(['country' => 'NP', 'kind' => 'khalti', 'label' => 'MP4TEST first desk Khalti', 'payee' => '9855500011', 'desk_code' => MP4_DESK, 'is_active' => '1']);
    $f5 = PayQr::storeImageFile($png(120, 120, 70));
    $files[] = ROOT_PATH . '/' . $f5;
    $firstDesk = PayQr::create($fdIn, $f5, 0, true);
    check('D with no NP default, a new DESK QR (even with "make default" ticked) does not become the country default',
        (int) (PayQr::get($firstDesk)['is_default'] ?? 1) === 0 && PayQr::defaultFor('NP') === null);
    PayQr::delete($firstDesk);
    PayQr::setDefault($fpId);
    check('D (default restored)', (int) (PayQr::defaultFor('NP')['id'] ?? 0) === $fpId && Settings::getString('nepal_esewa_qr', '') === $f2 && !is_file(ROOT_PATH . '/' . $f5));
    /* review fix: the clean v2 ticket (the renderer that runs, ticket_clean_v2_on) prints the row's own wallet word too */
    $csrc = (string) file_get_contents($ROOT . '/includes/ticketclean.php');
    check('D the clean v2 pay card prints the QR row\'s wallet word (no fixed "eSewa: " / "eSewa / Fonepay ID:" left); cached tickets redraw on deploy (staleBefore reads ticketclean.php\'s own mtime; the 25 Nov stamp stays)',
        str_contains($csrc, "\$npKind . ': ' . self::latin(") && str_contains($csrc, "\$npHead . ' ID: '") && str_contains($csrc, "(string) (\$payQ['esewaKind'] ?? 'eSewa') . ': '")
        && !str_contains($csrc, "'eSewa: ' . self::latin(") && !str_contains($csrc, "'eSewa / Fonepay ID: '")
        && str_contains((string) file_get_contents($ROOT . '/includes/ticket.php'), "(int) @filemtime(__DIR__ . '/ticketclean.php'));"));
    /* review fix: the app (boot + track.php) gets the resolver's picture URL + ITS payee + kind */
    $pi = PayQr::publicInfo(null);
    check('D PayQr::publicInfo (app boot / track.php): the NP default picture as a site URL with its payee and wallet word',
        $pi['url'] === '/' . $f2 && $pi['payee'] === '0123 4567 8901' && $pi['kind'] === 'Global IME' && $pi['source'] === 'default', json_encode($pi));
    $isrc = (string) file_get_contents($ROOT . '/index.php');
    $jsrc = (string) file_get_contents($ROOT . '/assets/js/07-checkout.js');
    check('D the app ticket pairs the picture and the id from ONE source (boot = PayQr::publicInfo; a sale\'s own npPay from track.php wins)',
        str_contains($isrc, 'PayQr::publicInfo(null)') && str_contains($isrc, "\$boot['settings']['nepal_pay_qr_kind']")
        && str_contains((string) file_get_contents($ROOT . '/api/track.php'), "\$payload['npPay']") && str_contains($jsrc, 'const npP = (b.npPay && b.npPay.url) ? b.npPay : null;')
        && str_contains($jsrc, 'esewa: npId, esewaKind:'));
    /* review fix: an NP default eSewa row with no id of its own keeps printing nepal_esewa_id (as before
       the library); saving a new id there follows into that row; another wallet's row never borrows it */
    $lgRow = PayQr::get($legacyId) ?? [];
    $lgFields = ['country' => 'NP', 'kind' => 'esewa', 'label' => 'MP4TEST legacy', 'payee' => '', 'desk_code' => null, 'is_active' => 1, 'sort' => 0];
    PayQr::update($legacyId, $lgFields);
    PayQr::setDefault($legacyId);
    $rL = PayQr::resolve(null, 'NP');
    check('D the NP default eSewa QR typed without an id: tickets keep printing the eSewa id from settings (nepal_esewa_id)',
        $lgRow !== [] && $rL['source'] === 'default' && $rL['payee'] === '9800000777' && $rL['kind'] === 'eSewa' && $rL['name'] === 'MP4 Branch', json_encode($rL));
    setKeep('nepal_esewa_id', '9800000888', 'string');
    $fol = PayQr::followLegacyId('9800000777', '9800000888');
    check('D the eSewa id changed (Payment Management / Owner Setup): the default eSewa row follows it, so the saved id is the printed one',
        $fol && (string) (PayQr::get($legacyId)['payee'] ?? '') === '9800000888' && PayQr::resolve(null, 'NP')['payee'] === '9800000888');
    PayQr::setDefault($fpId);
    check('D the GIBL default (another wallet, its own payee) never follows / borrows the eSewa id',
        !PayQr::followLegacyId('9800000888', '9800000999') && (string) (PayQr::get($fpId)['payee'] ?? '') === '0123 4567 8901' && PayQr::resolve(null, 'NP')['payee'] === '0123 4567 8901');
    setKeep('nepal_esewa_id', '9800000777', 'string');
    PayQr::update($legacyId, ['payee' => '9800000777'] + $lgFields);
    check('D (GIBL default restored)', (int) (PayQr::defaultFor('NP')['id'] ?? 0) === $fpId && Settings::getString('nepal_esewa_qr', '') === $f2);
    $pcsrc = (string) file_get_contents($ROOT . '/admin/payment-center.php');
    check('D Payment Management: np_save makes the library follow the id, the ID card says what tickets print; Owner Setup does the same',
        str_contains($pcsrc, "PayQr::followLegacyId(\$oldNpId, Settings::getString('nepal_esewa_id', ''))") && str_contains($pcsrc, "\$npNow = PayQr::resolve(null, 'NP');")
        && str_contains((string) file_get_contents($ROOT . '/admin/owner-setup.php'), 'PayQr::followLegacyId($oldNpId,'));
    /* review fix: WhatsApp texts follow the same resolver; pay.php / smartpay never name the wrong wallet */
    $nsrc = (string) file_get_contents($ROOT . '/includes/notify.php');
    $wbsrc = (string) file_get_contents($ROOT . '/includes/wabooking.php');
    $spsrc = (string) file_get_contents($ROOT . '/smartpay.php');
    check('D the WhatsApp booking texts (Notify::bookingPlaced, WaBooking) print the resolver\'s payee + wallet word',
        str_contains($nsrc, "PayQr::resolve(\$booking, 'NP', \$esewa)") && str_contains($nsrc, '$text .= $npKind . ": " . $esewa')
        && str_contains($wbsrc, "PayQr::resolve(\$b !== [] ? \$b : null, 'NP', \$esewa)") && str_contains($wbsrc, "\$kind . ': ' . \$esewa"));
    check('D pay.php + smartpay name the row\'s wallet in the note / radio / copy button, and the Nepal box shows for a library QR without a settings id',
        str_contains($psrc, "\$e(\$esKind) . ' बाट पठाउँदा") && !str_contains($psrc, 'Put \' . $e($pnr) . \' in the eSewa remarks') && str_contains($psrc, "> <?= \$e((string) (\$esewa['kind'] ?? 'eSewa')) ?></label>")
        && str_contains($psrc, "if (\$esewaId !== '' || \$npLibRow) {") && str_contains($spsrc, 'data-w="<?= $e($npW) ?>"') && !str_contains($spsrc, '>Copy eSewa ID<'));
    $stL = (new ManualQrAdapter())->start(['counter_code' => ''] + $fake, 500.0);
    check('D ManualQrAdapter: a Nepal sale with the library QR shows it (same as the ticket)', ($stL['esewa']['kind'] ?? '') === 'Global IME' && ($stL['esewa']['id'] ?? '') === '0123 4567 8901');

    /* ================= G. Google JSON ================= */
    echo "\n-- G Google client_secret JSON\n";
    $red = 'https://www.shreehariglobal.network/admin/gmail-oauth.php';
    $web = json_encode(['web' => ['client_id' => '1234-abcd.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-abcdefghijkl', 'redirect_uris' => [$red], 'project_id' => 'x']]);
    $g = GoogleOAuth::parseClientJson((string) $web, $red);
    check('G a "web" client file: id + secret read, our redirect URI found, no warning', $g['ok'] && $g['type'] === 'web' && $g['client_id'] === '1234-abcd.apps.googleusercontent.com' && $g['client_secret'] === 'GOCSPX-abcdefghijkl' && $g['redirect_ok'] && $g['warn'] === '');
    $g = GoogleOAuth::parseClientJson("\xEF\xBB\xBF" . (string) $web, $red . '/');
    check('G a BOM and a trailing slash do not matter', $g['ok'] && $g['redirect_ok']);
    $g = GoogleOAuth::parseClientJson((string) json_encode(['web' => ['client_id' => '1234-abcd.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-abcdefghijkl', 'redirect_uris' => ['https://other.example/cb']]]), $red);
    check('G our redirect URI missing from the file -> ok but a warning with the exact URI to add', $g['ok'] && !$g['redirect_ok'] && str_contains($g['warn'], $red) && str_contains($g['warn'], 'other.example'));
    $g = GoogleOAuth::parseClientJson((string) json_encode(['installed' => ['client_id' => '99-x1y2.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-zzzzzzzzzzzz']]), $red);
    check('G an "installed" (desktop) client is read but warned: make a Web application client', $g['ok'] && $g['type'] === 'installed' && str_contains($g['warn'], 'Web application'));
    check('G not JSON -> refused', !GoogleOAuth::parseClientJson('{"web": nope', $red)['ok']);
    check('G no web / installed -> refused', !GoogleOAuth::parseClientJson('{"type":"service_account"}', $red)['ok']);
    check('G no client_secret -> refused', !GoogleOAuth::parseClientJson((string) json_encode(['web' => ['client_id' => '1234-abcd.apps.googleusercontent.com']]), $red)['ok']);

    /* ================= P. admin/payment-center.php ================= */
    echo "\n-- P admin/payment-center.php\n";
    $ini = php_ini_loaded_file();
    $mkAdmin = static function (string $role) use (&$madeAdmins, $tag): int {
        $id = (int) Database::insert('admins', ['username' => 'mp4-' . $role . '-' . strtolower($tag), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'full_name' => 'MP4 ' . $role, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
        $madeAdmins[] = $id;
        return $id;
    };
    $page = static function (string $script, int $admin, array $get = [], ?array $post = null, bool $nocsrf = false) use ($ini): string {
        $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' --page '
            . escapeshellarg(base64_encode((string) json_encode(['script' => $script, 'admin' => $admin, 'get' => $get, 'post' => $post, 'nocsrf' => $nocsrf]))) . ' 2>&1';
        return (string) shell_exec($cmd);
    };
    /* a PHP diagnostic ("Warning: … on line N"), not the page's own "Notice text" label */
    $warn = static fn(string $h): bool => preg_match('/((Warning|Notice|Deprecated|Fatal error): .{0,300} on line \d+)|Uncaught /', $h) === 1;
    $audits = static fn(string $act): int => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE id > :f AND action = :a', ['f' => $auditFloor, 'a' => $act], 0);
    $SUP = $mkAdmin('superadmin');
    $MGR = $mkAdmin('manager');
    PayQr::update($deskId, ['is_active' => 1] + $fd);
    $h = $page('payment-center.php', $SUP, ['tab' => 'np']);
    check('P Nepal tab: "🔳 QR कोडहरू" card, every NP QR in the grid with its label, the one that prints on tickets marked, the add form, no PHP warning',
        !$warn($h) && str_contains($h, '🔳 QR कोडहरू · Payment QR codes') && str_contains($h, 'नेपाली QR') && str_contains($h, 'MP4TEST GIBL') && str_contains($h, 'MP4TEST desk eSewa')
        && str_contains($h, '🎫 टिकटमा छापिन्छ · prints on tickets') && str_contains($h, 'value="qr_add"') && str_contains($h, '🏪 ' . MP4_DESK)
        && preg_match('/id="pc-qr-row-' . $fpId . '".{0,4000}?prints on tickets/s', $h) === 1, substr((string) preg_replace('/\s+/', ' ', strip_tags($h)), 0, 300));
    $deskBlock = preg_match('/id="pc-qr-row-' . $deskId . '"(.*?)(id="pc-qr-row-|➕ QR थप्नुहोस्)/s', $h, $dm) === 1 ? $dm[1] : '';
    check('P a desk QR card offers no "make default" (button or radio) and says it is that desk\'s only',
        $deskBlock !== '' && !str_contains($deskBlock, 'value="qr_default"') && !str_contains($deskBlock, 'name="is_default"') && str_contains($deskBlock, 'a desk QR is never the country default'));
    check('P a missing picture placeholder is no wider than a QR (360 px)', str_contains($h, '.pc-qr-miss{width:100%;max-width:220px;'));
    check('P the Google setup box offers the client_secret JSON upload (manual fields kept)', str_contains($h, 'value="google_json"') && str_contains($h, 'name="google_json"') && str_contains($h, 'name="google_oauth_client_id"'));
    [$fiD] = PayQr::clean(['country' => 'IN', 'kind' => 'upi', 'label' => 'MP4TEST desk UPI', 'payee' => 'mp4desk@icici', 'desk_code' => MP4_DESK, 'is_active' => '1']);
    $f7 = PayQr::storeImageFile($png(140, 140, 120));
    $files[] = ROOT_PATH . '/' . $f7;
    $inDesk = PayQr::create($fiD, $f7, 0);
    $h = $page('payment-center.php', $SUP, ['tab' => 'in']);
    check('P India tab: its own QR card with the UPI QR, the dynamic-UPI-first note', !$warn($h) && str_contains($h, 'id="pc-qr-IN"') && str_contains($h, 'MP4TEST UPI') && str_contains($h, 'UPI QR रकम + PNR सहित'));
    check('P India tab: a desk UPI QR is labelled as the pay-page fallback, never "on that desk\'s tickets"',
        str_contains($h, '🏪 ' . MP4_DESK . ' · pay पेज (UPI QR नबने मात्र) · pay page fallback') && !str_contains($h, MP4_DESK . ' को टिकटमा'));
    $inR = PayQr::resolve(['counter_code' => MP4_DESK], 'IN');
    check('P the pay page\'s India fallback follows the same desk rule (desk UPI picture + its own VPA)', $inR['source'] === 'desk' && $inR['payee'] === 'mp4desk@icici'
        && PayQr::resolve(['counter_code' => ''], 'IN')['payee'] === 'mp4@icici');
    PayQr::delete($inDesk);
    $h = $page('payment-center.php', $MGR, ['tab' => 'np']);
    check('P a manager sees no QR card (super-admin only)', !str_contains($h, '🔳 QR कोडहरू'));
    $page('payment-center.php', $MGR, ['tab' => 'np'], ['act' => 'qr_default', 'tab' => 'np', 'id' => (string) $deskId]);
    $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'qr_default', 'tab' => 'np', 'id' => (string) $deskId], true);
    PayQr::reset();
    check('P "make default" by a manager or with a forged CSRF token -> nothing changes', (int) PayQr::defaultFor('NP')['id'] === $fpId && $audits('pay_qr.default') === 0);
    $h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'qr_default', 'tab' => 'np', 'id' => (string) $deskId]);
    PayQr::reset();
    check('P "make default" on a DESK QR (super-admin, CSRF) -> refused with the reason, the NP default unchanged, not audited',
        (int) PayQr::defaultFor('NP')['id'] === $fpId && $audits('pay_qr.default') === 0 && Settings::getString('nepal_esewa_qr', '') === $f2 && str_contains($h, 'cannot be the country default'), substr(strip_tags($h), 0, 200));
    [$fq] = PayQr::clean(['country' => 'NP', 'kind' => 'khalti', 'label' => 'MP4TEST Khalti', 'payee' => '9866600022', 'is_active' => '1']);
    $f6 = PayQr::storeImageFile($png(150, 150, 30));
    $files[] = ROOT_PATH . '/' . $f6;
    $khId = PayQr::create($fq, $f6, 0);
    $h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'qr_default', 'tab' => 'np', 'id' => (string) $khId]);
    PayQr::reset();
    check('P "make default" (super-admin, CSRF) on a QR for any desk -> the NP default, audited, setting follows', (int) PayQr::defaultFor('NP')['id'] === $khId && $audits('pay_qr.default') === 1
        && Settings::getString('nepal_esewa_qr', '') === $f6 && str_contains($h, 'now the default'), substr(strip_tags($h), 0, 200));
    PayQr::delete($khId);
    PayQr::setDefault($fpId);
    PayQr::reset();
    $h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'qr_edit', 'tab' => 'np', 'id' => (string) $fpId, 'label' => 'MP4TEST GIBL main', 'kind' => 'gibl', 'payee' => '0123 4567 8901', 'desk_code' => '', 'sort' => '5', 'is_active' => '1']);
    PayQr::reset();
    check('P edit through the page: label saved, audited', (string) (PayQr::get($fpId)['label'] ?? '') === 'MP4TEST GIBL main' && $audits('pay_qr.edit') === 1);
    $h = $page('payment-center.php', $SUP, ['tab' => 'np'], ['act' => 'qr_delete', 'tab' => 'np', 'id' => (string) $fpId]);
    PayQr::reset();
    check('P delete through the page: row gone, its picture file removed, audited', PayQr::get($fpId) === null && !is_file(ROOT_PATH . '/' . $f2) && $audits('pay_qr.delete') === 1 && str_contains($h, 'deleted'));
    PayQr::delete($deskId);
    check('P deleting the NP default leaves no default: the ticket falls back to the old single setting path', PayQr::defaultFor('NP') === null && !is_file($deskAbs));

    /* ================= W. WhatsApp ticket path ================= */
    echo "\n-- W WhatsApp ticket path (fake HTTP)\n";
    foreach (['wa_cloud_fallback_on' => ['1', 'bool'], 'wa_cloud_daily_cap' => ['100', 'int'], 'wa_cloud_gap_sec' => ['1', 'int'], 'wa_paid_daily_limit' => ['', 'string'],
              'whatsapp_template_name' => ['mp4_ticket_tpl', 'string'], 'whatsapp_template_lang' => ['en', 'string'], 'wa_tpl_meta_on' => ['1', 'bool'],
              'wa_ticket_rescue_on' => ['1', 'bool'], 'wa_meta_health_on' => ['1', 'bool'], 'wa_billing_hold_on' => ['1', 'bool'], 'wa_thread_block_on' => ['1', 'bool'],
              'wa_tpl_refused_hours' => ['6', 'int'], 'wa_global_daily_cap' => ['0', 'int']] as $k => [$v, $t]) {
        setKeep($k, $v, $t);
    }
    Database::run("DELETE FROM kv_store WHERE kscope IN ('wa_tpl_meta', 'wa_health') OR (kscope = 'global' AND kkey = 'wa_meta.billing_ok_at')");
    $kvTpl = static function (string $name, string $lang): void {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_tpl_meta', :k, :v, 'test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
            ['k' => 'status.' . $name, 'v' => json_encode(['status' => 'APPROVED', 'category' => 'UTILITY', 'language' => $lang, 'id' => 'id_' . $name, 'at' => time()])]);
    };
    $kvTpl('mp4_ticket_tpl', 'en_US');
    $kvTpl('shg_booking_received', 'en');
    $T = (float) time();
    $CALLS = []; $NEXT = []; $OPEN = []; $N = 0;
    WaSend::$clock   = static function () use (&$T): float { return $T; };
    WaSend::$sleeper = static function (float $s) use (&$T): void { $T += $s; };
    WaSend::$aisensy = false;
    WaSend::$creds   = ['token' => 'FAKE-MP4-TOKEN', 'phone_id' => '100000000003595', 'base' => 'https://graph.example.test/v25.0'];
    WaSend::$window  = static function (string $intl) use (&$OPEN): bool { return isset($OPEN[$intl]); };
    WaSend::$http    = static function (string $url, array $body) use (&$CALLS, &$NEXT, &$N): array {
        $CALLS[] = $body;
        if ($NEXT !== []) { return array_shift($NEXT); }
        $N++;
        return ['http' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.MP4T' . $N . '.' . mt_rand(1000, 9999)]]])];
    };
    $meta = static fn(int $http, int $code, string $msg): array => ['http' => $http, 'body' => json_encode(['error' => ['code' => $code, 'message' => $msg]])];
    $bid = (int) Database::insert('bookings', ['pnr' => 'SHG-2099-' . substr($tag, 0, 5), 'contact_phone' => MP4_PHONE . '00', 'contact_country_code' => '91',
        'status' => 'confirmed', 'total_amount' => 2800, 'source' => 'web']);
    $bookingIds[] = $bid;
    $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $bid], '');
    $num = static fn(string $two): string => '91' . MP4_PHONE . $two;
    $send = static function (string $to) use ($pnr, &$T): array {
        $T += 700;   // a new 10-minute resend bucket
        return WaSend::ticket($pnr, ['to' => $to, 'purpose' => 'ticket_resend', 'admin_id' => 1]);
    };

    $to1 = $num('01'); $OPEN[$to1] = true;
    $CALLS = [];
    $r = $send($to1);
    check('W inside the 24 h window: the ticket goes as a FREE picture with the PNR + ticket link in the caption',
        $r['state'] === 'sent' && count($CALLS) === 1 && ($CALLS[0]['type'] ?? '') === 'image' && str_contains((string) ($CALLS[0]['image']['caption'] ?? ''), $pnr)
        && str_contains((string) ($CALLS[0]['image']['caption'] ?? ''), 'download-ticket'), json_encode($r) . ' ' . json_encode($CALLS[0] ?? null));

    $to2 = $num('02');
    $CALLS = [];
    $r = $send($to2);
    check('W outside the window: the approved ticket template in the EXACT language Meta holds it in (en_US from the sync, not "en")',
        $r['state'] === 'sent' && ($CALLS[0]['type'] ?? '') === 'template' && ($CALLS[0]['template']['name'] ?? '') === 'mp4_ticket_tpl'
        && ($CALLS[0]['template']['language']['code'] ?? '') === 'en_US', json_encode($CALLS[0] ?? null));

    $to3 = $num('03');
    $CALLS = [];
    $NEXT = [$meta(404, 132001, 'Template name does not exist in the translation'), $meta(404, 132001, 'does not exist')];
    $r = $send($to3);
    check('W 132001: the rescue template (shg_booking_received, its synced language) is tried once, both refused -> failed, the template marked refused',
        $r['state'] === 'failed' && count($CALLS) === 2 && ($CALLS[1]['template']['name'] ?? '') === 'shg_booking_received' && ($CALLS[1]['template']['language']['code'] ?? '') === 'en'
        && WaTplMeta::refusedRecently('mp4_ticket_tpl') && str_contains((string) $r['error'], '132001'), json_encode($r));
    $CALLS = [];
    $r = WaSend::ticket($pnr, ['to' => $to3, 'purpose' => 'ticket_resend', 'admin_id' => 1]);
    check('W the same message again (same bucket): failed for good on a Meta code -> NOT re-queued, no HTTP call', $r['state'] === 'failed' && $CALLS === [], json_encode($r));
    /* review fix (px3 rescue regression): a template refused a moment ago must NOT leave the next
       tickets without any template - the configured one is tried and its refusal falls to the
       rescue template (shg_booking_received), which delivers on live. */
    $to4 = $num('04');
    $CALLS = [];
    $NEXT = [$meta(404, 132001, 'Template name does not exist in the translation')];
    $r = $send($to4);
    check('W the template refused recently, the next ticket outside the window: still tried, and the 132001 rescue (shg_booking_received) delivers it - no silent wait',
        $r['state'] === 'sent' && count($CALLS) === 2 && ($CALLS[0]['template']['name'] ?? '') === 'mp4_ticket_tpl' && ($CALLS[1]['template']['name'] ?? '') === 'shg_booking_received',
        json_encode([$r, $CALLS], JSON_UNESCAPED_UNICODE));
    check('W WaSend::ticketTemplate has no "refused recently -> no template" early return', !str_contains((string) file_get_contents($ROOT . '/includes/wasend.php'), "WaTplMeta::refusedRecently(\$set)"));
    /* review fix: a 132001 / 131042 failure can still go FREE once the passenger opens the 24 h window */
    $OPEN[$to3] = true;
    $CALLS = [];
    $r = WaSend::ticket($pnr, ['to' => $to3, 'purpose' => 'ticket_resend', 'admin_id' => 1]);
    check('W the 132001-failed ticket again after the passenger wrote in (window open): re-queued and sent as the FREE picture',
        $r['state'] !== 'failed' && ($CALLS[0]['type'] ?? '') === 'image', json_encode([$r, $CALLS[0]['type'] ?? null]));
    unset($OPEN[$to3]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta' AND kkey = 'refused.mp4_ticket_tpl'");

    echo "\n-- W 131042 billing hold\n";
    check('W no hold before any 131042', WaHealth::billingHold() === '' && WaRoute::paidRefusal() === '');
    Notify::logOutbound($num('05'), 'Template shg_booking_received (Meta)', 'failed', ['provider' => 'cloud_api', 'bookingId' => $bid, 'purpose' => 'booking_received',
        'error' => 'WhatsApp failed (code 131042) - payment method problem on the WhatsApp Business Account']);
    $hold = WaHealth::billingHold();
    check('W one 131042 in the log -> "Meta billing missing" hold; WaRoute::paidRefusal holds every template', str_contains($hold, 'Meta billing missing (held 131042)') && str_contains(WaRoute::paidRefusal(), '131042'), $hold);
    check('W the hold is for OUR Meta WABA only: AiSensy (own wallet / BSP) is not held - aisensyRefusal uses paidRefusal(false)',
        WaRoute::paidRefusal(false) === '' && str_contains((string) file_get_contents($ROOT . '/includes/waroute.php'), "self::isLoginTemplate(\$templateName) ? '' : self::paidRefusal(false);"));
    $to6 = $num('06');
    $CALLS = [];
    $r = $send($to6);
    $w6 = Database::fetch('SELECT state, error FROM wa_outbox WHERE to_number = :t ORDER BY id DESC LIMIT 1', ['t' => $to6]) ?? [];
    check('W during the hold a ticket outside the window is NOT sent as a template (it would fail at delivery) - it waits, no Meta call',
        $r['state'] === 'waiting' && $CALLS === [] && str_contains((string) ($w6['error'] ?? ''), 'template held: Meta billing missing'), json_encode([$r, $w6]));
    $to7 = $num('07'); $OPEN[$to7] = true;
    $CALLS = [];
    $r = $send($to7);
    check('W during the hold a free message inside the window still goes (free messages are not billed)', $r['state'] === 'sent' && ($CALLS[0]['type'] ?? '') === 'image');
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', 'wa_meta.billing_ok_at', :v, 'test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => (string) (time() + 5)]);
    check('W a billable delivery reported by Meta after it ends the hold', WaHealth::billingHold() === '');
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'wa_meta.billing_ok_at'");
    check('W (without that proof the hold is back)', WaHealth::billingHold() !== '');
    WaHealth::markFixed();
    check('W "Meta ठीक भयो" (markFixed) clears the hold', WaHealth::billingHold() === '' && WaHealth::fixedAt() >= time() - 5);

    echo "\n-- W 131070 thread control\n";
    $to8 = $num('08'); $OPEN[$to8] = true;
    $CALLS = [];
    $NEXT = [$meta(400, 131070, 'Caller is not thread owner')];
    $r = $send($to8);
    check('W a free ticket refused with 131070 -> failed (not retried), the number is put on hold for free messages',
        $r['state'] === 'failed' && count($CALLS) === 1 && WaHealth::threadBlocked($to8) && !WaHealth::threadBlocked($to1), json_encode($r));
    $CALLS = [];
    $r = $send($to8);
    check('W the next ticket to that number skips the free message and goes on the template (never a second free refusal)',
        $r['state'] === 'sent' && count($CALLS) === 1 && ($CALLS[0]['type'] ?? '') === 'template', json_encode($CALLS));
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => $to8, 'v' => (string) time()]);
    $ROUTE = [];
    WaRoute::$transport = static function (string $to, string $text, ?string $img) use (&$ROUTE): array { $ROUTE[] = $to; return ['success' => true, 'message_id' => 'wamid.MP4R', 'error' => '']; };
    $fr = WaRoute::freeFirst($to8, 'hello', null, 'bot_reply');
    check('W WaRoute::freeFirst does not even try a free message to a 131070 number', $fr === null && $ROUTE === []);
    WaRoute::$transport = null;
    /* review fix: a send WE held is written "(held N)" - parked + no try used, and never renews the hold */
    $heldTxt = 'provider refused the send: Meta billing missing (held 131042) since 25 Nov 10:00 - template messages paused';
    check('W a held send: codeIn reads nothing (no self-renewing hold), heldCodeIn / parkCode read 131042 / 131070',
        WaHealth::codeIn($heldTxt) === 0 && WaHealth::heldCodeIn($heldTxt) === 131042 && WaHealth::parkCode($heldTxt) === 131042
        && WaHealth::parkCode('this number answered 131070 - another app owns the chat (held 131070); no free message for now') === 131070
        && WaHealth::parkCode('WhatsApp failed (code 132001) x') === 132001);
    $hl = Database::insert('message_logs', ['booking_id' => $bid, 'channel' => 'whatsapp', 'provider' => 'cloud_api', 'purpose' => 'ticket', 'to_number' => $num('00'),
        'status' => 'failed', 'body' => 'held', 'error' => $heldTxt, 'created_at' => date('Y-m-d H:i:s', time() + 1)]);
    check('W a held ticket row counts as parked (permanentSql) - the cron alerts the office instead of burning tries',
        Database::exists('SELECT 1 FROM message_logs m WHERE m.id = :i AND ' . WaHealth::permanentSql('m'), ['i' => $hl]));
    Database::delete('message_logs', 'id = :i', ['i' => $hl]);
    Notify::logOutbound($num('05'), 'held', 'failed', ['provider' => 'cloud_api', 'bookingId' => $bid, 'purpose' => 'ticket', 'error' => $heldTxt]);
    check('W logging a held send does not start / renew the billing hold', WaHealth::billingHold() === '');
    $cronSrc = (string) file_get_contents($ROOT . '/cron/whatsapp-retry.php');
    check('W the retry cron does not count held sends / 131042 as tries (while parking is on)', str_contains($cronSrc, "LIKE '%(held %'") && str_contains($cronSrc, '" . $heldNoTry . "'));
    $nSrc = (string) file_get_contents($ROOT . '/includes/notify.php');
    check('W Notify writes the 131070 hold with the "(held 131070)" marker', str_contains($nSrc, '(held 131070)'));
    check('W codeIn reads "(code N)" and "(#N)"', WaHealth::codeIn('Cloud API (outbox #4): (code 131070) Caller') === 131070 && WaHealth::codeIn('(#132001) does not exist') === 132001 && WaHealth::codeIn('no code') === 0);

    echo "\n-- W parked tickets: no loop, one office alert\n";
    $lid = Database::insert('message_logs', ['booking_id' => $bid, 'channel' => 'whatsapp', 'provider' => 'cloud_api', 'purpose' => 'ticket', 'to_number' => $num('00'),
        'status' => 'failed', 'body' => 'Template mp4_ticket_tpl (Meta)', 'error' => 'WhatsApp failed (code 131070) · Caller is not thread owner', 'created_at' => date('Y-m-d H:i:s', time() + 2)]);
    $isPerm = Database::exists('SELECT 1 FROM message_logs m WHERE m.id = :i AND ' . WaHealth::permanentSql('m') . ' AND m.created_at > :a', ['i' => $lid, 'a' => WaHealth::parkedAfter()]);
    check('W a 131070 ticket failure after the last "Meta fixed" counts as parked (permanentSql)', $isPerm);
    $bk = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]) ?? [];
    $a1 = WaHealth::officeAlert($bk, 131070);
    $a2 = WaHealth::officeAlert($bk, 131070);
    $bell = Database::fetch('SELECT * FROM notifications WHERE id > :f AND title LIKE :t ORDER BY id DESC LIMIT 1', ['f' => $bellFloor, 't' => '%' . $pnr . '%']) ?? [];
    $nBell = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE id > :f AND title LIKE :t', ['f' => $bellFloor, 't' => '%' . $pnr . '%'], 0);
    check('W ONE office alert per parked booking (asked twice): the reason, the ticket link and a wa.me link to the passenger, opens the booking',
        $a1 && !$a2 && $nBell === 1 && str_contains((string) ($bell['body'] ?? ''), '131070') && str_contains((string) ($bell['body'] ?? ''), 'https://wa.me/' . $num('00'))
        && str_contains((string) ($bell['body'] ?? ''), $pnr) && (string) ($bell['link'] ?? '') === '/admin/booking-view.php?pnr=' . rawurlencode($pnr), json_encode($bell, JSON_UNESCAPED_UNICODE));
    /* review fix: the wa.me link goes to the number the ticket was SENT to (message_logs.to_number) */
    $bkNp = ['id' => $bid, 'pnr' => $pnr, 'contact_phone' => MP4_PHONE . '00', 'contact_country_code' => null, 'to_number' => '977' . MP4_PHONE . '00'];
    $fx0 = WaHealth::fixedAt();
    WaHealth::$clock = static fn(): int => max(time(), $fx0 + 1);   // a later "Meta fixed" moment, never the same second
    WaHealth::markFixed();
    WaHealth::$clock = null;
    $a3 = WaHealth::officeAlert($bkNp, 132001);
    $bell3 = Database::fetch('SELECT * FROM notifications WHERE id > :f AND title LIKE :t ORDER BY id DESC LIMIT 1', ['f' => $bellFloor, 't' => '%' . $pnr . '%']) ?? [];
    check('W after "Meta fixed" a new failure gets a NEW alert, and its wa.me link is the number actually sent to (977…, never a +91 guess)',
        $a3 && str_contains((string) ($bell3['body'] ?? ''), 'https://wa.me/977' . MP4_PHONE . '00') && !str_contains((string) ($bell3['body'] ?? ''), 'https://wa.me/91' . MP4_PHONE . '00'),
        json_encode($bell3, JSON_UNESCAPED_UNICODE));
    $cron = (string) file_get_contents($ROOT . '/cron/whatsapp-retry.php');
    check('W the cron hands the sent-to number to the alert', str_contains($cron, 'm.error, m.to_number'));
    check('W the retry cron parks 131070 / 131042 / 132001 (no resend loop) and alerts the office BEFORE the sender brake can stop the run',
        str_contains($cron, '$alreadyHasTicket . $parkSql') && str_contains($cron, 'WaHealth::officeAlert(') && strpos($cron, 'WaHealth::officeAlert(') < strpos($cron, '$lastFail = Database::fetch('));
    WaHealth::markFixed();
    check('W after "Meta fixed" that failure is no longer parked (the retry gets one more go)',
        !Database::exists('SELECT 1 FROM message_logs m WHERE m.id = :i AND ' . WaHealth::permanentSql('m') . ' AND m.created_at > :a', ['i' => $lid, 'a' => date('Y-m-d H:i:s', time() + 60)]));

    /* ================= H. the health card ================= */
    echo "\n-- H admin/wa-templates.php Meta स्वास्थ्य\n";
    Notify::logOutbound($num('05'), 'Template shg_ticket_image (Meta)', 'failed', ['provider' => 'cloud_api', 'bookingId' => $bid, 'purpose' => 'ticket', 'error' => 'Meta template: (code 132001) Template name does not exist']);
    Notify::logOutbound($num('05'), 'x', 'failed', ['provider' => 'cloud_api', 'bookingId' => $bid, 'purpose' => 'ticket', 'error' => 'WhatsApp failed (code 131042) - payment method problem']);
    $stats = WaHealth::stats(7);
    check('H stats: failures by code (131070, 131042, 132001) and 132001 by template name', ($stats['codes'][131070]['n'] ?? 0) >= 1 && ($stats['codes'][131042]['n'] ?? 0) >= 1
        && ($stats['codes'][132001]['n'] ?? 0) >= 1 && ($stats['tpl132001']['shg_ticket_image'] ?? 0) >= 1, json_encode($stats));
    $h = $page('wa-templates.php', $SUP);
    check('H the card: title, the red billing notice, counts per code, template language vs what the code sends, the Nepali owner steps, the two buttons',
        !$warn($h) && str_contains($h, 'Meta स्वास्थ्य · WhatsApp health') && str_contains($h, 'Meta billing missing') && str_contains($h, '× 131070')
        && str_contains($h, 'mp4_ticket_tpl') && str_contains($h, 'en_US') && str_contains($h, 'Conversation routing') && str_contains($h, 'SHG Messaging API')
        && str_contains($h, 'value="meta_fixed"') && str_contains($h, 'value="meta_phone"'),
        'missing: ' . implode(' | ', array_filter(['Meta स्वास्थ्य · WhatsApp health', 'Meta billing missing', '× 131070', 'mp4_ticket_tpl', 'en_US', 'Conversation routing', 'SHG Messaging API', 'value="meta_fixed"', 'value="meta_phone"'],
            static fn(string $s): bool => !str_contains($h, $s))) . ($warn($h) && preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)[: ].{0,300}/', $h, $wm) === 1 ? ' + ' . strip_tags($wm[0]) : ''));
    $page('wa-templates.php', $MGR, [], ['action' => 'meta_fixed']);
    $page('wa-templates.php', $SUP, [], ['action' => 'meta_fixed'], true);
    check('H "Meta ठीक भयो" by a manager or with a forged CSRF token -> the hold stays', WaHealth::billingHold() !== '' && $audits('whatsapp.meta_fixed') === 0);
    $page('wa-templates.php', $SUP, [], ['action' => 'meta_fixed']);
    check('H "Meta ठीक भयो" (super-admin, CSRF) -> hold cleared, audited', WaHealth::billingHold() === '' && $audits('whatsapp.meta_fixed') === 1);
} finally {
    mp4_cleanup();
}

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
