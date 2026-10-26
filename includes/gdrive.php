<?php
/**
 * =====================================================================
 *  GDrive — database backups into the owner's own Google Drive (5 Oct 2026)
 *
 *  The owner connects ONE Google account once (Backup page → "Connect
 *  Google Drive"). Google asks for the narrow `drive.file` permission: this
 *  app can only see the files it creates itself, never the rest of the
 *  Drive. After that:
 *
 *    - when an admin opens the panel and the last Drive copy is older than
 *      a day, a small sheet asks "back up to Google Drive now?" — Back up /
 *      Skip today / do it every night by itself;
 *    - with "every night" on, cron/backup-gdrive.php uploads the nightly dump;
 *    - copies are kept in a "S Hari Global Backups" folder, newest
 *      gdrive_keep kept (older ones go to the Drive trash, which Google
 *      empties after 30 days);
 *    - optionally encrypted with the off-site backup password (AES-256,
 *      opens with docs/RESTORE.md) so a leaked Drive does not leak customers.
 *
 *  Needs a Google Cloud "OAuth client ID" (type: Web application) whose
 *  redirect URI is redirectUri(). The refresh token is stored encrypted
 *  with APP_KEY; the client secret and token never reach a browser.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/dbdump.php';

final class GDrive
{
    private const SCOPE  = 'https://www.googleapis.com/auth/drive.file openid email';
    private const FOLDER = 'S Hari Global Backups';

    private static ?string $access = null;

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Settings
     * ----------------------------------------------------------------- */

    public static function clientId(): string
    {
        return trim(Settings::getString('gdrive_client_id', ''));
    }

    private static function clientSecret(): string
    {
        return trim(Settings::getString('gdrive_client_secret', ''));
    }

    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function connected(): bool
    {
        return self::configured() && Settings::getString('gdrive_refresh_token', '') !== '';
    }

    public static function account(): string
    {
        return Settings::getString('gdrive_account', '');
    }

    public static function redirectUri(): string
    {
        return appUrl('/admin/backup.php?oauth=1');
    }

    /** Time of the last good Drive upload (0 = never). */
    public static function lastAt(): int
    {
        $t = Settings::getString('gdrive_last_at', '');
        return $t !== '' ? (int) strtotime($t) : 0;
    }

    /** Should the panel offer "back up now?" (no Drive copy in the last 20 hours). */
    public static function due(): bool
    {
        return time() - self::lastAt() > 20 * 3600;
    }

    private static function set(string $k, string $v, string $type = 'string'): void
    {
        Settings::set($k, $v, $type, 'backup');
    }

    /* -----------------------------------------------------------------
     *  Token storage (AES-256-GCM, key from APP_KEY)
     * ----------------------------------------------------------------- */

    private static function key(): string
    {
        return hash('sha256', (defined('APP_KEY') ? APP_KEY : '') . '|gdrive-token', true);
    }

    private static function seal(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($c === false) {
            throw new RuntimeException('token encryption failed');
        }
        return 'g1:' . base64_encode($iv . $tag . $c);
    }

    private static function open(string $stored): string
    {
        if (!str_starts_with($stored, 'g1:')) {
            return '';
        }
        $b = base64_decode(substr($stored, 3), true);
        if ($b === false || strlen($b) < 29) {
            return '';
        }
        $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $p === false ? '' : $p;
    }

    /* -----------------------------------------------------------------
     *  OAuth
     * ----------------------------------------------------------------- */

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
            'state'         => $state,
        ]);
    }

    /** Swap the code Google sent back for a refresh token; remember the account. */
    public static function finishConnect(string $code): array
    {
        $r = self::http('POST', 'https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ], 'form');
        $j = $r['json'];
        if ($r['http'] !== 200 || empty($j['refresh_token'])) {
            return ['ok' => false, 'error' => (string) ($j['error_description'] ?? ($j['error'] ?? 'Google did not return a refresh token (HTTP ' . $r['http'] . ')'))];
        }
        $email = '';
        if (!empty($j['id_token']) && substr_count((string) $j['id_token'], '.') === 2) {
            $payload = json_decode((string) base64_decode(strtr(explode('.', (string) $j['id_token'])[1], '-_', '+/')), true);
            $email = (string) ($payload['email'] ?? '');
        }
        self::set('gdrive_refresh_token', self::seal((string) $j['refresh_token']));
        self::set('gdrive_account', $email);
        self::set('gdrive_folder_id', '');
        self::set('gdrive_last_error', '');
        self::$access = (string) ($j['access_token'] ?? '') ?: null;
        return ['ok' => true, 'email' => $email];
    }

    public static function disconnect(): void
    {
        $tok = self::open(Settings::getString('gdrive_refresh_token', ''));
        if ($tok !== '') {
            self::http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => $tok], 'form');
        }
        self::set('gdrive_refresh_token', '');
        self::set('gdrive_account', '');
        self::set('gdrive_folder_id', '');
        self::$access = null;
    }

    private static function accessToken(): string
    {
        if (self::$access !== null) {
            return self::$access;
        }
        $refresh = self::open(Settings::getString('gdrive_refresh_token', ''));
        if ($refresh === '') {
            throw new RuntimeException('Google Drive is not connected.');
        }
        $r = self::http('POST', 'https://oauth2.googleapis.com/token', [
            'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
        ], 'form');
        if ($r['http'] !== 200 || empty($r['json']['access_token'])) {
            $err = (string) ($r['json']['error'] ?? 'HTTP ' . $r['http']);
            if ($err === 'invalid_grant') {
                $err = 'Google access was withdrawn or expired — connect Google Drive again';
            }
            throw new RuntimeException($err);
        }
        return self::$access = (string) $r['json']['access_token'];
    }

    /* -----------------------------------------------------------------
     *  Drive
     * ----------------------------------------------------------------- */

    private static function folderId(): string
    {
        $id = Settings::getString('gdrive_folder_id', '');
        if ($id !== '') {
            $r = self::http('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=id,trashed', null, 'none', self::accessToken());
            if ($r['http'] === 200 && empty($r['json']['trashed'])) {
                return $id;
            }
        }
        $r = self::http('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', [
            'name' => self::FOLDER, 'mimeType' => 'application/vnd.google-apps.folder',
        ], 'json', self::accessToken());
        if ($r['http'] !== 200 || empty($r['json']['id'])) {
            throw new RuntimeException('Could not create the backup folder in Drive (HTTP ' . $r['http'] . ')');
        }
        self::set('gdrive_folder_id', (string) $r['json']['id']);
        return (string) $r['json']['id'];
    }

    /**
     * Upload a file with a resumable session (streams from disk, any size).
     *
     * @return array{id:string, name:string, size:int}
     */
    private static function upload(string $path, string $name, string $mime): array
    {
        $size = (int) filesize($path);
        $meta = ['name' => $name, 'parents' => [self::folderId()], 'description' => 'S Hari Global database backup'];
        $init = self::http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,size',
            $meta, 'json', self::accessToken(), ['X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . $size]);
        $loc = $init['headers']['location'] ?? '';
        if ($init['http'] !== 200 || $loc === '') {
            throw new RuntimeException('Drive did not open an upload (HTTP ' . $init['http'] . ')');
        }
        $fh = fopen($path, 'rb');
        $ch = curl_init($loc);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => $size,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $mime, 'Authorization: Bearer ' . self::accessToken()],
        ]);
        $raw  = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        $j = json_decode($raw, true);
        if (!in_array($http, [200, 201], true) || empty($j['id'])) {
            throw new RuntimeException('Drive upload failed (HTTP ' . $http . ($cerr !== '' ? ', ' . $cerr : '') . ')');
        }
        return ['id' => (string) $j['id'], 'name' => (string) ($j['name'] ?? $name), 'size' => (int) ($j['size'] ?? $size)];
    }

    /** @return list<array{id:string,name:string,size:int,created:string}> newest first */
    public static function listCopies(int $max = 50): array
    {
        $q = "'" . self::folderId() . "' in parents and trashed = false";
        $r = self::http('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $q, 'orderBy' => 'createdTime desc', 'pageSize' => min(200, $max), 'fields' => 'files(id,name,size,createdTime)',
        ]), null, 'none', self::accessToken());
        $out = [];
        foreach ((array) ($r['json']['files'] ?? []) as $f) {
            $out[] = ['id' => (string) $f['id'], 'name' => (string) $f['name'], 'size' => (int) ($f['size'] ?? 0), 'created' => (string) ($f['createdTime'] ?? '')];
        }
        return $out;
    }

    /** Older copies go to the Drive trash (Google empties it after 30 days). */
    private static function prune(int $keep): int
    {
        $n = 0;
        foreach (array_slice(self::listCopies(200), max(3, $keep)) as $f) {
            $r = self::http('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($f['id']), ['trashed' => true], 'json', self::accessToken());
            if ($r['http'] === 200) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Back up to Drive now: newest local dump if it is fresh (< 6 h), else a
     * new one; encrypted when asked; uploaded; old copies trimmed.
     *
     * @return array{ok:bool, error?:string, file?:string, size?:int, drive_id?:string, encrypted?:bool, pruned?:int}
     */
    public static function backupNow(string $trigger, ?int $by = null): array
    {
        if (!self::connected()) {
            return ['ok' => false, 'error' => 'Google Drive is not connected yet.'];
        }
        @set_time_limit(600);
        $tmp = null;
        try {
            $local = DbDump::latest();
            if ($local === null || $local['mtime'] < time() - 6 * 3600) {
                $d = DbDump::run($trigger === 'cron' ? 'cron' : 'manual', $by);
                if (!$d['ok']) {
                    throw new RuntimeException($d['error'] ?? 'backup failed');
                }
                $local = DbDump::latest();
            }
            if ($local === null) {
                throw new RuntimeException('No backup file to upload.');
            }
            $path = $local['path'];
            $name = 'shg-' . preg_replace('/^backup_/', '', $local['file']);
            $mime = 'application/gzip';
            $enc  = false;
            $pass = Settings::getString('backup_offsite_password', '');
            if (Settings::getBool('gdrive_encrypt', true) && mb_strlen($pass) >= 10) {
                require_once __DIR__ . '/offsitebackup.php';
                $tmp = tempnam(sys_get_temp_dir(), 'shgbk');
                file_put_contents($tmp, OffsiteBackup::encrypt((string) file_get_contents($path), $pass));
                $path = $tmp;
                $name .= '.enc';
                $mime = 'application/octet-stream';
                $enc  = true;
            }
            $up = self::upload($path, $name, $mime);
            $pruned = self::prune(max(3, Settings::getInt('gdrive_keep', 30)));
            self::set('gdrive_last_at', date('Y-m-d H:i:s'));
            self::set('gdrive_last_file', $up['name']);
            self::set('gdrive_last_error', '');
            try {
                Database::update('backups', ['note' => mb_substr('gdrive:' . $up['id'], 0, 255)], 'filename = :f', ['f' => $local['file']]);
            } catch (Throwable $ignored) {
            }
            Logger::audit('backup.gdrive', 'backup', $local['file'], null, ['drive' => $up['id'], 'size' => $up['size'], 'encrypted' => $enc], $by ? 'by admin #' . $by : 'nightly');
            return ['ok' => true, 'file' => $up['name'], 'size' => $up['size'], 'drive_id' => $up['id'], 'encrypted' => $enc, 'pruned' => $pruned];
        } catch (Throwable $e) {
            self::set('gdrive_last_error', date('d M H:i') . ' — ' . mb_substr($e->getMessage(), 0, 200));
            Logger::exception($e, 'backup');
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /* -----------------------------------------------------------------
     *  HTTP
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed>|null $body
     * @param list<string> $extraHeaders
     * @return array{http:int, json:array<string,mixed>, headers:array<string,string>}
     */
    private static function http(string $method, string $url, ?array $body, string $enc, ?string $bearer = null, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = $extraHeaders;
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $resHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $resHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            if ($enc === 'form') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                $headers[] = 'Content-Type: application/json; charset=UTF-8';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw  = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($raw, true);
        return ['http' => $http, 'json' => is_array($j) ? $j : [], 'headers' => $resHeaders];
    }
}
