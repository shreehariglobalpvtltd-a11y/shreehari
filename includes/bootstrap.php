<?php
/**
 * =====================================================================
 *  Bootstrap — the single entry point every page and endpoint includes.
 *
 *  Usage from any file in the web root:
 *      require_once __DIR__ . '/includes/bootstrap.php';
 *
 *  From a subdirectory (admin/, api/, customer/):
 *      require_once dirname(__DIR__) . '/includes/bootstrap.php';
 * =====================================================================
 */

declare(strict_types=1);

// Marks a legitimate application request. Every include checks for it,
// so opening includes/db.php directly in a browser returns 403.
if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}

// Guard against double bootstrapping.
if (defined('SHG_BOOTSTRAPPED')) {
    return;
}
define('SHG_BOOTSTRAPPED', true);


/* =====================================================================
 *  1. Configuration
 * ===================================================================== */

// A local override (config/config.local.php) is used when present. It is
// git-ignored and exists only on developer machines, so production always
// uses config.php. Handy for pointing at a test database while developing.
$configFile  = dirname(__DIR__) . '/config/config.php';
$localConfig = dirname(__DIR__) . '/config/config.local.php';

if (is_file($localConfig)) {
    $configFile = $localConfig;
}

if (!is_file($configFile)) {
    http_response_code(503);
    exit('Configuration file is missing. Copy config/config.sample.php to config/config.php and fill in your database details.');
}

require_once $configFile;


/* =====================================================================
 *  2. Runtime environment
 * ===================================================================== */

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');

// Writable runtime directories. Created on first boot so a fresh upload
// does not need manual mkdir over FTP.
foreach ([UPLOAD_PATH, TICKET_PATH, QR_PATH, INVOICE_PATH, BACKUP_PATH, LOG_PATH] as $runtimeDir) {
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
    }
}


/* =====================================================================
 *  3. Core classes
 * ===================================================================== */

require_once INCLUDE_PATH . '/db.php';
require_once INCLUDE_PATH . '/logger.php';
require_once INCLUDE_PATH . '/response.php';
require_once INCLUDE_PATH . '/security.php';
require_once INCLUDE_PATH . '/settings.php';
require_once INCLUDE_PATH . '/helpers.php';
// Before auth.php: Auth records every sign-in attempt through LoginLog.
require_once INCLUDE_PATH . '/loginlog.php';
require_once INCLUDE_PATH . '/auth.php';
// Per-pickup cut-off: decides whether a boarding point on today's run is
// still catchable. Needed by both the search endpoint and the booking
// chokepoint, so it loads with the core rather than per-page.
require_once INCLUDE_PATH . '/boarding.php';
// Fleet rotation: decides which coach serves a given travel date, so a
// daily service recycles a small fleet instead of being hand-assigned.
require_once INCLUDE_PATH . '/fleet.php';
// EventBus: booking/trip/agent lifecycle events + the automation
// listeners. Loads with the core so every emit site can rely on it; the
// listeners themselves lazy-require notify.php only when they fire.
require_once INCLUDE_PATH . '/events.php';


/* =====================================================================
 *  4. Error and exception handling
 *     Nothing reaches the visitor as a raw stack trace, and no request
 *     ever dies as a blank white page.
 * ===================================================================== */

set_exception_handler(static function (Throwable $e): void {
    Logger::exception($e);

    $wantsJson = str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($wantsJson) {
        Response::serverError($e);
    }

    http_response_code(500);

    if (APP_DEBUG) {
        echo '<h1>Application error</h1>';
        echo '<p><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</strong></p>';
        echo '<p>' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' line ' . $e->getLine() . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }

    $errorPage = ROOT_PATH . '/error.php';
    if (is_file($errorPage)) {
        require $errorPage;
        exit;
    }

    exit('Something went wrong at our end. Please try again in a moment.');
});

set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $fatal = error_get_last();

    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Logger::critical($fatal['message'], [
            'file' => $fatal['file'],
            'line' => $fatal['line'],
        ]);

        if (!headers_sent()) {
            http_response_code(500);
        }
    }
});


/* =====================================================================
 *  5. Session
 * ===================================================================== */

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => Security::isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    session_start();

    // Rotate the session id periodically to blunt fixation attacks.
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - (int) $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    // Every visitor gets a token used to own their seat holds.
    if (empty($_SESSION['lock_token'])) {
        $_SESSION['lock_token'] = Security::randomToken(24);
    }
}


/* =====================================================================
 *  6. Transport security and response headers
 * ===================================================================== */

if (PHP_SAPI !== 'cli') {
    $isApiRequest = str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');

    Security::enforceHttps();
    Security::sendHeaders($isApiRequest);
}


/* =====================================================================
 *  7. Maintenance mode
 *     Admin routes stay reachable so the site can be brought back up.
 * ===================================================================== */

if (PHP_SAPI !== 'cli') {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

    $isExempt = str_contains($uri, '/admin')
        || str_contains($uri, '/install.php')
        || str_contains($uri, '/api/');

    if (!$isExempt && Settings::getBool('maintenance_mode', false)) {
        http_response_code(503);
        header('Retry-After: 3600');

        $maintenancePage = ROOT_PATH . '/maintenance.php';
        if (is_file($maintenancePage)) {
            require $maintenancePage;
            exit;
        }

        exit('We are carrying out scheduled maintenance. Please check back shortly.');
    }
}
