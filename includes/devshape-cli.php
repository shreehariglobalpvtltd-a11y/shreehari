<?php
/**
 * devshape-cli.php — the child process DevShape runs for web requests.
 *
 * PHP-FPM cannot use FFI (ffi.enable = preload allows the CLI only), so
 * includes/devshape.php starts this script with the CLI binary, writes
 * {"texts":[...]} to its stdin and reads {"runs":[...]} back. It loads
 * nothing but DevShape itself: no config, no database, no session.
 *
 *   echo '{"texts":["यात्रु"]}' | php includes/devshape-cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}
require __DIR__ . '/devshape.php';

$in = json_decode((string) stream_get_contents(STDIN), true);
$texts = (is_array($in) && isset($in['texts']) && is_array($in['texts'])) ? array_map('strval', array_values($in['texts'])) : [];
$runs = $texts ? DevShape::shapeLocal($texts) : [];
if ($runs === null) {
    fwrite(STDERR, "harfbuzz unavailable\n");
    exit(1);
}
echo json_encode(['runs' => $runs], JSON_UNESCAPED_UNICODE);
