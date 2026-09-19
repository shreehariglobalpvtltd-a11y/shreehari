<?php
/**
 * =====================================================================
 *  backup-offsite-test.php — the copy that leaves the server is encrypted,
 *  opens with stock OpenSSL, and is never sent twice or in the clear
 *  (19 Sep 2026).
 *
 *  A backup nobody can open is worse than none (it feels safe), and one
 *  that leaves unencrypted is a leak of every passenger's ID number. So:
 *
 *    - round trip; wrong password = null, not garbage
 *    - the container is OpenSSL's own: the `openssl` program decrypts what
 *      PHP wrote (skipped when the binary is absent) — the restore guide
 *      depends on exactly this
 *    - no password / short password → NOTHING is sent and a card is raised
 *    - the attachment is ciphertext (no SQL visible), named *.enc
 *    - sent once per dump; a stale dump raises a card instead of resending
 *    - the mailer is injected: this suite never sends an email
 *
 *    php -c .claude/php-dev.ini tests/backup-offsite-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/offsitebackup.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$dir  = sys_get_temp_dir() . '/shg-offsite-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$name = 'backup_20991231_021500.sql.gz';
$sql  = "-- S Hari Global database backup\nINSERT INTO `bookings` (`pnr`) VALUES ('SHG-SECRET-PNR');\n";
file_put_contents($dir . '/' . $name, gzencode($sql));

/* Settings are memoised per request; write through the API and read back. */
$keep = [];
foreach (['backup_offsite_password', 'backup_offsite_email'] as $k) { $keep[$k] = Settings::getString($k, ''); }
$set = static function (string $k, string $v): void { Settings::set($k, $v, 'string', 'automation'); };

$cleanup = static function () use ($dir, $name, $keep, $set): void {
    foreach ($keep as $k => $v) { Settings::set($k, $v, 'string', 'automation'); }
    Database::delete('backups', 'filename = :f', ['f' => $name]);
    Database::run("DELETE FROM health_incidents WHERE dedupe_key LIKE 'backup.offsite_%'");
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
};
$isOpen = static fn(string $key): bool => (string) Database::scalar('SELECT status FROM health_incidents WHERE dedupe_key = :d', ['d' => $key], '') === 'open';

try {
    Database::delete('backups', 'filename = :f', ['f' => $name]);

    echo "-- the container --\n";
    $blob = OffsiteBackup::encrypt('hello register', 'correct horse battery');
    check('starts with OpenSSL\'s "Salted__" header and hides the text', str_starts_with($blob, 'Salted__') && !str_contains($blob, 'hello'));
    check('round trip', OffsiteBackup::decrypt($blob, 'correct horse battery') === 'hello register');
    check('wrong password = null, never garbage', OffsiteBackup::decrypt($blob, 'wrong password!!') === null);
    check('two encryptions of one file differ (fresh salt each night)', OffsiteBackup::encrypt('x', 'pppppppppp') !== OffsiteBackup::encrypt('x', 'pppppppppp'));

    $bin = trim((string) @shell_exec('command -v openssl 2>/dev/null'));
    if ($bin !== '') {
        $enc = $dir . '/t.enc'; $out = $dir . '/t.out';
        file_put_contents($enc, OffsiteBackup::encrypt($sql, 'paper-password-2026'));
        @shell_exec(escapeshellarg($bin) . ' enc -d -aes-256-cbc -pbkdf2 -iter ' . OffsiteBackup::ITERATIONS . ' -md sha256 -in ' . escapeshellarg($enc)
            . ' -out ' . escapeshellarg($out) . ' -pass pass:paper-password-2026 2>&1');
        check('the stock `openssl` program opens what PHP wrote (docs/RESTORE.md step 1)', is_file($out) && file_get_contents($out) === $sql);
        @unlink($enc); @unlink($out);
    } else {
        echo "  \033[33mSKIP\033[0m  no openssl binary on this machine\n";
    }

    echo "-- nothing leaves in the clear --\n";
    $mails  = [];
    $mailer = static function (string $to, string $subject, string $html, array $att) use (&$mails): bool {
        $mails[] = compact('to', 'subject', 'html', 'att');
        return true;
    };
    $now = time();
    touch($dir . '/' . $name, $now - 3600);

    $set('backup_offsite_password', '');
    $r = OffsiteBackup::run($mailer, $dir, $now);
    check('no password → nothing sent, job reports not-ok', $mails === [] && $r['ok'] === false && $r['why'] === 'backup.offsite_password');
    check('...and a CRITICAL card tells the owner to set one and write it on paper',
        $isOpen('backup.offsite_password')
        && stripos((string) Database::scalar("SELECT fix_steps FROM health_incidents WHERE dedupe_key = 'backup.offsite_password'", [], ''), 'paper') !== false);
    $set('backup_offsite_password', 'short');
    OffsiteBackup::run($mailer, $dir, $now);
    check('a 5-character password is refused too', $mails === []);

    echo "-- the send --\n";
    $set('backup_offsite_password', 'paper-password-2026');
    $set('backup_offsite_email', 'owner@example.test');
    $r = OffsiteBackup::run($mailer, $dir, $now);
    check('with a password the newest dump is sent once', count($mails) === 1 && $r['ok'] === true && $r['sent'] === 1, json_encode($r));
    $m = $mails[0] ?? ['to' => '', 'att' => [['name' => '', 'data' => '']], 'html' => '', 'subject' => ''];
    check('to the off-site mailbox, as <dump>.enc', $m['to'] === 'owner@example.test' && $m['att'][0]['name'] === $name . '.enc');
    check('the attachment is ciphertext: no SQL, no PNR, not gzip',
        !str_contains($m['att'][0]['data'], 'SHG-SECRET-PNR') && !str_contains($m['att'][0]['data'], 'INSERT') && substr($m['att'][0]['data'], 0, 2) !== "\x1f\x8b");
    check('...that decrypts back to the exact dump', OffsiteBackup::decrypt($m['att'][0]['data'], 'paper-password-2026') === gzencode($sql)
        || gzdecode((string) OffsiteBackup::decrypt($m['att'][0]['data'], 'paper-password-2026')) === $sql);
    check('the email explains how to open it and does NOT contain the password',
        str_contains($m['html'], 'openssl enc -d') && !str_contains($m['html'], 'paper-password-2026') && !str_contains($m['subject'], 'paper-password'));
    check('the password card cleared itself', !$isOpen('backup.offsite_password'));

    $r = OffsiteBackup::run($mailer, $dir, $now);
    check('a second run the same night sends nothing more', count($mails) === 1 && $r['sent'] === 0 && $r['ok'] === true, json_encode($r));

    echo "-- failures are loud --\n";
    Database::delete('backups', 'filename = :f', ['f' => $name]);
    $r = OffsiteBackup::run(static fn(): bool => false, $dir, $now);
    check('a refused email = not-ok + card, and it will retry (not marked sent)',
        $r['ok'] === false && $isOpen('backup.offsite_send')
        && !Database::exists("SELECT 1 FROM backups WHERE filename = :f AND note LIKE 'offsite:%'", ['f' => $name]));
    $r = OffsiteBackup::run($mailer, $dir, $now + 40 * 3600);
    check('a 41-hour-old newest dump is NOT re-sent as if fresh: card "no fresh backup"', $r['ok'] === false && $r['why'] === 'backup.offsite_stale' && count($mails) === 1);

    echo "-- it only reads the local backups --\n";
    $src = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(INCLUDE_PATH . '/offsitebackup.php'));
    check('never deletes or rewrites a backup file', !preg_match('/\b(unlink|rename|file_put_contents|fwrite|rmdir)\s*\(/', (string) $src));
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
