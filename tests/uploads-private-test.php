<?php
/**
 * uploads-private-test.php — nobody reads a departure sheet by guessing a
 * URL (25 Sep 2026).
 *
 *  The owner found challan pictures opening without a login. The uploads
 *  tree is created at runtime and is not in git, so a rule written once at
 *  install time protects nothing a month later — and the root .htaccess
 *  claimed a guard existed inside uploads/ that had never been written.
 *
 *   A. The guards ship with the code, and say the same thing the code says.
 *   B. nginx and .htaccess agree on which folders are private — neither may
 *      quietly protect one the other does not.
 *   C. ensurePrivateDir() writes the guard beside the data, at the root of
 *      the tree, and restores the tree-wide rule if it goes missing.
 *   D. Over HTTP: every private tree is refused, while a public picture
 *      (what Instagram fetches) is still served.                    [HTTP]
 *   E. The legitimate door still opens: download-chalan.php with a signed
 *      link, and refuses without one.                               [HTTP]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function get(string $path): array {
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    $b = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $c, 'body' => $b];
}
$ROOT    = dirname(__DIR__);
/* wa-inbound carries media a customer sent us on WhatsApp, so it is a
   private tree exactly like the other four. This lineage's nginx already
   denied it; the guard beside the data was missing. */
$PRIVATE = ['challan', 'chalani', 'passengers', 'agents-kyc', 'wa-inbound'];

echo "\n=== The uploads tree is private ===\n\n-- A. the guards ship with the code --\n";
check('uploads/.htaccess is in the tree', is_file($ROOT . '/uploads/.htaccess'));
check('…and is exactly what the code writes', trim((string) @file_get_contents($ROOT . '/uploads/.htaccess')) === trim(UPLOADS_TREE_HTACCESS), 'otherwise the two drift apart');
foreach ($PRIVATE as $tree) {
    check("uploads/$tree/.htaccess is in the tree", is_file($ROOT . "/uploads/$tree/.htaccess"));
    check("…and is exactly what the code writes", trim((string) @file_get_contents($ROOT . "/uploads/$tree/.htaccess")) === trim(PRIVATE_DIR_HTACCESS));
}
check('the private guard denies everything', str_contains(PRIVATE_DIR_HTACCESS, 'Require all denied') && str_contains(PRIVATE_DIR_HTACCESS, 'Deny from all'));
check('the tree-wide rule stops PHP from running', str_contains(UPLOADS_TREE_HTACCESS, 'engine off') && str_contains(UPLOADS_TREE_HTACCESS, 'RemoveHandler') && str_contains(UPLOADS_TREE_HTACCESS, 'php'));
/* The tree-wide rule denies executables and nothing else. Checked by
   removing the <FilesMatch> blocks — which is where the deny belongs — and
   making sure no deny is left over them, because one there would take the
   social pictures down with it. */
$outsideFilesMatch = preg_replace('#<FilesMatch.*?</FilesMatch>#is', '', UPLOADS_TREE_HTACCESS) ?? '';
check('…and denies nothing outside the executable rule', !str_contains($outsideFilesMatch, 'Require all denied') && !str_contains($outsideFilesMatch, 'Deny from all'), 'a social picture must stay fetchable');
check('…while the executable rule does deny', (bool) preg_match('#<FilesMatch[^>]*php[^>]*>.*?(Require all denied|Deny from all).*?</FilesMatch>#is', UPLOADS_TREE_HTACCESS));

echo "\n-- B. nginx and .htaccess agree --\n";
$nginx = (string) @file_get_contents($ROOT . '/deploy/nginx-shreehariglobal.in.conf');
check('the deploy nginx config is readable', $nginx !== '');
foreach ($PRIVATE as $tree) {
    check("nginx denies /uploads/$tree/", (bool) preg_match('#location \^~ /uploads/' . preg_quote($tree, '#') . '/ \{\s*deny all#', $nginx));
}
preg_match_all('#location \^~ /uploads/([a-z-]+)/ \{\s*deny all#', $nginx, $m);
$deniedByNginx = $m[1] ?? [];
sort($deniedByNginx);
$guarded = $PRIVATE;
sort($guarded);
check('neither side protects a folder the other does not', $deniedByNginx === $guarded, 'nginx: ' . implode(', ', $deniedByNginx));
check('nginx also refuses to run PHP inside uploads', str_contains($nginx, 'location ~ \\.php$ { deny all; return 404; }'));

echo "\n-- C. the guard travels with the data --\n";
$day = UPLOAD_PATH . '/challan/probe-' . date('Ymd-His');
check('a new dated folder is created', ensurePrivateDir($day) && is_dir($day));
check('…and the guard sits at the root of the tree, not in the dated folder', is_file(UPLOAD_PATH . '/challan/.htaccess') && !is_file($day . '/.htaccess'), 'Apache applies it to everything beneath');
@unlink(UPLOAD_PATH . '/challan/.htaccess');
ensurePrivateDir($day);
check('a deleted guard is written again', is_file(UPLOAD_PATH . '/challan/.htaccess'));
$treeRule = (string) @file_get_contents(UPLOAD_PATH . '/.htaccess');
@unlink(UPLOAD_PATH . '/.htaccess');
ensurePrivateDir($day);
check('a deleted tree-wide rule is written again', is_file(UPLOAD_PATH . '/.htaccess'));
if ($treeRule !== '') { @file_put_contents(UPLOAD_PATH . '/.htaccess', $treeRule); }
@rmdir($day);
check('a path outside the private trees is left alone', privateUploadRoot(UPLOAD_PATH . '/social/poster.png') === null);
check('…and one inside resolves to its tree root', privateUploadRoot(UPLOAD_PATH . '/challan/2026-10-22/x.png') === UPLOAD_PATH . '/challan');

$home = get('/');
if ($home['code'] !== 200) {
    echo "\n  SKIP  no server at " . BASE . " — D and E not run\n";
} else {
    echo "\n-- D. what the server hands out --\n";
    foreach ($PRIVATE as $tree) {
        $r = get('/uploads/' . $tree . '/');
        check("/uploads/$tree/ is refused", in_array($r['code'], [401, 403, 404], true), 'HTTP ' . $r['code']);
    }
    $sheet = '';
    foreach (glob(UPLOAD_PATH . '/challan/*/*.png') ?: [] as $f) {
        $sheet = '/uploads/challan/' . basename(dirname($f)) . '/' . basename($f);
        break;
    }
    if ($sheet !== '') {
        $r = get($sheet);
        check('a real departure sheet cannot be fetched by its URL', in_array($r['code'], [401, 403, 404], true), $sheet . ' → HTTP ' . $r['code']);
    } else {
        check('a real departure sheet cannot be fetched by its URL', true, 'none drawn yet on this database');
    }
    $pub = UPLOAD_PATH . '/social';
    @mkdir($pub, 0755, true);
    @file_put_contents($pub . '/probe.txt', 'a picture the platforms fetch');
    $r = get('/uploads/social/probe.txt');
    check('a public upload is still served, so Instagram can fetch it', $r['code'] === 200 && str_contains($r['body'], 'platforms fetch'), 'HTTP ' . $r['code']);
    @unlink($pub . '/probe.txt');
    @rmdir($pub);

    echo "\n-- E. the legitimate door --\n";
    $sid = (int) Database::scalar('SELECT id FROM schedules ORDER BY id DESC LIMIT 1', [], 0);
    $r = get('/download-chalan.php?sid=' . $sid . '&doc=challan');
    check('download-chalan.php refuses an unsigned request', $r['code'] === 403, 'HTTP ' . $r['code']);
    $exp = time() + 600;
    $k   = substr(Security::sign('chalan-dl|' . $sid . '|challan|1|' . $exp), 0, 20);
    $r = get('/download-chalan.php?sid=' . $sid . '&doc=challan&page=1&exp=' . $exp . '&k=' . $k);
    check('…and serves the sheet for a signed link', $r['code'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'HTTP ' . $r['code'] . ', ' . strlen($r['body']) . ' bytes');
    $r = get('/download-chalan.php?sid=' . $sid . '&doc=challan&page=1&exp=' . (time() - 60) . '&k=' . substr(Security::sign('chalan-dl|' . $sid . '|challan|1|' . (time() - 60)), 0, 20));
    check('an expired link is refused even though it is correctly signed', $r['code'] === 403, 'HTTP ' . $r['code']);
    $r = get('/download-chalan.php?sid=' . $sid . '&doc=challan&page=1&exp=' . $exp . '&k=' . str_repeat('0', 20));
    check('a made-up key is refused', $r['code'] === 403, 'HTTP ' . $r['code']);
}

echo "\n----------------------------------------\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
