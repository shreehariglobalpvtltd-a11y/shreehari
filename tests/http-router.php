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
// PHP's automatic index fallback excludes missing paths containing dots,
// including our dynamic sitemap XML URLs. nginx sends these to index.php.
require dirname(__DIR__) . '/index.php';
