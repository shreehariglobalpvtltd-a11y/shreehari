<?php
/**
 * =====================================================================
 *  S HARI GLOBAL PVT LTD — Application Configuration
 * =====================================================================
 *
 *  THIS IS THE TEMPLATE, NOT THE LIVE CONFIG.
 *
 *      cp config/config.sample.php config/config.php
 *
 *  then fill in the DATABASE block in config.php. Only config.php is read
 *  at runtime, and it is git-ignored so the live credentials are never
 *  committed and can never be overwritten by a deploy.
 *
 *  The values below are deliberately placeholders. If you ever see
 *  'u000000000_shari' in a running site, config.php is missing and this
 *  template is being read instead.
 *
 *  This folder is blocked from direct web access by config/.htaccess.
 * =====================================================================
 */

declare(strict_types=1);

// Block direct browser access even if .htaccess is missing.
if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}


/* =====================================================================
 *  1. DATABASE  —  from cPanel → MySQL Databases
 * =====================================================================
 *  On Hostinger the host is almost always 'localhost'.
 *  The user and database names are prefixed with your account name,
 *  for example  u123456789_shari.
 */
define('DB_HOST',    'localhost');
define('DB_NAME',    'u000000000_shari');       // <-- CHANGE
define('DB_USER',    'u000000000_shariuser');   // <-- CHANGE
define('DB_PASS',    'CHANGE_ME_STRONG_PASSWORD'); // <-- CHANGE
define('DB_CHARSET', 'utf8mb4');


/* =====================================================================
 *  2. APPLICATION
 * =====================================================================
 */
define('APP_NAME',  'S Hari Global Pvt Ltd');

// Full public URL, no trailing slash. Used for links in e-mails, ticket PDFs
// and the WhatsApp/SMS messages, so a wrong value here sends customers to a
// dead address. This is the live domain — do not change it unless the domain
// itself changes.
define('APP_URL',   'https://www.shreehariglobal.in');

// 'production' hides errors from visitors. Use 'development' only while testing.
define('APP_ENV',   'production');
define('APP_DEBUG', APP_ENV !== 'production');

// Secret used to sign ticket QR codes and session tokens.
// install.php generates a strong random value here. If you edit it later,
// previously issued ticket QR codes will no longer validate at scan time.
define('APP_KEY',   'CHANGE_ME_RANDOM_64_CHARS');  // <-- install.php sets this

define('APP_TIMEZONE', 'Asia/Kolkata');


/* =====================================================================
 *  3. PATHS  — derived automatically, no need to edit
 * =====================================================================
 */
define('ROOT_PATH',     dirname(__DIR__));
define('CONFIG_PATH',   ROOT_PATH . '/config');
define('INCLUDE_PATH',  ROOT_PATH . '/includes');
define('UPLOAD_PATH',   ROOT_PATH . '/uploads');
define('TICKET_PATH',   ROOT_PATH . '/tickets');
define('QR_PATH',       ROOT_PATH . '/qr');
define('INVOICE_PATH',  ROOT_PATH . '/invoice');
define('BACKUP_PATH',   ROOT_PATH . '/backup');
define('LOG_PATH',      ROOT_PATH . '/logs');
define('ASSET_PATH',    ROOT_PATH . '/assets');


/* =====================================================================
 *  4. SESSION & SECURITY
 * =====================================================================
 */
define('SESSION_NAME',        'SHGSESSID');
define('SESSION_LIFETIME',    60 * 60 * 3);   // 3 hours
define('CSRF_TOKEN_NAME',     'shg_csrf');
define('ADMIN_SESSION_KEY',   'shg_admin');
define('USER_SESSION_KEY',    'shg_user');

// Force HTTPS. Leave true in production — Hostinger provides free SSL.
define('FORCE_HTTPS', true);

// Login throttling
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOGIN_LOCKOUT_MIN',   15);

// OTP
define('OTP_LENGTH',          6);
define('OTP_EXPIRY_MINUTES',  10);
define('OTP_RESEND_SECONDS',  60);


/* =====================================================================
 *  5. UPLOADS  — payment screenshots
 * =====================================================================
 */
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);   // 10 MB
define('ALLOWED_UPLOAD_MIME', serialize([
    'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
]));
define('ALLOWED_UPLOAD_EXT', serialize([
    'jpg', 'jpeg', 'png', 'webp', 'pdf',
]));


/* =====================================================================
 *  6. BUSINESS DEFAULTS
 * =====================================================================
 *  These are fallbacks only. The live values are stored in the
 *  `settings` table and are editable from Admin -> Settings.
 */
define('DEFAULT_CURRENCY',   'INR');
define('NPR_PER_INR',        1.6);
define('SEAT_HOLD_MINUTES',  30);
define('MAX_SEATS_BOOKING',  6);
define('PNR_PREFIX',         'SHG');


/* =====================================================================
 *  7. CRON SECURITY
 * =====================================================================
 *  Cron scripts refuse to run over the web unless this token matches.
 *  Example cron command (cPanel -> Cron Jobs):
 *    /usr/local/bin/php /home/USER/public_html/cron/expire_locks.php
 */
define('CRON_TOKEN', 'CHANGE_ME_CRON_TOKEN');   // <-- install.php sets this
