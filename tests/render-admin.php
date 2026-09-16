<?php
/**
 * Dev helper: render an admin page from the CLI with a faked staff session,
 * so a page can be smoke-tested without typing a password into a browser.
 *
 *     php -c .claude/php-dev.ini tests/render-admin.php refunds.php
 *     php -c .claude/php-dev.ini tests/render-admin.php trips.php view=today
 *
 * Prints the page's HTML (or the PHP error that stopped it). CLI only —
 * it fabricates an admin session, so it must never be reachable over HTTP.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$page = $argv[1] ?? '';
if ($page === '' || !preg_match('/^[a-z0-9_-]+\.php$/', $page)) {
    fwrite(STDERR, "usage: render-admin.php <page.php> [key=value ...]\n");
    exit(1);
}

$file = dirname(__DIR__) . '/admin/' . $page;
if (!is_file($file)) {
    fwrite(STDERR, "no such admin page: $page\n");
    exit(1);
}

// Remaining arguments become the query string; anything prefixed "post:"
// becomes a POST field and switches the request method (the CSRF token is
// filled in below, once the session exists).
$post = [];
foreach (array_slice($argv, 2) as $pair) {
    if (str_starts_with($pair, 'post:')) {
        [$k, $v] = array_pad(explode('=', substr($pair, 5), 2), 2, '');
        $post[$k] = $v;
        continue;
    }
    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
    $_GET[$k] = $v;
}

$_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
$_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
$_SERVER['REQUEST_URI']    = '/admin/' . $page;
$_SERVER['HTTP_HOST']      = 'localhost';

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$admin = Database::fetch("SELECT * FROM admins WHERE is_active = 1 ORDER BY id LIMIT 1");
if ($admin === null) { fwrite(STDERR, "no active admin in the database\n"); exit(1); }

$_SESSION[ADMIN_SESSION_KEY] = [
    'id'          => (int) $admin['id'],
    'username'    => (string) $admin['username'],
    'full_name'   => (string) ($admin['full_name'] ?? ''),
    'role'        => (string) $admin['role'],
    'permissions' => jsonColumn($admin['permissions'] ?? null),
    'last_seen'   => time(),
];

if ($post !== []) {
    $_POST                    = $post;
    $_POST[CSRF_TOKEN_NAME]   = Security::csrfToken();
    $_SERVER['CONTENT_TYPE']  = 'application/x-www-form-urlencoded';
}

require $file;
