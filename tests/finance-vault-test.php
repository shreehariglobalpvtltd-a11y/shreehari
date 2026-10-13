<?php
/**
 * tests/finance-vault-test.php — SHG Finance Master v2 website door (9 Oct 2026).
 *
 * Covers the three things that join the finance app to the admin panel:
 *
 *   1. admin/api/finance-vault.php — encrypted book backups on the India VPS:
 *      save / list / get round trip, prune to the newest 120, refusal of a
 *      plaintext book, bad base64, sha256 mismatch, oversize, path-traversal
 *      ids, permission walls (superadmin or the finance.vault grant, never a
 *      counter agent), CSRF on POST, the 30/min rate limit and the audit rows.
 *      Driven in-process against a temporary directory (the endpoint file is
 *      loaded with SHG_FINANCE_VAULT_LIB defined, which stops before HTTP).
 *   2. admin/finance.php — the csrf + shg-admin <meta> injection (placement,
 *      escaping, byte length), the role flags, the dev-only SHG_FINANCE_HTML
 *      override and the page's own CSP.
 *   3. The deep links from six admin pages into the app — source-level (every
 *      link gated on reports.view AND not a counter agent, root-relative,
 *      escaped with the page's own helper) and rendered per role in a child
 *      process with a faked staff session.
 *
 * Then, when the port is free, a real HTTP round trip: this suite starts its
 * own `php -S 127.0.0.1:8892` with SHG_FINANCE_VAULT_DIR pointing at a temp
 * folder (so nothing lands in backup/), signs four throwaway staff in through
 * /admin/login.php and exercises finance.php + the vault over the wire.
 *
 *   php tests/finance-vault-test.php
 *   SHG_VAULT_PORT=8893 php tests/finance-vault-test.php      (another port)
 *   SHG_VAULT_HTTP=0 php tests/finance-vault-test.php         (skip HTTP)
 *
 * Writes only to the TEST database (refuses anything else, like run-all.php):
 * throwaway admins named fvt-*, their audit / login rows and rate_limits rows,
 * all removed in a finally block. CLI only. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

const FVT_RENDER_ID   = 999731;
const FVT_RENDER_USER = 'fvt-render';

/* ------------------------------------------------------------------------
 *  Child mode: render one admin page with a faked staff session.
 *    php tests/finance-vault-test.php --render <role> <page.php> [k=v ...] [@perms=a,b]
 * ---------------------------------------------------------------------- */
if (($argv[1] ?? '') === '--render') {
    $role = (string) ($argv[2] ?? '');
    $page = (string) ($argv[3] ?? '');
    if (!preg_match('/^[a-z]+$/', $role) || !preg_match('/^[a-z0-9_-]+\.php$/', $page)) {
        fwrite(STDERR, "usage: --render <role> <page.php> [k=v ...]\n");
        exit(2);
    }
    $perms = [];
    $sessId = FVT_RENDER_ID;
    foreach (array_slice($argv, 4) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        if ($k === '@perms') { $perms = $v === '' ? [] : explode(',', $v); continue; }
        if ($k === '@id') { $sessId = (int) $v; continue; }
        $_GET[$k] = $v;
    }
    $qs = http_build_query($_GET);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page;
    $_SERVER['REQUEST_URI']    = '/admin/' . $page . ($qs !== '' ? '?' . $qs : '');
    $_SERVER['QUERY_STRING']   = $qs;
    $_SERVER['HTTP_HOST']      = 'localhost';
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (stripos((string) DB_NAME, 'test') === false || strtolower((string) APP_ENV) === 'production') {
        fwrite(STDERR, "refusing: not a test database\n");
        exit(3);
    }
    $_SESSION = [];
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $sessId, 'username' => FVT_RENDER_USER, 'full_name' => 'FVT Render', 'role' => $role,
        'permissions' => $perms, 'must_change_pw' => 0, 'last_seen' => time(),
    ];
    require dirname(__DIR__) . '/admin/' . $page;
    exit(0);
}

/* ------------------------------------------------------------------------
 *  Main suite
 * ---------------------------------------------------------------------- */
define('SHG_FINANCE_VAULT_LIB', true);
define('SHG_FINANCE_PAGE_LIB', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  SKIP  $l\n"; }

/* Same three walls as tests/run-all.php assertTestDatabase(). */
(static function (): void {
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $host = strtolower(trim(explode(';', defined('DB_HOST') ? (string) DB_HOST : '')[0]));
    $env  = defined('APP_ENV') ? strtolower((string) APP_ENV) : '';
    if (stripos($name, 'test') === false || !in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true) || $env === 'production') {
        fwrite(STDERR, "\n  REFUSING TO RUN — this does not look like a test database (DB '{$name}', host '{$host}', APP_ENV '{$env}').\n\n");
        exit(1);
    }
})();

require_once ROOT_PATH . '/admin/api/finance-vault.php';
require_once ROOT_PATH . '/admin/finance.php';

$root = dirname(__DIR__);
// Line endings normalised: several admin pages are CRLF, the checks are written with \n.
$src  = static fn(string $rel): string => is_file($root . '/' . $rel) ? str_replace("\r\n", "\n", (string) file_get_contents($root . '/' . $rel)) : '';

/** Fake staff session for the in-process walls. */
function as_staff(?string $role, array $perms = [], int $id = 990001, string $name = 'FVT Staff'): void {
    if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
    if ($role === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => 'fvt-staff-' . $role, 'full_name' => $name, 'role' => $role,
        'permissions' => $perms, 'must_change_pw' => 0, 'last_seen' => time()];
}
/** A real-shaped "enc1" envelope: random bytes stand in for AES-GCM output. */
function envelope(int $dataBytes = 2048, array $extra = []): string {
    return json_encode(['shgfm' => 'enc1', 'salt' => base64_encode(random_bytes(16)), 'iv' => base64_encode(random_bytes(12)),
        'data' => base64_encode(random_bytes($dataBytes))] + $extra, JSON_UNESCAPED_SLASHES);
}
/** Run a closure that must throw FinanceVaultError; returns the HTTP code (0 when it did not throw). */
function vault_code(Closure $fn): int {
    try { $fn(); } catch (FinanceVaultError $e) { return (int) $e->getCode(); }
    return 0;
}
function rrmdir(string $d): void {
    if (!is_dir($d) || is_link($d)) { if (is_link($d) || is_file($d)) { @unlink($d); } return; }
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $d . '/' . $f;
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

$tmpBase = sys_get_temp_dir() . '/shg-fvt-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($tmpBase, 0700, true);
$auditStart = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$serverProc = null;
$httpAdmins = [];

try {

/* ========================================================================
 *  1. Pure helpers
 * ====================================================================== */
echo "\n=== FinanceVault helpers — ids, base64, labels, folder ===\n";
check('a well-formed id is accepted', FinanceVault::isValidId('vault-20261009-140300-0a1b2c3d'));
$badIds = ['', '..', '../../config/config.local', 'vault-20261009-140300-0a1b2c3', 'vault-20261009-140300-0A1B2C3D',
    'vault-20261009-140300-0a1b2c3d.json', 'vault-20261009-140300-0a1b2c3d/../../x', "vault-20261009-140300-0a1b2c3d\n",
    "vault-20261009-140300-0a1b2c3d\0", 'vault-2026109-140300-0a1b2c3d', 'xvault-20261009-140300-0a1b2c3d', '/etc/passwd',
    'vault-20261009-140300-0a1b2c3d%2F..'];
$rejected = 0;
foreach ($badIds as $b) { if (!FinanceVault::isValidId($b)) { $rejected++; } }
check('every malformed / traversal id is rejected (' . count($badIds) . ' cases)', $rejected === count($badIds));
check('non-string ids are rejected (array, int, null)', !FinanceVault::isValidId(['x']) && !FinanceVault::isValidId(5) && !FinanceVault::isValidId(null));

check('base64: padded standard forms pass', FinanceVault::isBase64('QUJD') && FinanceVault::isBase64('QUI=') && FinanceVault::isBase64('QQ==') && FinanceVault::isBase64('a+/9'));
check('base64: missing padding, whitespace, url-safe chars, "====" and empty fail',
    !FinanceVault::isBase64('QUI') && !FinanceVault::isBase64("QU\nJD") && !FinanceVault::isBase64('a-_9') && !FinanceVault::isBase64('====')
    && !FinanceVault::isBase64('') && !FinanceVault::isBase64('A===') && !FinanceVault::isBase64(null));
check('base64: decoded length is computed without decoding', FinanceVault::base64Length(base64_encode(random_bytes(16))) === 16
    && FinanceVault::base64Length(base64_encode(random_bytes(17))) === 17 && FinanceVault::base64Length(base64_encode(random_bytes(12))) === 12);

check('label: tags stripped', FinanceVault::cleanLabel('<script>alert(1)</script>Daily <b>backup</b>') === 'alert(1)Daily backup');
check('label: control characters and newlines become one space', FinanceVault::cleanLabel("a\r\n\tb\x07c") === 'a b c');
check('label: Nepali kept, cut to 80 characters', FinanceVault::cleanLabel('दैनिक ब्याकअप') === 'दैनिक ब्याकअप'
    && mb_strlen(FinanceVault::cleanLabel(str_repeat('क', 200)), 'UTF-8') === 80);
check('label: non-scalar becomes empty', FinanceVault::cleanLabel(['x']) === '' && FinanceVault::cleanLabel(null) === '');
check('label: stray angle brackets removed', !str_contains(FinanceVault::cleanLabel('a < b > c'), '<') && !str_contains(FinanceVault::cleanLabel('x<img src=x onerror=1'), '<'));

check('folder: BACKUP_PATH/finance by default', FinanceVault::resolveDir('development', false, '/srv/site/backup') === '/srv/site/backup/finance');
check('folder: SHG_FINANCE_VAULT_DIR honoured outside production', FinanceVault::resolveDir('development', $tmpBase . '/v', '/srv/b') === $tmpBase . '/v');
check('folder: the override is ignored in production', FinanceVault::resolveDir('production', $tmpBase . '/v', '/srv/b') === '/srv/b/finance'
    && FinanceVault::resolveDir('PRODUCTION', $tmpBase . '/v', '/srv/b') === '/srv/b/finance');
check('folder: relative / parentless overrides are ignored', FinanceVault::resolveDir('development', 'rel/dir', '/srv/b') === '/srv/b/finance'
    && FinanceVault::resolveDir('development', '/no/such/parent/dir', '/srv/b') === '/srv/b/finance');
check('folder: the live default is under BACKUP_PATH', FinanceVault::defaultDir() === rtrim(BACKUP_PATH, '/') . '/finance' || getenv('SHG_FINANCE_VAULT_DIR') !== false);

/* ========================================================================
 *  2. Envelope validation — only ciphertext is ever stored
 * ====================================================================== */
echo "\n=== only an encrypted \"enc1\" envelope is accepted ===\n";
check('a real-shaped enc1 envelope passes', vault_code(static fn() => FinanceVault::assertEnvelope(envelope())) === 0);
check('KDF metadata fields (iter, kdf, v) are allowed', vault_code(static fn() => FinanceVault::assertEnvelope(envelope(512, ['iter' => 200000, 'kdf' => 'PBKDF2-SHA256', 'v' => 1]))) === 0);
$plainBook = json_encode(['shgfm' => 'book1', 'app' => '2.0', 'mode' => 'real', 'checksum' => str_repeat('a', 64),
    'book' => ['journals' => [['id' => 'j1', 'memo' => 'salary']], 'accounts' => []]]);
check('a plaintext book (shgfm book1) is refused with 422', vault_code(static fn() => FinanceVault::assertEnvelope($plainBook)) === 422);
check('the plaintext refusal says so', (static function () use ($plainBook): bool {
    try { FinanceVault::assertEnvelope($plainBook); } catch (FinanceVaultError $e) { return stripos($e->getMessage(), 'plaintext') !== false; }
    return false;
})());
check('a bare book object (journals, no shgfm) is refused', vault_code(static fn() => FinanceVault::assertEnvelope('{"journals":[],"accounts":[]}')) === 422);
check('a JSON array is refused', vault_code(static fn() => FinanceVault::assertEnvelope('["enc1"]')) === 422);
check('an empty object is refused', vault_code(static fn() => FinanceVault::assertEnvelope('{}')) === 422);
check('not JSON at all is refused', vault_code(static fn() => FinanceVault::assertEnvelope('hello')) === 422);
check('an empty / non-string payload is refused', vault_code(static fn() => FinanceVault::assertEnvelope('')) === 422
    && vault_code(static fn() => FinanceVault::assertEnvelope(['shgfm' => 'enc1'])) === 422 && vault_code(static fn() => FinanceVault::assertEnvelope(null)) === 422);
$env = json_decode(envelope(), true);
$mut = static function (array $over) use ($env): string { return json_encode(array_merge($env, $over), JSON_UNESCAPED_SLASHES); };
check('wrong marker (enc2) is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['shgfm' => 'enc2']))) === 422);
check('a book smuggled into an extra field is refused', vault_code(static fn() => FinanceVault::assertEnvelope(json_encode($env + ['book' => 'x']))) === 422
    && vault_code(static fn() => FinanceVault::assertEnvelope(json_encode($env + ['note' => 'Ram paid 5000']))) === 422);
check('a nested object anywhere is refused', vault_code(static fn() => FinanceVault::assertEnvelope(json_encode($env + ['iter' => ['n' => 1]]))) === 422);
check('an allowed extra key with odd characters is refused', vault_code(static fn() => FinanceVault::assertEnvelope(json_encode($env + ['kdf' => '<b>x</b>']))) === 422);
check('bad base64 in salt is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['salt' => 'not base64!']))) === 422);
check('unpadded base64 in iv is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['iv' => rtrim(base64_encode(random_bytes(13)), '=')]))) === 422);
check('missing data is refused', vault_code(static fn() => FinanceVault::assertEnvelope(json_encode(['shgfm' => 'enc1', 'salt' => $env['salt'], 'iv' => $env['iv']]))) === 422);
check('salt shorter than 16 bytes is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['salt' => base64_encode(random_bytes(8))]))) === 422);
check('iv of 8 bytes is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['iv' => base64_encode(random_bytes(8))]))) === 422);
check('ciphertext shorter than 32 bytes is refused', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['data' => base64_encode(random_bytes(20))]))) === 422);
check('base64 of READABLE text in data is refused (not ciphertext)', vault_code(static fn() => FinanceVault::assertEnvelope($mut(['data' => base64_encode(str_repeat('{"journals":[{"memo":"cash sale"}]}', 40))]))) === 422);
$overs = '{"shgfm":"enc1","salt":"' . $env['salt'] . '","iv":"' . $env['iv'] . '","data":"' . str_repeat('A', FinanceVault::MAX_PAYLOAD_BYTES) . '"}';
check('a payload over 25 MB is refused with 413', strlen($overs) > FinanceVault::MAX_PAYLOAD_BYTES && vault_code(static fn() => FinanceVault::assertEnvelope($overs)) === 413);
unset($overs);

/* ========================================================================
 *  3. The store — save / list / get / prune / traversal
 * ====================================================================== */
echo "\n=== the store: save, list, get, prune, traversal ===\n";
$dir = $tmpBase . '/backup/finance';
@mkdir($tmpBase . '/backup', 0755, true);
$vault = new FinanceVault($dir);
$by = ['id' => 36, 'name' => 'FVT <Boss>'];
$p1 = envelope(4096);
$s1 = $vault->save($by, 'दैनिक <b>backup</b> one', 'real', hash('sha256', $p1), $p1);
check('save returns a well-formed id', FinanceVault::isValidId($s1['id']), $s1['id']);
check('the id carries today\'s date', str_starts_with($s1['id'], 'vault-' . date('Ymd') . '-'));
check('createdAt is ISO-8601 with the site offset', preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $s1['createdAt']) === 1, $s1['createdAt']);
check('folder created with mode 0750', is_dir($dir) && (fileperms($dir) & 0777) === 0750, sprintf('%o', fileperms($dir) & 0777));
check('folder guard .htaccess says "Require all denied"', str_contains((string) @file_get_contents($dir . '/.htaccess'), 'Require all denied'));
check('empty index.html written', is_file($dir . '/index.html') && filesize($dir . '/index.html') === 0);
$f1 = $dir . '/' . $s1['id'] . '.json';
check('backup file is vault-<id>.json with mode 0640', is_file($f1) && (fileperms($f1) & 0777) === 0640, sprintf('%o', @fileperms($f1) & 0777));
$rec = json_decode((string) file_get_contents($f1), true);
check('stored file is one JSON object with exactly id, createdAt, by, label, mode, sha256, size, payload',
    is_array($rec) && array_keys($rec) === ['id', 'createdAt', 'by', 'label', 'mode', 'sha256', 'size', 'payload']);
check('stored payload is byte-identical, sha256 and size match', ($rec['payload'] ?? null) === $p1 && ($rec['sha256'] ?? '') === hash('sha256', $p1) && ($rec['size'] ?? 0) === strlen($p1));
check('stored label is plain text (tags stripped)', ($rec['label'] ?? '') === 'दैनिक backup one');
check('stored "by" is {id, name} with the name cleaned', ($rec['by'] ?? null) === ['id' => 36, 'name' => 'FVT']);
check('no temp files left behind', glob($dir . '/.tmp-*') === []);

$p2 = envelope(1024); $s2 = $vault->save($by, 'two', 'demo', strtoupper(hash('sha256', $p2)), $p2);
$p3 = envelope(1024); $s3 = $vault->save($by, 'three', 'real', hash('sha256', $p3), $p3);
check('an upper-case sha256 hex is accepted', FinanceVault::isValidId($s2['id']));
$items = $vault->items();
check('list has all three, newest first', array_column($items, 'id') === [$s3['id'], $s2['id'], $s1['id']], implode(',', array_column($items, 'id')));
check('list items carry id, createdAt, size, sha256, label, mode, by — never the payload',
    array_keys($items[0]) === ['id', 'createdAt', 'size', 'sha256', 'label', 'mode', 'by'] && !isset($items[0]['payload']));
check('list item values are right', $items[1]['mode'] === 'demo' && $items[1]['label'] === 'two' && $items[1]['size'] === strlen($p2) && $items[1]['sha256'] === hash('sha256', $p2));
$got = $vault->load($s1['id']);
check('get returns the payload byte-identical', $got['payload'] === $p1 && $got['sha256'] === hash('sha256', $p1));
check('meta() reads only the head and matches list()', $vault->meta($s2['id']) === $items[1]);

$before = count($vault->items());
check('sha256 mismatch is refused with 422', vault_code(static fn() => $vault->save($by, 'x', 'real', hash('sha256', 'something else'), $p1)) === 422);
check('a malformed sha256 is refused with 422', vault_code(static fn() => $vault->save($by, 'x', 'real', 'abc', $p1)) === 422
    && vault_code(static fn() => $vault->save($by, 'x', 'real', null, $p1)) === 422);
check('mode other than real/demo is refused', vault_code(static fn() => $vault->save($by, 'x', 'test', hash('sha256', $p1), $p1)) === 422
    && vault_code(static fn() => $vault->save($by, 'x', null, hash('sha256', $p1), $p1)) === 422);
check('a plaintext book is refused by save() too', vault_code(static fn() => $vault->save($by, 'x', 'real', hash('sha256', $plainBook), $plainBook)) === 422);
check('a refused save writes no file', count($vault->items()) === $before);

check('get with a traversal id is refused 400 before touching a file', vault_code(static fn() => $vault->load('../../config/config.local')) === 400
    && vault_code(static fn() => $vault->pathOf('../' . basename($dir) . '/' . $s1['id'])) === 400);
check('get with a well-formed but unknown id is 404', vault_code(static fn() => $vault->load('vault-20990101-000000-00000000')) === 404);
$outside = $tmpBase . '/outside.json';
file_put_contents($outside, json_encode(['id' => 'vault-20990101-000000-0000000a', 'payload' => 'x', 'sha256' => hash('sha256', 'x')]));
@symlink($outside, $dir . '/vault-20990101-000000-0000000a.json');
check('a symlink that leads out of the folder is not served', vault_code(static fn() => $vault->pathOf('vault-20990101-000000-0000000a')) === 404);
@unlink($dir . '/vault-20990101-000000-0000000a.json');
file_put_contents($dir . '/vault-20000101-000000-0000000b.json', 'garbage, not json');
check('a damaged file is skipped by list and reported 500 by get', !in_array('vault-20000101-000000-0000000b', array_column($vault->items(), 'id'), true)
    && vault_code(static fn() => $vault->meta('vault-20000101-000000-0000000b')) === 500);
@unlink($dir . '/vault-20000101-000000-0000000b.json');
file_put_contents($dir . '/notes.json', '{}');
file_put_contents($dir . '/vault-x.json', '{}');
check('files that are not vault-<id>.json are never listed', count($vault->items()) === 3);
$t = (string) file_get_contents($f1);
file_put_contents($f1, str_replace('"payload":"{', '"payload":"{ ', $t));
check('a stored payload changed on disk fails its checksum (500)', vault_code(static fn() => $vault->load($s1['id'])) === 500);
file_put_contents($f1, $t);
check('…and loads again once restored', $vault->load($s1['id'])['payload'] === $p1);

// Prune: 125 more saves → exactly 120 remain, the newest kept, the oldest gone.
$saved = [];
$prunedTotal = 0;
for ($i = 0; $i < 125; $i++) {
    $p = envelope(64);
    $r = $vault->save($by, 'p' . $i, 'demo', hash('sha256', $p), $p);
    $saved[] = $r['id'];
    $prunedTotal += $r['pruned'];
}
$ids = array_column($vault->items(), 'id');
check('after 128 saves exactly 120 backups remain', count($ids) === FinanceVault::KEEP, (string) count($ids));
check('the newest backup is always kept', $ids[0] === end($saved));
check('the oldest ones were the ones removed', !in_array($s1['id'], $ids, true) && !in_array($saved[0], $ids, true) && in_array($saved[8], $ids, true));
check('save() reports how many it pruned (8 in total)', $prunedTotal === 8, (string) $prunedTotal);
check('ids stay in save order even within one second', $ids === array_reverse(array_slice($saved, -120)));
check('prune never removes the id it is told to protect', (static function () use ($vault): bool {
    $all = array_column($vault->items(), 'id');
    $oldest = end($all);
    $vault->prune(0, $oldest);
    $left = array_column($vault->items(), 'id');
    return $left === [$oldest];
})());
check('unrelated files in the folder are left alone', is_file($dir . '/notes.json') && is_file($dir . '/.htaccess') && is_file($dir . '/index.html'));

/* ========================================================================
 *  4. The request handler — walls in order, CSRF, rate limit, audit
 * ====================================================================== */
echo "\n=== finance_vault_handle(): who may call it, CSRF, limits, audit ===\n";
$hv = new FinanceVault($tmpBase . '/h/finance');
@mkdir($tmpBase . '/h', 0755, true);
$allow = static fn(string $who): bool => true;
$get = static fn(array $q = ['action' => 'list']): array => finance_vault_handle($hv, ['method' => 'GET', 'query' => $q], $allow);

as_staff(null);
$r = $get();
check('signed out → 401', $r['status'] === 401 && ($r['json']['ok'] ?? true) === false && ($r['json']['code'] ?? 0) === 401);
foreach ([['accountant', []], ['manager', []], ['support', []], ['counter', []], ['official', []], ['scanner', []]] as [$role, $perms]) {
    as_staff($role, $perms);
    $r = $get();
    check("$role without the finance.vault grant → 403", $r['status'] === 403, (string) $r['status']);
}
as_staff('agent', ['finance.vault', 'reports.view']);
check('a counter agent is refused even WITH the finance.vault grant (403)', $get()['status'] === 403 && !FinanceVault::mayUse());
as_staff('manager', ['finance.vault']);
check('a manager with the finance.vault grant may list', $get()['status'] === 200 && FinanceVault::mayUse());
as_staff('superadmin');
$r = $get();
check('superadmin may list', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['items'] ?? null) === []);
check('list reports keep=120, maxBytes=25 MB and the site time zone', ($r['json']['keep'] ?? 0) === 120 && ($r['json']['maxBytes'] ?? 0) === 25 * 1024 * 1024 && ($r['json']['tz'] ?? '') === APP_TIMEZONE);
check('PUT / DELETE → 405', finance_vault_handle($hv, ['method' => 'PUT'], $allow)['status'] === 405 && finance_vault_handle($hv, ['method' => 'DELETE'], $allow)['status'] === 405);
check('unknown GET action → 400', $get(['action' => 'nuke'])['status'] === 400);

$token = Security::csrfToken();
$hp = envelope(3000);
$body = json_encode(['action' => 'save', 'label' => 'Daily auto backup', 'mode' => 'real', 'sha256' => hash('sha256', $hp), 'payload' => $hp]);
$post = static function (?string $csrf, $bodyArg, ?Closure $lim = null, int $cl = 0) use ($hv, $allow): array {
    if ($csrf === null) { unset($_SERVER['HTTP_X_CSRF_TOKEN']); } else { $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf; }
    $_POST = [];
    $res = finance_vault_handle($hv, ['method' => 'POST', 'body' => $bodyArg, 'contentLength' => $cl], $lim ?? $allow);
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    return $res;
};
check('POST without the X-CSRF-Token header → 419', $post(null, $body)['status'] === 419);
check('POST with a wrong token → 419', $post('deadbeef' . substr($token, 8), $body)['status'] === 419);
$bodyRead = false;
as_staff('accountant');
$post($token, static function () use (&$bodyRead, $body): string { $bodyRead = true; return $body; });
check('a refused caller never gets the body read (permission checked first)', $bodyRead === false);
as_staff('superadmin');
$bodyRead = false;
$r = $post($token, static function () use (&$bodyRead): string { $bodyRead = true; return '{}'; }, null, FinanceVault::MAX_BODY_BYTES + 1);
check('Content-Length over the limit → 413 before the body is read', $r['status'] === 413 && $bodyRead === false);
check('a body over the limit → 413', $post($token, str_repeat(' ', FinanceVault::MAX_BODY_BYTES + 1))['status'] === 413);
check('an empty body → 400', $post($token, '')['status'] === 400);
check('a non-JSON body → 400', $post($token, 'label=x')['status'] === 400);
check('an unknown POST action → 400', $post($token, json_encode(['action' => 'delete', 'id' => 'x']))['status'] === 400);
check('POST plaintext book → 422', $post($token, json_encode(['action' => 'save', 'mode' => 'real', 'sha256' => hash('sha256', $plainBook), 'payload' => $plainBook]))['status'] === 422);
check('POST with a sha256 mismatch → 422', $post($token, json_encode(['action' => 'save', 'mode' => 'real', 'sha256' => str_repeat('0', 64), 'payload' => $hp]))['status'] === 422);
check('POST with the payload as an object (not a string) → 422', $post($token, json_encode(['action' => 'save', 'mode' => 'real', 'sha256' => hash('sha256', $hp), 'payload' => json_decode($hp, true)]))['status'] === 422);
$limitedCalls = 0;
$r = $post($token, $body, static function (string $who) use (&$limitedCalls): bool { $limitedCalls++; return false; });
check('rate limiter says no → 429 (keyed admin:<id>)', $r['status'] === 429 && $limitedCalls === 1);
$r = $post($token, static fn(): string => $body);
check('POST save with the right token → 200 {ok, id, createdAt, size, sha256, pruned}', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true
    && FinanceVault::isValidId($r['json']['id'] ?? '') && ($r['json']['size'] ?? 0) === strlen($hp) && ($r['json']['sha256'] ?? '') === hash('sha256', $hp)
    && array_key_exists('pruned', $r['json']) && array_key_exists('createdAt', $r['json']), json_encode($r['json']));
$hid = (string) ($r['json']['id'] ?? '');
$l = $get();
check('it is listed with its label, mode and saver', ($l['json']['items'][0]['id'] ?? '') === $hid && $l['json']['items'][0]['label'] === 'Daily auto backup'
    && $l['json']['items'][0]['mode'] === 'real' && $l['json']['items'][0]['by'] === ['id' => 990001, 'name' => 'FVT Staff']);
$g = $get(['action' => 'get', 'id' => $hid]);
$wire = isset($g['stream']) ? $g['prefix'] . (string) file_get_contents($g['stream']) . $g['suffix'] : '';
$wj = json_decode($wire, true);
check('get streams {ok:true, item:{…}} that decodes as JSON', $g['status'] === 200 && is_array($wj) && ($wj['ok'] ?? false) === true);
check('round trip: the payload comes back byte-identical and matches its sha256',
    ($wj['item']['payload'] ?? null) === $hp && ($wj['item']['sha256'] ?? '') === hash('sha256', $hp) && ($wj['item']['id'] ?? '') === $hid);
check('get item carries id, createdAt, sha256, label, mode, payload', is_array($wj['item'] ?? null) && count(array_diff(['id', 'createdAt', 'sha256', 'label', 'mode', 'payload'], array_keys($wj['item']))) === 0);
check('get with a traversal id → 400', $get(['action' => 'get', 'id' => '../../config/config.local'])['status'] === 400
    && $get(['action' => 'get', 'id' => ['x']])['status'] === 400 && $get(['action' => 'get'])['status'] === 400);
check('get with an unknown id → 404', $get(['action' => 'get', 'id' => 'vault-20990101-000000-00000000'])['status'] === 404);
as_staff('accountant');
check('get is walled like list (accountant → 403)', $get(['action' => 'get', 'id' => $hid])['status'] === 403);
as_staff('superadmin');

$auditSave = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'finance.vault_save' AND entity_id = :e", ['s' => $auditStart, 'e' => $hid], 0);
$auditGet  = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :s AND action = 'finance.vault_get' AND entity_id = :e", ['s' => $auditStart, 'e' => $hid], 0);
check('the save wrote one finance.vault_save audit row', $auditSave === 1, (string) $auditSave);
check('the download wrote one finance.vault_get audit row', $auditGet === 1, (string) $auditGet);
$auditNew = (string) Database::scalar("SELECT new_value FROM audit_logs WHERE id > :s AND action = 'finance.vault_save' AND entity_id = :e", ['s' => $auditStart, 'e' => $hid], '');
check('the audit row records size + sha256 but never the payload', str_contains($auditNew, hash('sha256', $hp)) && !str_contains($auditNew, json_decode($hp, true)['data']));

// The real limiter (Security::rateLimit, DB-backed): 30 calls a minute, the 31st is refused.
as_staff('superadmin', [], 990077);
Database::query("DELETE FROM rate_limits WHERE bucket = 'finance_vault' AND identifier = 'admin:990077'");
$codes = [];
for ($i = 0; $i < 31; $i++) { $codes[] = finance_vault_handle($hv, ['method' => 'GET', 'query' => ['action' => 'list']])['status']; }
check('30 calls a minute pass, the 31st gets 429 (real DB limiter)', count(array_filter(array_slice($codes, 0, 30), static fn($c) => $c === 200)) === 30 && $codes[30] === 429,
    implode(',', array_unique($codes)));
Database::query("DELETE FROM rate_limits WHERE bucket = 'finance_vault' AND identifier = 'admin:990077'");
as_staff('superadmin');

/* ========================================================================
 *  5. admin/finance.php — meta injection, role flags, override, CSP
 * ====================================================================== */
echo "\n=== admin/finance.php — injected meta, flags, override, CSP ===\n";
$meta = ['id' => 7, 'name' => "Ram \"<b>x</b>\" & 'y' राम", 'role' => 'superadmin', 'can' => ['vault' => true, 'feed' => true]];
$sample = "<!doctype html>\n<!-- the <head> in this comment is not the tag -->\n<html lang=\"ne\">\n<HEAD data-x=\"1\">\n<title>t</title>\n</head>\n<body><header>h</header><script>var s='<head>';</script></body></html>\n";
$out = finance_inject_meta($sample, 'tok"<en>', $meta);
$at = strpos($out, '<HEAD data-x="1">');
$after = "\n<meta name=\"csrf\" content=";
check('meta tags go right after the first real <head> tag (attributes, any case)', $at !== false && substr($out, $at + strlen('<HEAD data-x="1">'), strlen($after)) === $after);
check('…exactly once', substr_count($out, '<meta name="csrf"') === 1 && substr_count($out, '<meta name="shg-admin"') === 1);
check('…not inside the comment, not after <header>', strpos($out, '<meta name="csrf"') > strpos($out, '-->') && strpos($out, '<meta name="csrf"') < strpos($out, '<header>'));
check('…and nothing else changed', str_replace(["\n<meta name=\"csrf\" content=\"tok&quot;&lt;en&gt;\">", "\n<meta name=\"shg-admin\" content=\"" . htmlspecialchars((string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">"], '', $out) === $sample);
preg_match('/<meta name="shg-admin" content="([^"]*)">/', $out, $mm);
$attr = (string) ($mm[1] ?? '');
check('shg-admin attribute holds no raw quote, < or >', $attr !== '' && strpbrk($attr, "\"'<>") === false);
check('shg-admin decodes back to the exact JSON (name with quotes, tags, & and Nepali)', json_decode(html_entity_decode($attr, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) === $meta);
$dom = new DOMDocument();
@$dom->loadHTML('<?xml encoding="utf-8"?>' . $out);
$domMeta = null; $domCsrf = null;
foreach ($dom->getElementsByTagName('meta') as $m) {
    if ($m->getAttribute('name') === 'shg-admin') { $domMeta = $m->getAttribute('content'); }
    if ($m->getAttribute('name') === 'csrf') { $domCsrf = $m->getAttribute('content'); }
}
check('an HTML parser reads the same JSON and token back', $domMeta !== null && json_decode($domMeta, true) === $meta && $domCsrf === 'tok"<en>');
$noHead = finance_inject_meta("<!doctype html><html><title>x</title></html>", 't', $meta);
check('no <head>: the tags go right after <html>', str_starts_with($noHead, "<!doctype html><html>\n<meta name=\"csrf\""));
check('no <html> either: the tags go first', str_starts_with(finance_inject_meta('<p>x</p>', 't', $meta), '<meta name="csrf"'));
check('<header> alone is never taken for <head>', str_starts_with(finance_inject_meta('<header>x</header>', 't', $meta), '<meta name="csrf"'));

// Role flags — must agree with the vault's own wall.
$flagsFor = static function (string $role, array $perms = []): array {
    as_staff($role, $perms);
    $m = finance_admin_meta(Auth::admin() ?? []);
    return [$m['can']['vault'], $m['can']['feed'], FinanceVault::mayUse(), $m];
};
[$v, $f, $mu, $m] = $flagsFor('superadmin');
check('superadmin: can.vault and can.feed', $v === true && $f === true && $mu === true);
check('meta carries id, name, role and nothing else', array_keys($m) === ['id', 'name', 'role', 'can'] && $m['name'] === 'FVT Staff' && $m['role'] === 'superadmin' && $m['id'] === 990001);
[$v, $f, $mu] = $flagsFor('accountant');
check('accountant: feed yes, vault no (no grant)', $v === false && $f === true && $mu === false);
[$v, $f, $mu] = $flagsFor('manager', ['finance.vault']);
check('manager with finance.vault: both', $v === true && $f === true && $mu === true);
[$v, $f, $mu] = $flagsFor('agent', ['finance.vault', 'reports.view']);
check('counter agent with grants: neither (never vault, never feed)', $v === false && $f === false && $mu === false);
[$v, $f, $mu] = $flagsFor('support');
check('support: neither', $v === false && $f === false && $mu === false);
as_staff('superadmin');

// The dev-only override.
$def = '/srv/default.html';
$alt = $tmpBase . '/alt-build.html';
file_put_contents($alt, '<!doctype html><html><head></head><body>alt</body></html>');
$txt = $tmpBase . '/notes.txt';
file_put_contents($txt, 'x');
@symlink($txt, $tmpBase . '/sneaky.html');
check('override honoured outside production', finance_app_file('development', $alt, $def) === realpath($alt));
check('override ignored in production', finance_app_file('production', $alt, $def) === $def && finance_app_file('Production', $alt, $def) === $def);
check('override ignored when not set / empty', finance_app_file('development', false, $def) === $def && finance_app_file('development', '', $def) === $def);
check('override must be a *.html file', finance_app_file('development', $txt, $def) === $def);
check('override must exist', finance_app_file('development', $tmpBase . '/missing.html', $def) === $def);
check('a *.html symlink to a non-html file is refused (realpath checked)', finance_app_file('development', $tmpBase . '/sneaky.html', $def) === $def);

$csp = finance_csp();
foreach (["default-src 'self'", "script-src 'self' 'unsafe-inline'", "connect-src 'self';", "img-src 'self' data: blob:;",
          "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", "font-src 'self' data: https://fonts.gstatic.com",
          "frame-src 'self' blob:;", "worker-src 'self' blob:;", "media-src 'self' data: blob:;",
          "frame-ancestors 'self'", "object-src 'none'", "base-uri 'none'", "form-action 'self'"] as $d) {
    check("page CSP has: $d", str_contains($csp, $d));
}
check('page CSP has no unsafe-eval, no third-party script / connect / frame / worker host, no https: image wildcard',
    !str_contains($csp, 'unsafe-eval') && !preg_match("/(script|connect|img|frame|worker|media)-src[^;]*https?:/", $csp) && !str_contains($csp, '*'));
check('page CSP is tighter than the site-wide one (no CDN, no map hosts)', !str_contains($csp, 'cdnjs') && !str_contains($csp, 'unpkg') && !str_contains($csp, 'openfreemap'));

$fin = $src('admin/finance.php');
check('finance.php still gated on reports.view', str_contains($fin, "\$admin = admin_boot('reports.view');"));
check('finance.php sends its CSP replacing the site-wide one', str_contains($fin, "header('Content-Security-Policy: ' . finance_csp(), true);"));
check('finance.php: download branch streams the untouched file as an attachment and exits before any injection',
    preg_match("/if \(\\\$download\) \{.*?filesize\(\\\$file\).*?Content-Disposition: attachment.*?readfile\(\\\$file\);\s*exit;\s*\}/s", $fin) === 1
    && strpos($fin, 'readfile($file);') < strpos($fin, '$html = finance_inject_meta('));
check('finance.php: Content-Length is computed on the injected HTML', preg_match('/\$html = finance_inject_meta\(.*?header\(\'Content-Length: \' \. \(string\) strlen\(\$html\)\);\s*echo \$html;/s', $fin) === 1);
check('finance.php: the csrf comes from Security::csrfToken()', str_contains($fin, 'finance_inject_meta($html, Security::csrfToken(), finance_admin_meta($admin))'));
check('finance.php: SHG_FINANCE_HTML read only through finance_app_file(APP_ENV, …)', str_contains($fin, "finance_app_file(APP_ENV, getenv('SHG_FINANCE_HTML'), INCLUDE_PATH . '/finance/shg-finance-master.html')")
    && substr_count($fin, 'SHG_FINANCE_HTML') >= 1 && !preg_match('/readfile\(getenv/', $fin));
check('finance.php keeps the open / download audit rows', str_contains($fin, "'finance.app_download' : 'finance.app_open'"));
check('finance.php keeps no-store + noindex', str_contains($fin, "header('Cache-Control: private, no-store, max-age=0');") && str_contains($fin, "header('X-Robots-Tag: noindex, nofollow');"));

// The real build still works after injection (engine test extracts the script blocks).
$appFile = getenv('SHG_FINANCE_HTML') ?: ROOT_PATH . '/includes/finance/shg-finance-master.html';
if (is_file($appFile)) {
    $real = (string) file_get_contents($appFile);
    $inj = finance_inject_meta($real, 'abc123', $m);
    $headAt = stripos($real, '<head>');
    check('real app: injected right after its first <head>', $headAt !== false && substr($inj, $headAt + 6, 24) === "\n<meta name=\"csrf\" conte");
    check('real app: the rest of the file is untouched', strlen($inj) > strlen($real) && substr($inj, 0, $headAt + 6) === substr($real, 0, $headAt + 6)
        && substr($inj, -1000) === substr($real, -1000));
    $injFile = $tmpBase . '/injected.html';
    file_put_contents($injFile, $inj);
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '' && is_file(ROOT_PATH . '/tests/finance-master-test.js')) {
        exec(escapeshellarg($node) . ' ' . escapeshellarg(ROOT_PATH . '/tests/finance-master-test.js') . ' ' . escapeshellarg($injFile) . ' 2>&1', $nodeOut, $nodeCode);
        check('real app: node tests/finance-master-test.js passes on the injected page', $nodeCode === 0, (string) end($nodeOut));
    } else {
        skip('node not installed — engine test on the injected page');
    }
} else {
    skip('no app file to inject into (' . $appFile . ')');
}

/* ========================================================================
 *  6. Vault endpoint — source-level walls
 * ====================================================================== */
echo "\n=== admin/api/finance-vault.php — source-level walls ===\n";
$vs = $src('admin/api/finance-vault.php');
check('lives under admin/api/ (JSON 401/403, no HTML CSP)', $vs !== '');
check('boots through _guard.php and admin_boot()', str_contains($vs, "require_once dirname(__DIR__) . '/_guard.php';") && str_contains($vs, "\nadmin_boot();"));
check('vault wall: superadmin or finance.vault, never a counter agent', str_contains($vs, "Auth::admin() === null || Auth::isCounterAgent()") && str_contains($vs, "return Auth::isSuperadmin() || Auth::can('finance.vault');"));
check('POST verified with Security::verifyCsrf()', str_contains($vs, "if (\$method === 'POST' && !Security::verifyCsrf())"));
check('rate limit bucket finance_vault, 30 per 60 s, keyed admin:<id>', str_contains($vs, "RATE_BUCKET = 'finance_vault'") && str_contains($vs, 'RATE_HITS   = 30') && str_contains($vs, 'RATE_WINDOW = 60') && str_contains($vs, "'admin:' . (int) \$admin['id']"));
check('both audit actions are written', str_contains($vs, "Logger::audit('finance.vault_save'") && str_contains($vs, "Logger::audit('finance.vault_get'"));
check('the id is validated before any path is built', preg_match('/function pathOf\(mixed \$id\): string\s*\{\s*if \(!self::isValidId\(\$id\)\)/', $vs) === 1);
check('limits: 25 MB payload, keep 120', str_contains($vs, 'MAX_PAYLOAD_BYTES = 25 * 1024 * 1024') && str_contains($vs, 'KEEP = 120'));
check('modes: folder 0750, files 0640', str_contains($vs, 'mkdir($this->dir, 0750, true)') && str_contains($vs, 'chmod($tmp, 0640)'));
check('the payload is never echoed into a log line', !preg_match('/Logger::[a-z]+\([^;]*\$payload/', $vs));
$ngx = $src('deploy/nginx-shreehariglobal.in.conf');
check('nginx denies /backup/ (where the vault lives)', preg_match('#location ~ \^/\([^)]*\bbackup\b[^)]*\)/ \{\s*deny all;#', $ngx) === 1);

/* ========================================================================
 *  7. Deep links — source level: gated, root-relative, escaped
 * ====================================================================== */
echo "\n=== deep links into the app — every one gated on reports.view and not a counter agent ===\n";
$GATE = "Auth::can('reports.view') && !Auth::isCounterAgent()";
$expect = [
    'admin/booking-view.php' => ['🔎 Finance trace', "'/admin/finance.php?go=trace&q=' . rawurlencode(", 'Security::e('],
    'admin/agent.php'        => ['💼 Finance statement', "'/admin/finance.php?go=statements&type=agent&seller=' . \$viewId", 'Security::e('],
    'admin/agents.php'       => ['💼 Finance statement', "'/admin/finance.php?go=statements&type=agent&seller=' . (int) \$r['id']", '$e('],
    'admin/agent-360.php'    => ['💼 Finance statement', "'/admin/finance.php?go=statements&type=agent&seller=' . \$agentId", '$e('],
    'admin/accounting.php'   => ['💼 Finance Master — reconcile', "'/admin/finance.php?go=website&tab=recon&from=' . rawurlencode(\$from) . '&to=' . rawurlencode(\$to)", 'Security::e('],
    'admin/index.php'        => ['💼 Finance Master', "'/admin/finance.php?go=dash'", '$e('],
];
foreach ($expect as $file => [$label, $url, $escaper]) {
    $s = $src($file);
    $n = substr_count($s, '/admin/finance.php?go=');
    check("$file has its finance link", $n >= 1 && str_contains($s, $label) && str_contains($s, $url));
    check("$file link is escaped with the page's helper", str_contains($s, $escaper . $url));
    check("$file link is root-relative (never page-relative finance.php)", !preg_match('/["\']finance\.php\?go=/', $s));
    $gated = 0;
    $offset = 0;
    while (($pos = strpos($s, '/admin/finance.php?go=', $offset)) !== false) {
        $ctx = substr($s, max(0, $pos - 700), 700);
        $varGate = str_contains($ctx, '$canFinance') && str_contains($s, '$canFinance = ' . $GATE . ';');
        if (str_contains($ctx, $GATE) || $varGate) { $gated++; }
        $offset = $pos + 1;
    }
    check("$file: every finance link sits behind \"$GATE\" ($gated of $n)", $gated === $n && $n > 0);
}
check('agent.php never shows it on an agent\'s own panel', str_contains($src('admin/agent.php'), "(!\$isOwn && $GATE"));
check('agents.php gates each row with $canFinance', str_contains($src('admin/agents.php'), '<?php if ($canFinance): ?><a class="btn ghost" href="<?= $e(\'/admin/finance.php?go=statements'));
check('booking-view.php gate wraps the button in the toolbar', str_contains($src('admin/booking-view.php'), "<?php if ($GATE): ?>\n    <a class=\"btn ghost\" href=\"<?= Security::e('/admin/finance.php?go=trace"));
check('no page links the app with a #hash (it would not survive the login redirect)', !preg_match('~finance\.php#~', implode('', array_map($src, array_keys($expect)))));
check('the existing agent-360 / agents wiring is still in place', str_contains($src('admin/agents.php'), 'href="agent-360.php?agent=<?= (int) $r[\'id\']') && str_contains($src('admin/agent-360.php'), "Auth::requireAdmin('commissions.view')"));

/* ========================================================================
 *  8. Deep links — rendered per role (child process, faked session)
 * ====================================================================== */
echo "\n=== deep links rendered per role ===\n";
$render = static function (string $role, string $page, array $args = []): string {
    $cmd = array_merge([PHP_BINARY, __FILE__, '--render', $role, $page], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    return $out;
};
$pnr  = (string) Database::scalar("SELECT pnr FROM bookings ORDER BY id DESC LIMIT 1", [], '');
$agId = (int) Database::scalar("SELECT id FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($pnr !== '') {
    $want = 'href="/admin/finance.php?go=trace&amp;q=' . rawurlencode($pnr) . '"';
    foreach (['superadmin' => true, 'manager' => true, 'accountant' => true, 'support' => false, 'counter' => false] as $role => $shown) {
        $h = $render($role, 'booking-view.php', ['pnr=' . $pnr]);
        $has = str_contains($h, $want) && str_contains($h, 'Finance trace');
        check("booking-view.php as $role: Finance trace " . ($shown ? 'shown' : 'hidden'), $h !== '' && $has === $shown && str_contains($h, $pnr), $h === '' ? 'empty render' : '');
    }
    $h = $render('agent', 'booking-view.php', ['pnr=' . $pnr, '@perms=reports.view']);
    check('booking-view.php as a counter agent holding a reports.view grant: hidden', !str_contains($h, 'Finance trace'));
} else {
    skip('no booking in the test database — booking-view render');
}
if ($agId > 0) {
    $want = 'href="/admin/finance.php?go=statements&amp;type=agent&amp;seller=' . $agId . '"';
    foreach (['superadmin' => true, 'manager' => true, 'accountant' => true] as $role => $shown) {
        $h = $render($role, 'agent.php', ['agent=' . $agId]);
        check("agent.php?agent=$agId as $role: Finance statement shown", str_contains($h, $want), $h === '' ? 'empty render' : '');
    }
    $h2 = $render('agent', 'agent.php', ['@id=' . $agId, '@perms=reports.view']);
    check('agent.php — an agent on their own panel (even with a reports.view grant): hidden', str_contains($h2, 'My Agent Panel') && !str_contains($h2, 'Finance statement') && !str_contains($h2, 'finance.php?go='));
    $h = $render('superadmin', 'agent-360.php', ['agent=' . $agId]);
    check('agent-360.php as superadmin: Finance statement in the header', str_contains($h, $want));
    $h = $render('superadmin', 'agents.php');
    $rows = substr_count($h, 'agent-360.php?agent=');
    check('agents.php as superadmin: one Finance statement per agent row', $rows > 0 && substr_count($h, '/admin/finance.php?go=statements&amp;type=agent&amp;seller=') === substr_count($h, '>💼 Wallet</a>'), "rows with wallet=" . substr_count($h, '>💼 Wallet</a>'));
    $h = $render('manager', 'agents.php', ['@perms=']);
    check('agents.php as manager: shown', str_contains($h, '/admin/finance.php?go=statements&amp;type=agent&amp;seller='));
} else {
    skip('no agent in the test database — agent/agents/agent-360 render');
}
$want = 'href="/admin/finance.php?go=website&amp;tab=recon&amp;from=2026-10-01&amp;to=2026-10-09"';
foreach (['superadmin' => true, 'accountant' => true, 'counter' => false, 'official' => false] as $role => $shown) {
    $h = $render($role, 'accounting.php', ['from=2026-10-01', 'to=2026-10-09']);
    check("accounting.php as $role: reconcile link " . ($shown ? 'shown, carrying the page range' : 'hidden'), $h !== '' && str_contains($h, $want) === $shown && str_contains($h, 'Day-book'));
}
$h = $render('accountant', 'accounting.php', ['from=2026-10-09', 'to=2026-10-01']);
check('accounting.php: a reversed range is passed on in order', str_contains($h, 'from=2026-10-01&amp;to=2026-10-09'));
foreach (['superadmin' => true, 'manager' => true, 'accountant' => true, 'support' => false] as $role => $shown) {
    $h = $render($role, 'index.php');
    check("index.php as $role: Finance Master action " . ($shown ? 'shown' : 'hidden'), $h !== '' && str_contains($h, 'href="/admin/finance.php?go=dash"') === $shown);
}

/* ========================================================================
 *  9. HTTP round trip on :8892 (own php -S, temp vault folder)
 * ====================================================================== */
echo "\n=== HTTP round trip (php -S on its own port, temp vault folder) ===\n";
$port = (int) (getenv('SHG_VAULT_PORT') ?: 8892);
$base = 'http://127.0.0.1:' . $port;
$httpDir = $tmpBase . '/http-vault';
$httpApp = $tmpBase . '/http-app.html';
if (getenv('SHG_VAULT_HTTP') === '0') {
    skip('HTTP round trip switched off (SHG_VAULT_HTTP=0)');
} elseif (!function_exists('curl_init')) {
    skip('php-curl missing — HTTP round trip');
} elseif (!is_file($appFile)) {
    skip('no app file — HTTP round trip');
} elseif (@fsockopen('127.0.0.1', $port, $en, $es, 0.3) !== false) {
    skip("port $port is busy — HTTP round trip (set SHG_VAULT_PORT)");
} else {
    file_put_contents($httpApp, str_replace('</html>', "<!-- fvt-override-marker -->\n</html>", (string) file_get_contents($appFile)));
    $envVars = getenv();
    $envVars['SHG_FINANCE_VAULT_DIR'] = $httpDir;
    $envVars['SHG_FINANCE_HTML'] = $httpApp;
    $serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', ROOT_PATH], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp, ROOT_PATH, $envVars);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $up = @fsockopen('127.0.0.1', $port, $en, $es, 0.2) !== false; }
    if (!$up) {
        check("php -S came up on :$port", false);
    } else {
        $req = static function (string $jar, string $method, string $url, array $opt = []) use ($base): array {
            $ch = curl_init($base . $url);
            $headers = $opt['headers'] ?? [];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
                CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
            if (isset($opt['json'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($opt['json']) ? $opt['json'] : json_encode($opt['json']));
                $headers[] = 'Content-Type: application/json';
                if (!empty($opt['csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['csrf']; }
            } elseif (isset($opt['form'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
            }
            if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
            $raw = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsz = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $head = substr($raw, 0, $hsz);
            $body = substr($raw, $hsz);
            $h = [];
            foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
            }
            $json = ($body !== '' && ($body[0] === '{' || $body[0] === '[')) ? json_decode($body, true) : null;
            return ['code' => $code, 'body' => $body, 'h' => $h, 'head' => $head, 'json' => is_array($json) ? $json : null];
        };
        $jar = static function (string $n) use ($tmpBase): string { return $tmpBase . '/jar-' . $n . '.txt'; };
        $signIn = static function (string $user, string $pw) use ($req, $jar): string {
            $j = $jar($user);
            $lp = $req($j, 'GET', '/admin/login.php');
            preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
            $req($j, 'POST', '/admin/login.php', ['form' => ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]]);
            return $j;
        };
        $metaOf = static function (string $html): array {
            preg_match('/<meta name="csrf" content="([^"]*)">/', $html, $c);
            preg_match('/<meta name="shg-admin" content="([^"]*)">/', $html, $a);
            $adm = isset($a[1]) ? json_decode(html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) : null;
            return [(string) ($c[1] ?? ''), is_array($adm) ? $adm : null];
        };

        Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket = 'finance_vault'");
        $PW = 'FvtVault@12345';
        $mk = static function (string $user, string $role, array $perms) use ($PW): int {
            Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]);
            return (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($PW, PASSWORD_BCRYPT),
                'full_name' => 'FVT ' . $role . ' "<x>"', 'role' => $role, 'permissions' => json_encode($perms), 'is_active' => 1, 'must_change_pw' => 0]);
        };
        $httpAdmins = [
            'fvt-boss'  => $mk('fvt-boss', 'superadmin', []),
            'fvt-acct'  => $mk('fvt-acct', 'accountant', []),
            'fvt-agent' => $mk('fvt-agent', 'agent', ['finance.vault', 'reports.view']),
            'fvt-mgr'   => $mk('fvt-mgr', 'manager', ['finance.vault']),
        ];

        $anon = $jar('anon');
        $r = $req($anon, 'GET', '/admin/api/finance-vault.php?action=list');
        check('signed out: vault list → 401 JSON', $r['code'] === 401 && ($r['json']['ok'] ?? true) === false && ($r['json']['code'] ?? 0) === 401, 'HTTP ' . $r['code']);
        $r = $req($anon, 'POST', '/admin/api/finance-vault.php', ['json' => ['action' => 'save']]);
        check('signed out: vault save → 401 JSON', $r['code'] === 401);
        $r = $req($anon, 'GET', '/admin/finance.php?go=trace&q=SHG-2026-00001');
        check('signed out: finance.php deep link → login, keeping ?go= in next', $r['code'] === 302 && str_contains($r['h']['location'] ?? '', 'login.php?next=' . rawurlencode('finance.php?go=trace&q=SHG-2026-00001')), $r['h']['location'] ?? '');

        $jb = $signIn('fvt-boss', $PW);
        $r = $req($jb, 'GET', '/admin/finance.php?go=trace&q=SHG-2026-00001');
        [$csrf, $adm] = $metaOf($r['body']);
        check('superadmin: finance.php?go=… → 200 with the app', $r['code'] === 200 && str_contains($r['body'], 'shg-engine'), 'HTTP ' . $r['code']);
        check('the dev override served the SHG_FINANCE_HTML build', str_contains($r['body'], 'fvt-override-marker'));
        check('Content-Length equals the bytes actually sent', isset($r['h']['content-length']) && (int) $r['h']['content-length'] === strlen($r['body']), ($r['h']['content-length'] ?? '-') . ' vs ' . strlen($r['body']));
        check('csrf meta present, right after <head>', $csrf !== '' && preg_match('/<head>\n<meta name="csrf" content="[a-f0-9]{64}">\n<meta name="shg-admin" content="/', $r['body']) === 1);
        check('shg-admin meta: id, escaped name, role, can.vault + can.feed', is_array($adm) && $adm['id'] === $httpAdmins['fvt-boss'] && $adm['name'] === 'FVT superadmin "<x>"'
            && $adm['role'] === 'superadmin' && $adm['can'] === ['vault' => true, 'feed' => true]);
        check('the name is HTML-escaped in the page', str_contains($r['body'], 'FVT superadmin \&quot;&lt;x&gt;\&quot;') || str_contains($r['body'], 'FVT superadmin &quot;&lt;x&gt;&quot;'));
        $csp = $r['h']['content-security-policy'] ?? '';
        check('the page gets its own tight CSP (connect-src self only), sent once', $csp === finance_csp() && substr_count(strtolower($r['head']), 'content-security-policy:') === 1, $csp);
        check('no-store + noindex kept', str_contains($r['h']['cache-control'] ?? '', 'no-store') && str_contains($r['h']['x-robots-tag'] ?? '', 'noindex'));
        $d = $req($jb, 'GET', '/admin/finance.php?download=1');
        check('download=1: attachment, byte-identical to the file, no token or staff details inside',
            $d['code'] === 200 && str_contains($d['h']['content-disposition'] ?? '', 'attachment') && $d['body'] === file_get_contents($httpApp)
            && !str_contains($d['body'], 'name="csrf"') && !str_contains($d['body'], 'shg-admin" content') && (int) ($d['h']['content-length'] ?? -1) === strlen($d['body']));

        $pl = envelope(5000);
        $save = ['action' => 'save', 'label' => 'HTTP <i>test</i>', 'mode' => 'demo', 'sha256' => hash('sha256', $pl), 'payload' => $pl];
        $r = $req($jb, 'POST', '/admin/api/finance-vault.php', ['json' => $save]);
        check('save without X-CSRF-Token → 419', $r['code'] === 419 && ($r['json']['code'] ?? 0) === 419, 'HTTP ' . $r['code']);
        $r = $req($jb, 'POST', '/admin/api/finance-vault.php', ['json' => $save, 'csrf' => $csrf]);
        $sid = (string) ($r['json']['id'] ?? '');
        check('save with the token from the injected meta → 200 ok', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && FinanceVault::isValidId($sid), $r['body']);
        $r = $req($jb, 'POST', '/admin/api/finance-vault.php', ['json' => ['action' => 'save', 'mode' => 'real', 'sha256' => hash('sha256', $plainBook), 'payload' => $plainBook], 'csrf' => $csrf]);
        check('save of a plaintext book over HTTP → 422', $r['code'] === 422 && ($r['json']['ok'] ?? true) === false);
        $r = $req($jb, 'GET', '/admin/api/finance-vault.php?action=list');
        check('list shows it with the cleaned label', $r['code'] === 200 && ($r['json']['items'][0]['id'] ?? '') === $sid && ($r['json']['items'][0]['label'] ?? '') === 'HTTP test'
            && ($r['json']['items'][0]['by']['id'] ?? 0) === $httpAdmins['fvt-boss']);
        check('JSON answers are no-store', str_contains($r['h']['cache-control'] ?? '', 'no-store'));
        $r = $req($jb, 'GET', '/admin/api/finance-vault.php?action=get&id=' . $sid);
        check('get → 200, Content-Length exact, payload byte-identical', $r['code'] === 200 && (int) ($r['h']['content-length'] ?? -1) === strlen($r['body'])
            && ($r['json']['item']['payload'] ?? null) === $pl && ($r['json']['item']['sha256'] ?? '') === hash('sha256', $pl), 'HTTP ' . $r['code']);
        $r = $req($jb, 'GET', '/admin/api/finance-vault.php?action=get&id=' . rawurlencode('../../config/config.local'));
        check('get with a traversal id over HTTP → 400', $r['code'] === 400);
        $r = $req($jb, 'GET', '/admin/api/finance-vault.php?action=get&id=vault-20990101-000000-00000000');
        check('get with an unknown id over HTTP → 404', $r['code'] === 404);
        check('the file landed in SHG_FINANCE_VAULT_DIR (0640, folder 0750, guard present) — not in backup/',
            is_file($httpDir . '/' . $sid . '.json') && (fileperms($httpDir . '/' . $sid . '.json') & 0777) === 0640 && (fileperms($httpDir) & 0777) === 0750
            && is_file($httpDir . '/.htaccess') && !is_file(BACKUP_PATH . '/finance/' . $sid . '.json'));

        $ja = $signIn('fvt-acct', $PW);
        $r = $req($ja, 'GET', '/admin/finance.php');
        [, $adm] = $metaOf($r['body']);
        check('accountant: finance.php opens, can.vault false, can.feed true', $r['code'] === 200 && is_array($adm) && $adm['can'] === ['vault' => false, 'feed' => true]);
        $r = $req($ja, 'GET', '/admin/api/finance-vault.php?action=list');
        check('accountant: vault list → 403', $r['code'] === 403 && ($r['json']['code'] ?? 0) === 403, 'HTTP ' . $r['code']);

        $jg = $signIn('fvt-agent', $PW);
        $r = $req($jg, 'GET', '/admin/api/finance-vault.php?action=list');
        check('counter agent WITH finance.vault + reports.view grants: vault → 403', $r['code'] === 403, 'HTTP ' . $r['code']);
        $r = $req($jg, 'GET', '/admin/finance.php');
        [, $adm] = $metaOf($r['body']);
        check('counter agent with a reports.view grant: meta says no vault, no feed', $r['code'] !== 200 || (is_array($adm) && $adm['can'] === ['vault' => false, 'feed' => false]), 'HTTP ' . $r['code']);

        $jm = $signIn('fvt-mgr', $PW);
        $r = $req($jm, 'GET', '/admin/api/finance-vault.php?action=list');
        check('manager with the finance.vault grant: list → 200 and sees the backup', $r['code'] === 200 && ($r['json']['items'][0]['id'] ?? '') === $sid);

        // A deep link must survive the sign-in: login.php only honours a plain
        // "<file>.php?<query>" next (no #hash), which is why the links use ?go=.
        Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'");
        foreach (['finance.php?go=statements&type=agent&seller=7', 'finance.php?go=website&tab=recon&from=2026-10-01&to=2026-10-09', 'finance.php?go=trace&q=SHG-2026-00009'] as $next) {
            $jn = $jar('next' . md5($next));
            $lp = $req($jn, 'GET', '/admin/login.php?next=' . rawurlencode($next));
            preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
            $r = $req($jn, 'POST', '/admin/login.php', ['form' => ['shg_csrf' => $m[1] ?? '', 'username' => 'fvt-mgr', 'password' => $PW, 'next' => $next]]);
            check('sign-in returns to the deep link: ' . $next, $r['code'] === 302 && str_ends_with($r['h']['location'] ?? '', '/admin/' . $next), $r['h']['location'] ?? ('HTTP ' . $r['code']));
        }
    }
}

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
        proc_close($serverProc);
    }
    try {
        $ids = array_values($httpAdmins);
        foreach ($ids as $id) {
            Database::query('DELETE FROM audit_logs WHERE actor_id = :a AND id > :s', ['a' => $id, 's' => $auditStart]);
            try { Database::query('DELETE FROM admin_login_events WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::query('DELETE FROM admin_devices WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::query('DELETE FROM admins WHERE id = :a', ['a' => $id]);
        }
        try { Database::query("DELETE FROM admin_login_events WHERE username LIKE 'fvt-%'"); } catch (Throwable $e) {}
        Database::query("DELETE FROM audit_logs WHERE id > :s AND actor_name LIKE 'fvt-%'", ['s' => $auditStart]);
        Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket = 'finance_vault'");
    } catch (Throwable $e) {
        echo "  (cleanup: " . $e->getMessage() . ")\n";
    }
    rrmdir($tmpBase);
}

echo "\n  $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n\n";
exit($FAIL > 0 ? 1 : 0);
