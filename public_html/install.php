<?php
/**
 * =====================================================================
 *  install.php — one-time setup wizard for Hostinger / cPanel.
 *
 *  Run this once in the browser after uploading the files:
 *    1. Checks PHP version, extensions and writable folders.
 *    2. Takes your database details and the first admin account.
 *    3. Imports schema.sql + seed.sql, creates the superadmin,
 *       generates a strong APP_KEY and CRON_TOKEN, and writes
 *       config/config.php.
 *    4. Locks itself (config/installed.lock) so it cannot run again.
 *
 *  DELETE THIS FILE once the site is up. It refuses to run once the
 *  lock file exists, but removing it is cleaner still.
 * =====================================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root       = __DIR__;
$configDir  = $root . '/config';
$configFile = $configDir . '/config.php';
$lockFile   = $configDir . '/installed.lock';
$schemaFile = $root . '/database/schema.sql';
$seedFile   = $root . '/database/seed.sql';

$errors  = [];
$notices = [];
$done    = false;

/* ---------------------------------------------------------------------
 *  Already installed?  Refuse to run again.
 * ------------------------------------------------------------------- */
$alreadyInstalled = is_file($lockFile);

/* ---------------------------------------------------------------------
 *  Environment checks (shown on every load).
 * ------------------------------------------------------------------- */
$phpOk  = version_compare(PHP_VERSION, '8.1.0', '>=');
$extNeeded = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd'];
$extStatus = [];
foreach ($extNeeded as $ext) {
    $extStatus[$ext] = extension_loaded($ext);
}
$writable = [];
foreach (['config', 'uploads', 'tickets', 'invoice', 'qr', 'logs', 'backup'] as $dir) {
    $p = $root . '/' . $dir;
    if (!is_dir($p)) { @mkdir($p, 0755, true); }
    $writable[$dir] = is_dir($p) && is_writable($p);
}
$filesPresent = is_file($schemaFile) && is_file($seedFile);

$envReady = $phpOk && !in_array(false, $extStatus, true)
          && !in_array(false, $writable, true) && $filesPresent;

/* ---------------------------------------------------------------------
 *  Handle the submitted form.
 * ------------------------------------------------------------------- */
if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');

    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminName = trim((string) ($_POST['admin_name'] ?? 'Administrator'));
    $adminMail = trim((string) ($_POST['admin_mail'] ?? ''));

    if (!$envReady)          { $errors[] = 'Please resolve the environment checks above first.'; }
    if ($dbName === '')      { $errors[] = 'Database name is required.'; }
    if ($dbUser === '')      { $errors[] = 'Database user is required.'; }
    if ($appUrl === '' || !preg_match('#^https?://#', $appUrl)) { $errors[] = 'Enter the full site URL, e.g. https://yourdomain.com'; }
    if (strlen($adminUser) < 3) { $errors[] = 'Admin username must be at least 3 characters.'; }
    if (strlen($adminPass) < 8) { $errors[] = 'Admin password must be at least 8 characters.'; }

    $dsn  = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES       => true,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ];

    // A fresh connection per multi-statement file, so no pending result
    // set from one import can trip up the next.
    $connect = static fn(): PDO => new PDO($dsn, $dbUser, $dbPass, $opts);

    $pdo = null;
    if ($errors === []) {
        try {
            $pdo = $connect();
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database: ' . $e->getMessage();
        }
    }

    if ($errors === [] && $pdo instanceof PDO) {
        try {
            // Import schema, then seed — each on its own connection.
            $connect()->exec((string) file_get_contents($schemaFile));
            $connect()->exec((string) file_get_contents($seedFile));
            $pdo = $connect();

            // Create (or reset) the first superadmin.
            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 11]);
            $stmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, full_name, email, role, is_active)
                      VALUES (:u, :h, :n, :e, "superadmin", 1)
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                      full_name = VALUES(full_name), email = VALUES(email), is_active = 1'
            );
            $stmt->execute(['u' => $adminUser, 'h' => $hash, 'n' => $adminName, 'e' => $adminMail]);

            // Strong secrets.
            $appKey    = bin2hex(random_bytes(32));
            $cronToken = bin2hex(random_bytes(16));

            // Write config.php from the template.
            $config = build_config([
                'DB_HOST' => $dbHost, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass,
                'APP_URL' => $appUrl, 'APP_KEY' => $appKey, 'CRON_TOKEN' => $cronToken,
            ]);
            if (@file_put_contents($configFile, $config) === false) {
                $errors[] = 'Could not write config/config.php. Make the config folder writable (755) and retry.';
            } else {
                @chmod($configFile, 0644);
                @file_put_contents($lockFile, date('c') . " installed\n");
                $done = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}

/**
 * Produce a config.php with the collected values baked in.
 *
 * @param array<string,string> $v
 */
function build_config(array $v): string
{
    $q = static fn(string $s): string => str_replace("'", "\\'", $s);
    return <<<PHP
<?php
/**
 * Application configuration — generated by install.php.
 * Edit DB credentials or APP_URL here if they ever change. Changing
 * APP_KEY invalidates previously issued ticket QR codes.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) { http_response_code(403); exit('Forbidden'); }

/* 1. Database */
define('DB_HOST',    '{$q($v['DB_HOST'])}');
define('DB_NAME',    '{$q($v['DB_NAME'])}');
define('DB_USER',    '{$q($v['DB_USER'])}');
define('DB_PASS',    '{$q($v['DB_PASS'])}');
define('DB_CHARSET', 'utf8mb4');

/* 2. Application */
define('APP_NAME',  'S Hari Global Pvt Ltd');
define('APP_URL',   '{$q($v['APP_URL'])}');
define('APP_ENV',   'production');
define('APP_DEBUG', APP_ENV !== 'production');
define('APP_KEY',   '{$q($v['APP_KEY'])}');
define('APP_TIMEZONE', 'Asia/Kolkata');

/* 3. Paths (derived) */
define('ROOT_PATH',    dirname(__DIR__));
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDE_PATH', ROOT_PATH . '/includes');
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('TICKET_PATH',  ROOT_PATH . '/tickets');
define('QR_PATH',      ROOT_PATH . '/qr');
define('INVOICE_PATH', ROOT_PATH . '/invoice');
define('BACKUP_PATH',  ROOT_PATH . '/backup');
define('LOG_PATH',     ROOT_PATH . '/logs');
define('ASSET_PATH',   ROOT_PATH . '/assets');

/* 4. Session & security */
define('SESSION_NAME',      'SHGSESSID');
define('SESSION_LIFETIME',  60 * 60 * 3);
define('CSRF_TOKEN_NAME',   'shg_csrf');
define('ADMIN_SESSION_KEY', 'shg_admin');
define('USER_SESSION_KEY',  'shg_user');
define('FORCE_HTTPS', true);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MIN',  15);
define('OTP_LENGTH',         6);
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_RESEND_SECONDS', 60);

/* 5. Uploads */
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);
define('ALLOWED_UPLOAD_MIME', serialize(['image/jpeg','image/png','image/webp','application/pdf']));
define('ALLOWED_UPLOAD_EXT',  serialize(['jpg','jpeg','png','webp','pdf']));

/* 6. Business defaults */
define('DEFAULT_CURRENCY',  'INR');
define('NPR_PER_INR',       1.6);
define('SEAT_HOLD_MINUTES', 30);
define('MAX_SEATS_BOOKING', 6);
define('PNR_PREFIX',        'SHG');

/* 7. Cron security */
define('CRON_TOKEN', '{$q($v['CRON_TOKEN'])}');
PHP;
}

function yn(bool $ok): string
{
    return $ok
        ? '<span style="color:#0a6b3b;font-weight:700">✓ OK</span>'
        : '<span style="color:#b02a2a;font-weight:700">✗ missing</span>';
}
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · S Hari Global</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:linear-gradient(135deg,#12264E,#2E5FA8);
     font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#1b2436;padding:24px}
.box{background:#fff;max-width:640px;margin:0 auto;border-radius:18px;padding:30px 32px;box-shadow:0 24px 60px rgba(0,0,0,.3)}
h1{font-size:22px;margin:0 0 4px;color:#12264E}.sub{color:#6b7688;margin:0 0 22px}
h2{font-size:15px;margin:22px 0 10px;color:#12264E;border-bottom:1px solid #eef1f6;padding-bottom:6px}
table{width:100%;border-collapse:collapse;font-size:14px}td{padding:6px 4px}
label{display:block;font-size:13px;font-weight:700;margin:12px 0 5px}
input{width:100%;padding:11px 13px;border:1px solid #dde3ee;border-radius:9px;font-size:14px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
button{margin-top:22px;width:100%;padding:13px;border:0;border-radius:10px;background:#F07C1F;color:#fff;font-weight:800;font-size:15px;cursor:pointer}
.err{background:#f7dcdc;color:#8a1f1f;padding:11px 14px;border-radius:9px;margin:8px 0;font-size:13px;font-weight:600}
.ok{background:#d7f4e3;color:#0a6b3b;padding:14px 16px;border-radius:10px;font-weight:600}
.muted{color:#6b7688;font-size:12px}
code{background:#f3f5fa;padding:2px 6px;border-radius:5px;font-size:12px}
</style></head><body><div class="box">
<h1>🚌 S Hari Global — Setup</h1>
<p class="sub">One-time installation wizard</p>

<?php if ($alreadyInstalled): ?>
  <div class="ok">✅ This site is already installed.</div>
  <p class="muted">For security, delete <code>install.php</code> from the server. To reinstall, remove
     <code>config/installed.lock</code> first (this does not touch your data).</p>
  <p><a href="./admin/login.php">→ Go to the admin panel</a></p>

<?php elseif ($done): ?>
  <div class="ok">🎉 Installation complete!</div>
  <h2>Do these two things now</h2>
  <ol class="muted" style="line-height:1.9">
    <li><strong>Delete <code>install.php</code></strong> from the server.</li>
    <li>Set up the cron jobs (see <code>HOSTINGER-DEPLOY.md</code>) using your new token.</li>
  </ol>
  <p><a href="./admin/login.php">→ Sign in to the admin panel</a> &nbsp;·&nbsp; <a href="./">View the website</a></p>

<?php else: ?>
  <h2>1 · Environment</h2>
  <table>
    <tr><td>PHP 8.1+ (<?= $e(PHP_VERSION) ?>)</td><td style="text-align:right"><?= yn($phpOk) ?></td></tr>
    <?php foreach ($extStatus as $ext => $ok): ?>
      <tr><td>Extension: <?= $e($ext) ?></td><td style="text-align:right"><?= yn($ok) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($writable as $dir => $ok): ?>
      <tr><td>Writable: /<?= $e($dir) ?></td><td style="text-align:right"><?= yn($ok) ?></td></tr>
    <?php endforeach; ?>
    <tr><td>database/schema.sql + seed.sql</td><td style="text-align:right"><?= yn($filesPresent) ?></td></tr>
  </table>

  <?php foreach ($errors as $err): ?><div class="err"><?= $e($err) ?></div><?php endforeach; ?>

  <form method="post">
    <h2>2 · Database (from cPanel → MySQL Databases)</h2>
    <label>Database host</label>
    <input name="db_host" value="<?= $e($_POST['db_host'] ?? 'localhost') ?>" required>
    <div class="grid">
      <div><label>Database name</label><input name="db_name" value="<?= $e($_POST['db_name'] ?? '') ?>" required placeholder="u123_shari"></div>
      <div><label>Database user</label><input name="db_user" value="<?= $e($_POST['db_user'] ?? '') ?>" required placeholder="u123_shariuser"></div>
    </div>
    <label>Database password</label>
    <input name="db_pass" type="password" value="">
    <label>Full site URL</label>
    <input name="app_url" value="<?= $e($_POST['app_url'] ?? '') ?>" required placeholder="https://yourdomain.com">

    <h2>3 · First admin account</h2>
    <div class="grid">
      <div><label>Username</label><input name="admin_user" value="<?= $e($_POST['admin_user'] ?? '') ?>" required></div>
      <div><label>Full name</label><input name="admin_name" value="<?= $e($_POST['admin_name'] ?? 'Administrator') ?>"></div>
    </div>
    <label>Email</label>
    <input name="admin_mail" type="email" value="<?= $e($_POST['admin_mail'] ?? '') ?>">
    <label>Password (min 8 characters)</label>
    <input name="admin_pass" type="password" required>

    <button type="submit">Install now</button>
    <p class="muted" style="margin-top:12px">This imports the database, creates your admin login and writes a fresh config with strong secrets.</p>
  </form>
<?php endif; ?>
</div></body></html>
