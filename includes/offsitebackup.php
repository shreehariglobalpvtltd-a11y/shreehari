<?php
/**
 * includes/offsitebackup.php — a copy of the register that survives the server.
 *
 * cron/backup.php writes a nightly dump NEXT TO the database it protects. A
 * dead disk, a deleted VPS or an unpaid hosting bill takes both. This sends
 * the newest dump somewhere else every night.
 *
 * WHERE: the company's own mailbox (settings.backup_offsite_email, default
 * admin_email). The plan said Google Drive; a Drive upload needs a Google
 * Cloud project, a service account and a key file the owner would have to
 * create and nobody would rotate. The dump is ~0.6 MB, SMTP already works,
 * and a Gmail inbox IS Google storage in another building. One password to
 * set, nothing to install. Past MAX_BYTES the job stops and says so rather
 * than silently mailing nothing.
 *
 * ENCRYPTED, because the dump is every passenger's name, phone and ID number
 * and email is not private. AES-256-CBC, key from PBKDF2-SHA256, in OpenSSL's
 * own "Salted__" container — so it opens with a stock command on any
 * computer, with no code from this project:
 *
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 \
 *           -in backup_XXXX.sql.gz.enc -out backup.sql.gz
 *
 * THE PASSWORD IS THE OWNER'S, NOT THE SERVER'S. It is typed once in Admin →
 * Settings (backup_offsite_password) and must also live on paper / in the
 * owner's phone. Deriving it from APP_KEY would have been zero-setup — and
 * useless on the one day it matters, because APP_KEY dies with the server.
 * Until a password of MIN_PASS characters is set the job sends NOTHING and
 * raises a card: an unencrypted dump never leaves.
 *
 * Switch: backup_offsite_on (default off). Observe-and-send only: it never
 * deletes or changes a local backup.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/health.php';

final class OffsiteBackup
{
    public const ITERATIONS = 200000;
    public const MIN_PASS   = 10;
    public const MAX_BYTES  = 18 * 1024 * 1024;   // under Gmail's 25 MB after base64
    public const MAX_AGE_H  = 26;

    public static function enabled(): bool
    {
        return Settings::getBool('backup_offsite_on', false);
    }

    /** OpenSSL-compatible: "Salted__" . salt(8) . AES-256-CBC(PKCS#7). */
    public static function encrypt(string $plain, string $password, ?string $salt = null): string
    {
        $salt = $salt ?? random_bytes(8);
        $ki   = hash_pbkdf2('sha256', $password, $salt, self::ITERATIONS, 48, true);
        $ct   = openssl_encrypt($plain, 'aes-256-cbc', substr($ki, 0, 32), OPENSSL_RAW_DATA, substr($ki, 32, 16));
        if ($ct === false) {
            throw new RuntimeException('encryption failed');
        }
        return 'Salted__' . $salt . $ct;
    }

    /** The inverse — used by the restore drill and the tests. Null = wrong password or not our format. */
    public static function decrypt(string $blob, string $password): ?string
    {
        if (strlen($blob) < 32 || substr($blob, 0, 8) !== 'Salted__') {
            return null;
        }
        $ki = hash_pbkdf2('sha256', $password, substr($blob, 8, 8), self::ITERATIONS, 48, true);
        $pt = openssl_decrypt(substr($blob, 16), 'aes-256-cbc', substr($ki, 0, 32), OPENSSL_RAW_DATA, substr($ki, 32, 16));
        return $pt === false ? null : $pt;
    }

    /** Newest local dump, or null. */
    public static function newestDump(?string $dir = null): ?string
    {
        $all = glob(($dir ?? BACKUP_PATH) . '/backup_*.sql.gz') ?: [];
        rsort($all);
        return $all[0] ?? null;
    }

    /**
     * Send the newest dump if it has not gone yet.
     *
     * @param callable(string,string,string,array):bool|null $mailer fn(to, subject, html, attachments) — tests inject one
     * @return array<string,mixed> one line for cron_done(); 'ok' false = the job did not do its job
     */
    public static function run(?callable $mailer = null, ?string $dir = null, ?int $nowTs = null): array
    {
        $nowTs = $nowTs ?? time();
        $pass  = Settings::getString('backup_offsite_password', '');
        $to    = Settings::getString('backup_offsite_email', '') ?: Settings::getString('admin_email', '');

        if (mb_strlen($pass) < self::MIN_PASS) {
            return self::fail('backup.offsite_password', Health::CRITICAL,
                'Off-site backup is ON but has no password - nothing is being sent',
                'The nightly backup is only allowed to leave the server encrypted, and no backup password is set, so no copy exists outside this server.',
                "1. Admin > Settings: find backup_offsite_password and type a password of at least " . self::MIN_PASS . " characters.\n"
                . "2. WRITE THAT PASSWORD ON PAPER and keep it away from the office computer. Without it the backup cannot be opened - not by anyone.\n"
                . "3. Save. The next night's backup is sent by itself.");
        }
        if ($to === '') {
            return self::fail('backup.offsite_email', Health::CRITICAL, 'Off-site backup has nowhere to go',
                'No backup_offsite_email and no admin_email is set.', 'Admin > Settings: set backup_offsite_email to the mailbox that should receive the nightly backup.');
        }

        $file = self::newestDump($dir);
        $age  = $file !== null ? ($nowTs - (int) filemtime($file)) / 3600 : null;
        if ($file === null || $age > self::MAX_AGE_H) {
            return self::fail('backup.offsite_stale', Health::CRITICAL, 'There is no fresh backup to send off-site',
                'The newest local backup is ' . ($file === null ? 'missing' : round((float) $age) . ' hours old') . '. The backup job itself is not producing files.',
                "Run by hand and read the error:\n/usr/bin/php /var/www/shreehariglobal.in/public_html/cron/backup.php");
        }
        $size = (int) filesize($file);
        if ($size > self::MAX_BYTES) {
            return self::fail('backup.offsite_too_big', Health::CRITICAL, 'The backup has outgrown email (' . round($size / 1048576) . ' MB)',
                'Email cannot carry a file this large, so no off-site copy is being made.',
                'Ask the developer to move the off-site copy to cloud storage (Google Drive / S3).');
        }

        $name = basename($file);
        if (self::alreadySent($name)) {
            self::clear();
            return ['ok' => true, 'sent' => 0, 'file' => $name, 'note' => 'already sent'];
        }

        $blob = self::encrypt((string) file_get_contents($file), $pass);
        $html = '<p>Nightly encrypted backup of the S Hari Global booking database.</p>'
              . '<p><b>' . htmlspecialchars($name, ENT_QUOTES) . '.enc</b> · ' . round($size / 1024) . ' KB · ' . date('Y-m-d H:i', (int) filemtime($file)) . '</p>'
              . '<p>Keep these emails. To open one you need the backup password that was set in Admin &gt; Settings '
              . '(it is NOT in this email). On any computer with OpenSSL:</p>'
              . '<pre>openssl enc -d -aes-256-cbc -pbkdf2 -iter ' . self::ITERATIONS . ' -md sha256 -in ' . htmlspecialchars($name, ENT_QUOTES) . '.enc -out backup.sql.gz</pre>';
        $att  = [['name' => $name . '.enc', 'mime' => 'application/octet-stream', 'data' => $blob]];
        $subj = 'SHG backup ' . date('Y-m-d', (int) filemtime($file)) . ' (encrypted)';

        if ($mailer !== null) {
            $ok = (bool) $mailer($to, $subj, $html, $att);
        } else {
            require_once __DIR__ . '/notify.php';
            $ok = Notify::email($to, $subj, $html, '', $att);
        }
        if (!$ok) {
            return self::fail('backup.offsite_send', Health::CRITICAL, 'The off-site backup could not be emailed',
                'The mail server refused or could not be reached, so last night\'s backup exists only on this server.',
                "1. Admin > Settings > Email: press the email test button.\n2. Check smtp_host / smtp_user / smtp_pass.\n3. The job tries again on its next run.");
        }

        self::markSent($name);
        self::clear();
        return ['ok' => true, 'sent' => 1, 'file' => $name, 'kb' => (int) round(strlen($blob) / 1024)];
    }

    /* --------------------------------------------------------------- */

    private static function alreadySent(string $name): bool
    {
        try {
            return Database::exists("SELECT 1 FROM backups WHERE filename = :f AND note LIKE 'offsite:%' LIMIT 1", ['f' => $name]);
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function markSent(string $name): void
    {
        try {
            $n = Database::update('backups', ['note' => 'offsite: emailed ' . date('Y-m-d H:i')], 'filename = :f', ['f' => $name]);
            if ($n === 0) {
                Database::insert('backups', ['filename' => $name, 'trigger_type' => 'cron', 'note' => 'offsite: emailed ' . date('Y-m-d H:i')]);
            }
        } catch (Throwable $e) {
            Logger::error('offsite backup: could not record the send', ['e' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed> */
    private static function fail(string $key, string $severity, string $title, string $detail, string $fix): array
    {
        self::clear($key);
        Health::open('backup_offsite', $key, $severity, $title, $detail, $fix);
        return ['ok' => false, 'sent' => 0, 'why' => $key];
    }

    /** Resolve every card of this job except the one being raised now. */
    private static function clear(string $except = ''): void
    {
        foreach (['backup.offsite_password', 'backup.offsite_email', 'backup.offsite_stale', 'backup.offsite_too_big', 'backup.offsite_send'] as $k) {
            if ($k !== $except) {
                Health::resolve($k);
            }
        }
    }
}
