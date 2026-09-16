<?php
/**
 * =====================================================================
 *  preflight.php — "is this server actually ready to take money?"
 *
 *  Run this ON THE LIVE SERVER after uploading and after running the
 *  migrations. It checks the things that silently go wrong on a shared
 *  host: a migration that was skipped, a writable directory that is not,
 *  an installer that never locked itself, debug output left on, a config
 *  still pointing at the template values.
 *
 *  Two ways to run it:
 *
 *    CLI   php tests/preflight.php
 *    Web   https://yourdomain.com/tests/preflight.php
 *          (requires a signed-in superadmin — it reports configuration
 *           state, so it is never public)
 *
 *  Exit code 0 = ready. 1 = at least one FAIL. WARNs never fail the run;
 *  they are judgement calls the operator should read.
 *
 *  Read-only: it writes nothing except one temp file per writable-dir
 *  probe, which it deletes.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/* Web access is admin-only: the output names tables, paths and settings. */
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    Auth::requireAdmin('dashboard.view');
    header('Content-Type: text/plain; charset=utf-8');
}

$pass = 0; $warn = 0; $fail = 0;
$section = '';

function head(string $t): void { global $section; $section = $t; echo "\n== $t ==\n"; }
function ok(string $m, string $d = ''): void   { global $pass; $pass++; echo "  PASS  $m" . ($d ? " — $d" : '') . "\n"; }
function bad(string $m, string $d = ''): void  { global $fail; $fail++; echo "  FAIL  $m" . ($d ? " — $d" : '') . "\n"; }
function meh(string $m, string $d = ''): void  { global $warn; $warn++; echo "  WARN  $m" . ($d ? " — $d" : '') . "\n"; }
function check(bool $c, string $m, string $d = ''): void { $c ? ok($m, $d) : bad($m, $d); }

echo "S Hari Global — live preflight\n";
echo str_repeat('-', 60) . "\n";
echo 'Host: ' . ($_SERVER['HTTP_HOST'] ?? php_uname('n')) . "  ·  PHP " . PHP_VERSION . "  ·  " . date('c') . "\n";

/* =====================================================================
 *  1. Runtime
 * ===================================================================== */
head('Runtime');

check(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP >= 8.1', PHP_VERSION);

foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd'] as $ext) {
    check(extension_loaded($ext), "extension $ext");
}
// curl is optional — only the AI assistant needs it, and that degrades.
extension_loaded('curl')
    ? ok('extension curl', 'AI assistant + Twilio can make outbound calls')
    : meh('extension curl missing', 'Twilio WhatsApp/SMS and the AI assistant will not send');

/* Writable runtime directories — a read-only uploads/ breaks payment
   screenshots, and a read-only tickets/ breaks every PDF. */
foreach (['uploads', 'tickets', 'invoice', 'qr', 'logs', 'backup'] as $dir) {
    $p = ROOT_PATH . '/' . $dir;
    $writable = false;
    if (is_dir($p)) {
        $probe = $p . '/.preflight-' . bin2hex(random_bytes(4));
        $writable = @file_put_contents($probe, 'x') !== false;
        @unlink($probe);
    }
    check($writable, "$dir/ is writable");
}

/* =====================================================================
 *  2. Configuration
 * ===================================================================== */
head('Configuration');

check(APP_ENV === 'production', 'APP_ENV is production', APP_ENV);
check(!APP_DEBUG, 'APP_DEBUG is off', APP_DEBUG ? 'ON — stack traces are public!' : 'off');
check(defined('FORCE_HTTPS') && FORCE_HTTPS, 'FORCE_HTTPS is on');

// A config still holding the template placeholders means the wrong DB or
// wrong ticket links, and both fail silently until a customer complains.
check(!str_contains(DB_NAME, 'u000000000'), 'DB_NAME is not the template value', DB_NAME);
check(!str_contains(APP_URL, 'shariglobal.com') && !str_contains(APP_URL, 'localhost'),
    'APP_URL is not a placeholder', APP_URL);
check(str_starts_with(APP_URL, 'https://'), 'APP_URL is https', APP_URL);

if (!$isCli && isset($_SERVER['HTTP_HOST'])) {
    $confHost = parse_url(APP_URL, PHP_URL_HOST) ?: '';
    check($confHost === $_SERVER['HTTP_HOST'],
        'APP_URL host matches the host serving this request',
        "config=$confHost  request=" . $_SERVER['HTTP_HOST']
        . ($confHost !== $_SERVER['HTTP_HOST'] ? ' — ticket links and admin redirects will point elsewhere' : ''));
}

check(strlen(APP_KEY) >= 32 && !str_contains(APP_KEY, 'dev_key'), 'APP_KEY is a real secret');
check(defined('CRON_TOKEN') && strlen(CRON_TOKEN) >= 16, 'CRON_TOKEN is set');

/* config.local.php is the DEVELOPER override — bootstrap prefers it over
   config.php. Uploaded by accident, the live site quietly runs against a
   developer's database. */
is_file(CONFIG_PATH . '/config.local.php')
    ? bad('config/config.local.php must NOT exist on live', 'bootstrap prefers it over config.php — the site is using it right now')
    : ok('no config/config.local.php', 'production config is in use');

/* =====================================================================
 *  3. Security posture
 * ===================================================================== */
head('Security');

/* The installer rewrites config.php, re-imports schema+seed and creates a
   superadmin. It is gated ONLY by config/installed.lock. */
$installer = ROOT_PATH . '/install.php';
if (!is_file($installer)) {
    ok('install.php has been removed');
} elseif (is_file(CONFIG_PATH . '/installed.lock')) {
    meh('install.php is present but locked', 'safe for now — deleting it is safer still');
} else {
    bad('install.php is present and UNLOCKED',
        'anyone can re-run setup: overwrite config, re-import the database and create their own superadmin. Delete install.php now.');
}

check(is_file(ROOT_PATH . '/.htaccess'), '.htaccess is present', 'blocks app.template.html and *.sql from the web');

/* Admin accounts */
$admins = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE is_active = 1', [], 0);
check($admins > 0, 'at least one active admin account exists', $admins . ' active');
$supers = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role = 'superadmin' AND is_active = 1", [], 0);
check($supers > 0, 'at least one active superadmin', $supers . ' active');
$mustChange = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE must_change_pw = 1 AND is_active = 1', [], 0);
$mustChange === 0
    ? ok('no admin is still on a forced-change password')
    : meh("$mustChange admin(s) still flagged must_change_pw", 'have them sign in and set a real password');

/* =====================================================================
 *  4. Schema — every migration actually applied
 * ===================================================================== */
head('Migrations');

$tableExists = static function (string $t): bool {
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
        ['t' => $t], 0
    ) > 0;
};
$columnExists = static function (string $t, string $c): bool {
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c',
        ['t' => $t, 'c' => $c], 0
    ) > 0;
};
$settingExists = static function (string $k): bool {
    return Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]);
};

/* Read the admins.role ENUM first — the agent-role probe below closes over
   it, and an arrow function captures by value at the moment it is created,
   so querying it afterwards would leave that one probe testing an empty
   string. (Caught by this script's own run: 'agent-role not applied' next
   to 'ENUM keeps both values'.) Two migrations MODIFY this column, and a
   MODIFY that drops 'agent' orphans every counter-agent account. */
$roleEnum = (string) Database::scalar(
    "SELECT column_type FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'admins' AND column_name = 'role'", [], ''
);

/* One probe per migration — the thing that file, and only that file, adds. */
$migrations = [
    'seat-units'        => fn() => $tableExists('schedule_unit_locks'),
    'seat-blocks'       => fn() => $tableExists('seat_blocks'),
    'fleet-expand'      => fn() => Database::exists("SELECT 1 FROM routes WHERE route_code = 'r6'"),
    'return-fleet'      => fn() => Database::exists("SELECT 1 FROM routes WHERE route_code = 'r10'"),
    'pricing-direction' => fn() => str_contains((string) Database::scalar("SELECT svalue FROM settings WHERE skey='cabin_pricing'", [], ''), 'sharingByDir'),
    'enquiries'         => fn() => $tableExists('enquiries'),
    'twilio'            => fn() => $settingExists('twilio_account_sid'),
    'sms-gateway'       => fn() => $tableExists('message_logs') && $settingExists('twilio_sms_from'),
    'trip-lifecycle'    => fn() => $tableExists('trip_events') && $tableExists('trip_status'),
    'ticketno-official' => fn() => $tableExists('pnr_counters'),
    'agent-role'        => fn() => str_contains($roleEnum, "'agent'"),
    'agent-panel'       => fn() => $columnExists('bookings', 'sold_by_admin_id'),
    'agent-wallet'      => fn() => $tableExists('agent_ledger') && $tableExists('offline_tickets'),
    'agent-controls'    => fn() => $tableExists('agent_route_permissions') && $columnExists('admin_profiles', 'daily_booking_limit'),
    'admin-security'    => fn() => $tableExists('admin_login_events') && $tableExists('admin_devices'),
    'ai-assistant'      => fn() => $settingExists('anthropic_api_key'),
];

foreach ($migrations as $name => $probe) {
    check($probe(), "upgrade-2026-08-$name.sql applied");
}

/* Both role values must survive — see the LIVE-READY note about MODIFY
   replacing the whole ENUM definition. */
check(str_contains($roleEnum, "'agent'") && str_contains($roleEnum, "'official'"),
    "admins.role ENUM keeps both 'agent' and 'official'", $roleEnum);

/* =====================================================================
 *  5. Operational settings
 * ===================================================================== */
head('Settings');

check(!Settings::getBool('maintenance_mode', false), 'maintenance mode is OFF');
check(Settings::getString('upi_id', '') !== '', 'UPI id is set', Settings::getString('upi_id', '') ?: 'EMPTY — customers cannot pay');
check(Settings::getString('company_phone', '') !== '', 'public phone is set');

$sid  = Settings::getString('twilio_account_sid', '');
$tok  = Settings::getString('twilio_auth_token', '');
$waFm = Settings::getString('twilio_whatsapp_from', '');
$smFm = Settings::getString('twilio_sms_from', '');

if ($sid === '' || $tok === '') {
    meh('Twilio credentials not set', 'WhatsApp/SMS tickets will not send — run database/set-twilio-credentials.sql');
} else {
    ok('Twilio credentials set', substr($sid, 0, 4) . '… (' . strlen($sid) . ' chars)');
    $waFm !== '' ? ok('twilio_whatsapp_from set', $waFm)
                 : meh('twilio_whatsapp_from is EMPTY', 'WhatsApp ticket delivery is off until you fill this in Admin → Settings → Notify');
    $smFm !== '' ? ok('twilio_sms_from set', $smFm)
                 : meh('twilio_sms_from is EMPTY', 'SMS + OTP delivery is off until you fill this in');
}

Settings::getString('anthropic_api_key', '') !== ''
    ? ok('AI assistant key set', 'SHG Sahayak escalates unmatched questions')
    : meh('no AI assistant key', 'the chatbot stays rule-based — this is a valid choice, not an error');

/* Secrets must never be flagged public: is_public rows are served to every
   visitor in the boot payload. */
$leaky = Database::fetchAll(
    "SELECT skey FROM settings
      WHERE is_public = 1
        AND (skey LIKE '%token%' OR skey LIKE '%_key%' OR skey LIKE '%secret%'
          OR skey LIKE '%pass%' OR skey LIKE '%auth%' OR skey LIKE '%sid%')"
);
$leaky === []
    ? ok('no credential setting is marked public')
    : bad('credential settings exposed to the browser', implode(', ', pluck($leaky, 'skey')));

/* =====================================================================
 *  6. Data sanity
 * ===================================================================== */
head('Data');

$routes = (int) Database::scalar('SELECT COUNT(*) FROM routes WHERE is_active = 1', [], 0);
check($routes > 0, 'active routes exist', $routes . ' active');

$bothWays = (int) Database::scalar(
    "SELECT COUNT(DISTINCT CONCAT(from_city,'>',to_city)) FROM routes WHERE is_active = 1", [], 0);
$bothWays >= 2 ? ok('routes run in both directions', $bothWays . ' city pairs')
               : meh('only one direction is offered', $bothWays . ' city pair');

$bookings = (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0);
ok('bookings table reachable', $bookings . ' rows');

/* Anything stuck pending far past its hold window means cron/expire.php
   is not running. */
$stale = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings
      WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at < (NOW() - INTERVAL 1 DAY)", [], 0);
$stale === 0
    ? ok('no long-expired pending bookings', 'cron/expire.php looks alive')
    : meh("$stale pending booking(s) expired over a day ago", 'is the seat-hold expiry cron running?');

$codOwed = (float) Database::scalar(
    "SELECT COALESCE(SUM(b.total_amount),0) FROM bookings b
       JOIN payments p ON p.id = (SELECT id FROM payments WHERE booking_id = b.id ORDER BY id DESC LIMIT 1)
      WHERE b.is_cod = 1 AND p.status = 'cod_pending' AND b.status NOT IN ('cancelled','rejected')", [], 0);
ok('cash-on-delivery outstanding', inr($codOwed) . ' — settle from Admin → Verify Payments');

/* =====================================================================
 *  Verdict
 * ===================================================================== */
echo "\n" . str_repeat('-', 60) . "\n";
printf("PASS %d   WARN %d   FAIL %d\n", $pass, $warn, $fail);
echo $fail === 0
    ? ($warn === 0 ? "READY — nothing outstanding.\n"
                   : "READY — but read the WARNs above before taking bookings.\n")
    : "NOT READY — fix every FAIL before going live.\n";

if ($isCli) {
    exit($fail === 0 ? 0 : 1);
}
