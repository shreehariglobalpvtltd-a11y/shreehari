<?php
/**
 * tools/navdata/import-places.php — load places.tsv (navplaces.py) into nav_places.
 *
 *   sudo -u www-data php tools/navdata/import-places.php /srv/shg-nav/work/places.tsv
 *
 * Loads into nav_places_new, then swaps the tables in one RENAME, so the
 * search never sees a half-filled index. CLI only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SHG_APP', true);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$file = $argv[1] ?? '';
$fh = $file !== '' ? fopen($file, 'rb') : false;
if ($fh === false) {
    fwrite(STDERR, "usage: php import-places.php places.tsv\n");
    exit(2);
}
Database::run('DROP TABLE IF EXISTS nav_places_new');
Database::run('CREATE TABLE nav_places_new LIKE nav_places');
$batch = [];
$n = 0;
$flush = static function () use (&$batch, &$n): void {
    if ($batch === []) {
        return;
    }
    $vals = [];
    $p = [];
    foreach ($batch as $i => $r) {
        $vals[] = "(:o$i, :n$i, :e$i, :k$i, :a$i, :b$i)";
        $p["o$i"] = $r[0];
        $p["n$i"] = $r[1];
        $p["e$i"] = $r[2] !== '' ? $r[2] : null;
        $p["k$i"] = $r[3];
        $p["a$i"] = $r[4];
        $p["b$i"] = $r[5];
    }
    Database::run('INSERT INTO nav_places_new (osm_ref, name, name_en, kind, lat, lng) VALUES ' . implode(',', $vals), $p);
    $n += count($batch);
    $batch = [];
};
while (($line = fgets($fh)) !== false) {
    $c = explode("\t", rtrim($line, "\r\n"));
    if (count($c) < 6 || $c[1] === '' || !is_numeric($c[4]) || !is_numeric($c[5])) {
        continue;
    }
    $batch[] = [mb_substr($c[0], 0, 24), mb_substr($c[1], 0, 150), mb_substr($c[2], 0, 150), mb_substr($c[3], 0, 60), (float) $c[4], (float) $c[5]];
    if (count($batch) >= 400) {
        $flush();
    }
}
$flush();
fclose($fh);
Database::run('DROP TABLE IF EXISTS nav_places_old');
Database::run('RENAME TABLE nav_places TO nav_places_old, nav_places_new TO nav_places');
Database::run('DROP TABLE nav_places_old');
echo "imported $n places\n";
