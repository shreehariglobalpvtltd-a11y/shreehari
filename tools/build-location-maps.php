<?php
/**
 * tools/build-location-maps.php - make the satellite / road pictures of our
 * places (LocationMaps::buildAll), same as "Make all satellite maps" on
 * admin/locations.php. Safe to run again: an unchanged picture keeps its file.
 *
 *   php tools/build-location-maps.php [place-id]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/locationmaps.php';
$only = isset($argv[1]) ? (int) $argv[1] : 0;
foreach (LocationMaps::buildAll($only > 0 ? $only : null) as $line) {
    echo $line, "\n";
}
