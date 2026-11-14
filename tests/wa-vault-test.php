<?php
/**
 * =====================================================================
 *  wa-vault-test.php — 14 Nov 2026 (the owner's and the CEO's papers).
 *
 *  Only admin_whatsapp / ceo_whatsapp may use it; each sees only its own
 *  papers; files are stored encrypted, checked for type, sent back only
 *  to the same number; a Drive link is accepted only from Google.
 *
 *  Local test database. Meta, Drive and the sender are stubs: nothing is
 *  downloaded or sent. Rows and files it makes are removed; settings put
 *  back.
 *
 *    php tests/wa-vault-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/wavault.php';
require_once INCLUDE_PATH . '/companydocs.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$keys = ['admin_whatsapp', 'ceo_whatsapp', 'wa_vault_on'];
$old = [];
foreach ($keys as $k) { $old[$k] = Settings::getString($k, "\0none"); }
$OWN = '919811100021'; $CEO = '919811100022'; $STR = '919811100023';

// A real tiny PNG and a tiny PDF, so finfo sees real types.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$exe = "MZ\x90\x00" . str_repeat("\x00", 200);
$media = ['m-png' => $png, 'm-pdf' => $pdf, 'm-exe' => $exe];
WaVault::$fetchMedia = static fn(string $id): ?array => isset($media[$id]) ? ['bytes' => $media[$id], 'mime' => 'x'] : null;
$fetched = [];
WaVault::$fetchUrl = static function (string $url) use (&$fetched, $pdf): ?array { $fetched[] = $url; return ['bytes' => $pdf]; };
$sent = [];
WaVault::$deliver = static function (string $to, string $bytes, string $mime, string $fn, string $cap) use (&$sent): bool { $sent[] = compact('to', 'bytes', 'mime', 'fn', 'cap'); return true; };
$img = static fn(string $id, string $caption): array => ['kind' => 'image', 'id' => $id, 'mime' => 'image/png', 'filename' => '', 'caption' => $caption];

try {
    Settings::set('admin_whatsapp', $OWN, 'string', 'whatsapp');
    Settings::set('ceo_whatsapp', '+91 98111 00022', 'string', 'whatsapp');
    Settings::set('wa_vault_on', '1', 'bool', 'whatsapp');
    WaVault::ensure();
    Database::run('DELETE FROM personal_vault WHERE owner_key IN (:a, :b)', ['a' => $OWN, 'b' => $CEO]);

    echo "\n1. Who may use it\n";
    $ok(WaVault::ownerKey('+' . $OWN) === $OWN && WaVault::ownerKey('+' . $CEO) === $CEO, 'owner and CEO numbers are recognised (CEO typed with +91 and spaces)');
    $ok(WaVault::ownerKey('+' . $STR) === '' && WaVault::ownerKey('') === '', 'any other number is not');
    $ok(WaVault::handle('+' . $STR, 'kagaj') === null && WaVault::handle('+' . $STR, 'save aadhar', 'image', $img('m-png', 'save aadhar')) === null, 'a stranger: every vault word falls through to the normal bot');
    $ok(WaVault::handle('+' . $OWN, 'hello') === null && WaVault::handle('+' . $OWN, 'aaja ko report') === null, 'the owner\'s ordinary messages fall through too');
    $ok(WaVault::handle('+' . $OWN, '', 'image', $img('m-png', '')) === null, 'a photo from the owner without "save" is left to the other handlers');

    echo "\n2. Save\n";
    $r = WaVault::handle('+' . $OWN, 'save Aadhaar card', 'image', $img('m-png', 'save Aadhaar card'));
    $ok($r !== null && str_contains($r['text'], 'राखियो') && str_contains($r['text'], 'Aadhaar card'), 'a photo with "save Aadhaar card" is kept');
    $row = Database::fetch("SELECT * FROM personal_vault WHERE owner_key = :o ORDER BY id DESC LIMIT 1", ['o' => $OWN]);
    $file = rtrim(UPLOAD_PATH, '/\\') . '/' . (string) ($row['file_path'] ?? '');
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    $ok($row !== null && $row['mime'] === 'image/png' && is_file($file), 'one row, type read from the bytes, file on disk');
    $ok($raw !== '' && !str_contains($raw, "PNG") && CompanyDocs::open($raw) === $png, 'the file on disk is encrypted and opens back to the exact bytes');
    $again = WaVault::handle('+' . $OWN, 'save copy', 'image', $img('m-png', 'save copy'));
    $ok(str_contains($again['text'], 'पहिले नै'), 'the same file twice is not stored twice');
    $bad = WaVault::handle('+' . $OWN, 'save x', 'document', ['kind' => 'document', 'id' => 'm-exe', 'filename' => 'a.pdf']);
    $ok(str_contains($bad['text'], 'PDF मात्र') && (int) Database::scalar("SELECT COUNT(*) FROM personal_vault WHERE owner_key = :o", ['o' => $OWN], 0) === 1, 'a program dressed as a PDF is refused');
    $ok(str_contains(WaVault::handle('+' . $OWN, 'save x', 'image', $img('m-missing', 'save x'))['text'], 'ल्याउन सकिएन'), 'a file Meta does not hand over: a clear message, nothing stored');

    echo "\n3. Google Drive link\n";
    $ok(WaVault::driveDownloadUrl('https://drive.google.com/file/d/1AbCdEfGhIjKlMn/view?usp=sharing') === 'https://drive.google.com/uc?export=download&confirm=t&id=1AbCdEfGhIjKlMn', 'a Drive file link becomes its download link');
    $ok(WaVault::driveDownloadUrl('https://docs.google.com/document/d/1AbCdEfGhIjKlMn/edit') === 'https://docs.google.com/document/d/1AbCdEfGhIjKlMn/export?format=pdf', 'a Google Doc is fetched as PDF');
    $ok(WaVault::driveDownloadUrl('https://evil.example/file/d/1AbCdEfGhIjKlMn') === '' && WaVault::driveDownloadUrl('http://drive.google.com/file/d/1AbCdEfGhIjKlMn') === ''
        && WaVault::driveDownloadUrl('https://drive.google.com.evil.example/file/d/1AbCdEfGhIjKlMn') === '', 'any other host, plain http or a look-alike host is refused');
    $r = WaVault::handle('+' . $OWN, 'save https://drive.google.com/file/d/1AbCdEfGhIjKlMn/view PAN card');
    $ok(str_contains($r['text'], 'राखियो') && str_contains($r['text'], 'PAN card') && count($fetched) === 1, 'save <Drive link> <name> keeps the file');
    $r = WaVault::handle('+' . $OWN, 'save https://example.com/x.pdf thing');
    $ok(str_contains($r['text'], 'Google Drive') && count($fetched) === 1, 'a non-Drive link is not fetched at all');

    echo "\n4. List, send, delete — each person sees only their own\n";
    WaVault::handle('+' . $CEO, 'save CEO passport', 'document', ['kind' => 'document', 'id' => 'm-pdf', 'filename' => 'p.pdf']);
    $listOwn = WaVault::handle('+' . $OWN, 'kagaj')['text'];
    $listCeo = WaVault::handle('+' . $CEO, 'मेरो कागज')['text'];
    $ok(str_contains($listOwn, 'Aadhaar card') && str_contains($listOwn, 'PAN card') && !str_contains($listOwn, 'passport'), 'the owner\'s list shows only the owner\'s papers');
    $ok(str_contains($listCeo, 'CEO passport') && !str_contains($listCeo, 'Aadhaar'), 'the CEO\'s list shows only the CEO\'s papers');
    $aid = (int) Database::scalar("SELECT id FROM personal_vault WHERE owner_key = :o AND title = 'Aadhaar card'", ['o' => $OWN], 0);
    $cid = (int) Database::scalar("SELECT id FROM personal_vault WHERE owner_key = :o", ['o' => $CEO], 0);
    $r = WaVault::handle('+' . $OWN, 'kagaj ' . $aid);
    $ok(str_contains($r['text'], 'पठाइयो') && count($sent) === 1 && $sent[0]['to'] === $OWN && $sent[0]['bytes'] === $png && $sent[0]['mime'] === 'image/png', '"kagaj <n>" sends the exact file back to the same number');
    $r = WaVault::handle('+' . $OWN, 'kagaj aadhar');
    $ok(str_contains($r['text'], 'पठाइयो') && count($sent) === 2, '"kagaj aadhar" finds it by name (aa/a spelling)');
    $r = WaVault::handle('+' . $OWN, 'kagaj ' . $cid);
    $ok(str_contains($r['text'], 'भेटिएन') && count($sent) === 2, 'the owner cannot fetch the CEO\'s paper by its number');
    $r = WaVault::handle('+' . $CEO, 'kagaj ' . $aid);
    $ok(str_contains($r['text'], 'भेटिएन') && count($sent) === 2, 'and the CEO cannot fetch the owner\'s');
    $r = WaVault::handle('+' . $CEO, 'kagaj hatau ' . $aid);
    $ok(str_contains($r['text'], 'भेटिएन') && (string) Database::scalar('SELECT status FROM personal_vault WHERE id = :i', ['i' => $aid], '') === 'active', 'nor delete it');
    $r = WaVault::handle('+' . $OWN, 'kagaj hatau ' . $aid);
    $ok(str_contains($r['text'], 'हटाइयो') && !str_contains(WaVault::handle('+' . $OWN, 'kagaj')['text'], 'Aadhaar'), 'the owner hides it; it leaves the list');
    $ok(is_file($file), 'the encrypted file is kept (the office can undo a slip)');
    $ok(str_contains(WaVault::handle('+' . $OWN, 'vault help')['text'], 'save aadhar'), '"vault help" explains the words');

    echo "\n5. Switch\n";
    Settings::set('wa_vault_on', '0', 'bool', 'whatsapp');
    $ok(WaVault::handle('+' . $OWN, 'kagaj') === null, 'wa_vault_on off: the vault answers nothing');
    $src = (string) file_get_contents('includes/wabot.php');
    $ok(strpos($src, 'WaVault::handle') !== false && strpos($src, 'WaVault::handle') < strpos($src, 'ApWa::reply'), 'the bot asks the vault first (before the staff desk)');
} finally {
    WaVault::$fetchMedia = WaVault::$fetchUrl = WaVault::$deliver = null;
    foreach (Database::fetchAll('SELECT id, file_path FROM personal_vault WHERE owner_key IN (:a, :b)', ['a' => $OWN, 'b' => $CEO]) as $r) {
        @unlink(rtrim(UPLOAD_PATH, '/\\') . '/' . $r['file_path']);
    }
    Database::run('DELETE FROM personal_vault WHERE owner_key IN (:a, :b)', ['a' => $OWN, 'b' => $CEO]);
    Database::run("DELETE FROM audit_logs WHERE action LIKE 'vault.%' AND created_at >= NOW() - INTERVAL 1 HOUR");
    foreach ($old as $k => $v) {
        if ($v === "\0none") {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, $v, $k === 'wa_vault_on' ? 'bool' : 'string', 'whatsapp');
        }
    }
}

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
