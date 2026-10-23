<?php
/** Development server front-controller routing, matching nginx try_files. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$root = realpath(dirname(__DIR__));
$file = realpath($root . $path);
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
// A directory with its own index.php (/team/, /driver/, /agreements/): nginx's index directive
// serves that file, GET and POST alike. php -S only did this for GET (23 Oct 2026).
if ($file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_dir($file) && is_file($file . DIRECTORY_SEPARATOR . 'index.php')) {
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $file . DIRECTORY_SEPARATOR . 'index.php';
    chdir($file);
    require $file . DIRECTORY_SEPARATOR . 'index.php';
    return true;
}
// Standalone pages with clean URLs: the nginx `location = /travel-info`
// block sends these straight to their own file, not through index.php.
$clean = ['/travel-info' => '/travel-info.php'];
if (isset($clean[$path])) {
    require $root . $clean[$path];
    return true;
}
// PHP's automatic index fallback excludes missing paths containing dots,
// including our dynamic sitemap XML URLs. nginx sends these to index.php.
require dirname(__DIR__) . '/index.php';
