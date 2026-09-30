<?php
/**
 * public-files-test.php — no backup copy of PHP source sits where the web
 * server can hand it out (24 Sep 2026).
 *
 * Eight hand-made backups from 28 Aug (admin/_guard.php.bak.20260828-…,
 * admin/booking-view.php.bak.…, admin/index.php.bak.…, includes/*.bak.…)
 * were tracked in git and deployed. nginx denies names ENDING in .bak, but
 * these end in a timestamp, so https://shreehariglobal.network/admin/
 * _guard.php.bak.20260828-153536 was served as a plain download: the admin
 * guard's source, readable by anyone. includes/ is denied by path, the
 * admin/ copies were not.
 *
 * Reads only: lists the tracked files and fails on any backup-style copy of
 * a script under a directory the web server serves.
 *
 *   php tests/public-files-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

echo "\n=== Public files: no downloadable source backups ===\n\n";

/* Walk the tree (git may not be on PATH for the web user); skip what nginx
   never serves anyway and what is not deployed code. */
$skip = ['.git', 'node_modules', 'logs', 'backup', 'tickets', 'invoice', 'tmp', 'output'];
$bad = [];
$seen = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $f) use ($skip, $root): bool {
            $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            return !in_array(explode('/', $rel)[0], $skip, true);
        }
    )
);
foreach ($it as $f) {
    $seen++;
    $name = $f->getFilename();
    if (preg_match('~\.(php|phtml|inc|js|html|sql)[._-]?(bak|old|orig|save|copy|backup|swp|tmp)~i', $name)
        || preg_match('~\.(bak|old|orig|save|copy|backup)[._-]?\d{6,}~i', $name)) {
        $bad[] = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    }
}
check('the tree was walked', $seen > 100, $seen . ' files');
check('no backup copy of a script anywhere in the deployed tree', $bad === [], implode(', ', array_slice($bad, 0, 8)));

echo "\n----------------------------------------\n";
echo "  {$PASS} passed, {$FAIL} failed\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
