<?php
/**
 * admin/api/finance-vault.php — encrypted SHG Finance Master book backups,
 * kept on the company's own server in India (9 Oct 2026).
 *
 * WHY: the Companies (Accounts) Rules, 2014, rule 3(1) (as amended in 2022)
 * want books kept in electronic form to have a backup on servers physically
 * located in India, taken daily. Finance Master keeps the books inside the
 * CEO's browser, so this endpoint lets the app park an ENCRYPTED copy on the
 * India VPS. The passphrase never leaves the device: the server only ever
 * receives an "enc1" envelope (AES-GCM ciphertext + PBKDF2 salt + iv, all
 * base64) and refuses anything that looks like a plaintext book.
 *
 * WHO: a signed-in staff member who is superadmin, or who holds the extra
 * permission 'finance.vault' (admins.permissions JSON). A counter agent
 * (role 'agent') is refused even with that grant.
 *
 *   GET  ?action=list          {ok, items:[{id, createdAt, size, sha256, label, mode, by:{id,name}}],
 *                               keep, maxBytes, tz}                       newest first
 *   GET  ?action=get&id=<id>   {ok, item:{id, createdAt, by, label, mode, sha256, size, payload}}
 *                               payload is the envelope JSON exactly as it was saved (a string)
 *   POST (JSON body, header X-CSRF-Token)
 *        {action:"save", label, mode:"real"|"demo", sha256:<hex of payload>, payload:"<enc1 JSON string>"}
 *                            → {ok, id, createdAt, size, sha256, pruned}
 *
 * Errors: {ok:false, error, code} with the real HTTP status — 400 bad request,
 * 401 signed out, 403 role, 404 no such backup, 405 method, 413 too large,
 * 419 CSRF, 422 not an encrypted envelope / checksum mismatch, 429 more than
 * 30 calls a minute, 500 storage failure, 507 disk full.
 *
 * STORAGE: BACKUP_PATH/finance/ (mode 0750, with a 'Require all denied'
 * .htaccess and an empty index.html). nginx already refuses every /backup/
 * URL (deploy/nginx-shreehariglobal.in.conf, "DENIED PATHS"); deploy.sh and
 * the deploy workflow leave backup/ alone, and the SQL-dump pruners only touch
 * backup_*.sql.gz, so nothing here is overwritten or shipped off-site by
 * mistake. One file per backup, 0640:
 *     vault-YYYYmmdd-His-<8 hex>.json = {id, createdAt, by:{id,name}, label, mode, sha256, size, payload}
 * The first 5 hex digits are the microseconds of the save and the last 3 are
 * random, so the name sorts in save order. The id is the file stem and is
 * checked against ID_PATTERN before any file is touched. The newest 120 are
 * kept; older ones are deleted on each save. Every save and every download
 * writes an audit row (finance.vault_save / finance.vault_get).
 *
 * Tests: tests/finance-vault-test.php loads this file with SHG_FINANCE_VAULT_LIB
 * defined, which stops before the HTTP part below, and drives the class and
 * finance_vault_handle() in-process against a temporary directory.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_guard.php';

/** A refusal with the HTTP status it should be answered with (the exception code). */
final class FinanceVaultError extends RuntimeException
{
}

final class FinanceVault
{
    /** Largest accepted payload (the envelope JSON string), in bytes. */
    public const MAX_PAYLOAD_BYTES = 25 * 1024 * 1024;
    /**
     * Largest accepted request body: the payload re-encoded as a JSON string
     * (an encoder that writes "/" as "\/" grows base64 by ~1.6%) plus the small
     * wrapper around it. The 25 MB rule itself is applied to the decoded payload.
     */
    public const MAX_BODY_BYTES = self::MAX_PAYLOAD_BYTES + self::MAX_PAYLOAD_BYTES / 16 + 65536;
    /** How many backups are kept; the oldest beyond this are deleted on each save. */
    public const KEEP = 120;
    /** Calls (any action) per staff member per minute. */
    public const RATE_HITS   = 30;
    public const RATE_WINDOW = 60;
    public const RATE_BUCKET = 'finance_vault';
    /** vault-YYYYmmdd-His-<8 lowercase hex>, nothing before or after. */
    public const ID_PATTERN = '/^vault-[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/D';
    public const LABEL_MAX  = 80;
    /** Small, optional, scalar envelope fields a client may add (KDF parameters, format version). */
    public const ENVELOPE_EXTRA_KEYS = ['v', 'kdf', 'iter', 'alg', 'hash', 'app'];

    private const B64_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    private const HTACCESS = "# SHG Finance Master — encrypted book backups. Never served over the web;\n"
                           . "# admin/api/finance-vault.php is the only reader.\n"
                           . "Require all denied\n"
                           . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    private string $dir;

    public function __construct(string $dir)
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '') {
            throw new InvalidArgumentException('Finance vault directory is empty.');
        }
        $this->dir = $dir;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** BACKUP_PATH/finance — or, outside production only, SHG_FINANCE_VAULT_DIR (tests, local dev). */
    public static function defaultDir(): string
    {
        return self::resolveDir(APP_ENV, getenv('SHG_FINANCE_VAULT_DIR'), BACKUP_PATH);
    }

    /**
     * The override is honoured only when the environment is not production and
     * it is an absolute path whose parent directory exists.
     */
    public static function resolveDir(string $appEnv, string|false $override, string $backupPath): string
    {
        if (strtolower($appEnv) !== 'production' && is_string($override) && $override !== ''
            && str_starts_with($override, '/') && !str_contains($override, "\0")
            && is_dir(dirname(rtrim($override, '/')))) {
            return rtrim($override, '/');
        }
        return rtrim($backupPath, '/\\') . '/finance';
    }

    /**
     * May the signed-in staff member use the vault? Superadmin, or an explicit
     * 'finance.vault' grant — and never a counter agent.
     */
    public static function mayUse(): bool
    {
        if (Auth::admin() === null || Auth::isCounterAgent()) {
            return false;
        }
        return Auth::isSuperadmin() || Auth::can('finance.vault');
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && strlen($id) === 30 && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** Plain text for a label: no tags, no control characters, one line, at most 80 characters. */
    public static function cleanLabel(mixed $label): string
    {
        if (!is_string($label) && !is_int($label) && !is_float($label)) {
            return '';
        }
        $s = (string) $label;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = strip_tags($s);
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        $s = trim(str_replace(['<', '>'], '', $s));
        return mb_substr($s, 0, self::LABEL_MAX, 'UTF-8');
    }

    /** Padded standard base64 (what btoa() produces), checked without a regex so 25 MB is cheap. */
    public static function isBase64(mixed $s): bool
    {
        if (!is_string($s)) {
            return false;
        }
        $n = strlen($s);
        if ($n === 0 || $n % 4 !== 0) {
            return false;
        }
        $pad = $s[$n - 1] === '=' ? ($s[$n - 2] === '=' ? 2 : 1) : 0;
        $body = $n - $pad;
        return strspn($s, self::B64_ALPHABET, 0, $body) === $body;
    }

    /** Decoded byte length of a string that passed isBase64(). */
    public static function base64Length(string $s): int
    {
        $n = strlen($s);
        $pad = $s[$n - 1] === '=' ? ($s[$n - 2] === '=' ? 2 : 1) : 0;
        return intdiv($n, 4) * 3 - $pad;
    }

    /**
     * Throws FinanceVaultError unless $payload is an "enc1" envelope:
     * {"shgfm":"enc1","salt":b64(16..64 bytes),"iv":b64(12..16 bytes),"data":b64(>=32 bytes)}
     * plus only the small scalar ENVELOPE_EXTRA_KEYS. A plaintext book — or a
     * book smuggled into an extra field, or base64 that decodes to readable
     * text — is refused.
     */
    public static function assertEnvelope(mixed $payload): void
    {
        if (!is_string($payload) || $payload === '') {
            throw new FinanceVaultError('payload खाली छ वा JSON string होइन — payload must be the encrypted backup as a JSON string.', 422);
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new FinanceVaultError('ब्याकअप धेरै ठूलो छ (२५ MB भन्दा बढी) — the backup is larger than 25 MB.', 413);
        }
        $head = substr($payload, 0, 4096);
        if (preg_match('/"shgfm"\s*:\s*"book1"/', $head) === 1 || preg_match('/"journals"\s*:/', $head) === 1) {
            throw new FinanceVaultError('इन्क्रिप्ट नगरिएको (सादा) किताब सर्भरमा राखिँदैन — plaintext books are refused; encrypt the backup first.', 422);
        }
        $env = json_decode($payload, true, 2);
        if (!is_array($env) || array_is_list($env)) {
            throw new FinanceVaultError('यो इन्क्रिप्ट गरिएको ब्याकअप (enc1) होइन — payload is not an encrypted "enc1" envelope.', 422);
        }
        if (($env['shgfm'] ?? null) !== 'enc1') {
            throw new FinanceVaultError('यो इन्क्रिप्ट गरिएको ब्याकअप (enc1) होइन — payload is not an encrypted "enc1" envelope.', 422);
        }
        foreach ($env as $k => $v) {
            if (in_array($k, ['shgfm', 'salt', 'iv', 'data'], true)) {
                continue;
            }
            $okExtra = in_array($k, self::ENVELOPE_EXTRA_KEYS, true)
                && (is_int($v) || (is_string($v) && preg_match('/^[A-Za-z0-9._:\-]{0,40}$/D', $v) === 1));
            if (!$okExtra) {
                throw new FinanceVaultError('ब्याकअपमा अनपेक्षित भाग छ — unexpected field "' . substr((string) $k, 0, 20) . '" in the encrypted envelope.', 422);
            }
        }
        foreach (['salt' => [16, 64], 'iv' => [12, 16], 'data' => [32, PHP_INT_MAX]] as $field => [$min, $max]) {
            $v = $env[$field] ?? null;
            if (!self::isBase64($v)) {
                throw new FinanceVaultError('"' . $field . '" base64 मिलेन — "' . $field . '" is not valid base64.', 422);
            }
            $len = self::base64Length($v);
            if ($len < $min || $len > $max) {
                throw new FinanceVaultError('"' . $field . '" को लम्बाइ मिलेन — "' . $field . '" has an unexpected length (' . $len . ' bytes).', 422);
            }
        }
        // AES-GCM output is indistinguishable from random bytes: about 38% of
        // them are printable ASCII. Base64 of a readable document is nearly
        // 100% printable, so a high ratio means "this was never encrypted".
        $sample = base64_decode(substr($env['data'], 0, 4096), true);
        if ($sample === false) {
            throw new FinanceVaultError('"data" base64 मिलेन — "data" is not valid base64.', 422);
        }
        $n = strlen($sample);
        $printable = $n - strlen((string) preg_replace('/[\x20-\x7E\t\r\n]/', '', $sample));
        if ($n >= 32 && $printable / $n > 0.9) {
            throw new FinanceVaultError('"data" इन्क्रिप्ट गरिएको देखिँदैन — "data" decodes to readable text, not ciphertext.', 422);
        }
    }

    /** Create the folder (0750) with its deny .htaccess and empty index.html. */
    public function ensureStore(): void
    {
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
                throw new FinanceVaultError('सर्भरमा ब्याकअप फोल्डर बन्न सकेन — the backup folder could not be created.', 500);
            }
        }
        @chmod($this->dir, 0750);
        $ht = $this->dir . '/.htaccess';
        if (!is_file($ht) || (string) @file_get_contents($ht) !== self::HTACCESS) {
            if (@file_put_contents($ht, self::HTACCESS, LOCK_EX) === false) {
                throw new FinanceVaultError('सर्भरमा ब्याकअप फोल्डर सुरक्षित गर्न सकिएन — could not write the folder guard.', 500);
            }
            @chmod($ht, 0644);
        }
        $ix = $this->dir . '/index.html';
        if (!is_file($ix)) {
            @file_put_contents($ix, '');
            @chmod($ix, 0644);
        }
    }

    /**
     * Validate and store one encrypted backup, then prune to KEEP.
     *
     * @param array{id?:mixed,name?:mixed} $by who saved it
     * @return array{id:string, createdAt:string, size:int, sha256:string, label:string, mode:string, pruned:int}
     */
    public function save(array $by, mixed $label, mixed $mode, mixed $sha256, mixed $payload): array
    {
        if ($mode !== 'real' && $mode !== 'demo') {
            throw new FinanceVaultError('mode "real" वा "demo" हुनुपर्छ — mode must be "real" or "demo".', 422);
        }
        if (!is_string($sha256) || preg_match('/^[0-9a-fA-F]{64}$/D', $sha256) !== 1) {
            throw new FinanceVaultError('sha256 (६४ hex) चाहिन्छ — sha256 must be the 64-hex SHA-256 of the payload.', 422);
        }
        self::assertEnvelope($payload);
        /** @var string $payload */
        $actual = hash('sha256', $payload);
        if (!hash_equals($actual, strtolower($sha256))) {
            throw new FinanceVaultError('चेकसम मिलेन — upload अधुरो भयो, फेरि पठाउनुहोस् (sha256 mismatch).', 422);
        }
        $label = self::cleanLabel($label);

        $this->ensureStore();
        $free = @disk_free_space($this->dir);
        if ($free !== false && $free < strlen($payload) * 2 + 50 * 1024 * 1024) {
            throw new FinanceVaultError('सर्भरको डिस्क भरिएको छ — the server disk is too full to keep another backup.', 507);
        }

        $lock = $this->lock();
        try {
            $now = microtime(true);
            $sec = (int) floor($now);
            $usec = min(999999, (int) round(($now - $sec) * 1000000));
            $id = '';
            for ($try = 0; $try < 8; $try++) {
                $cand = 'vault-' . date('Ymd-His', $sec) . '-' . sprintf('%05x%03x', $usec, random_int(0, 0xfff));
                if (!file_exists($this->dir . '/' . $cand . '.json')) {
                    $id = $cand;
                    break;
                }
            }
            if ($id === '') {
                throw new FinanceVaultError('ब्याकअप नाम बन्न सकेन, फेरि प्रयास गर्नुहोस् — could not allocate a backup id, try again.', 500);
            }
            $meta = [
                'id'        => $id,
                'createdAt' => date(DATE_ATOM, $sec),
                'by'        => ['id' => (int) ($by['id'] ?? 0), 'name' => self::cleanLabel($by['name'] ?? '')],
                'label'     => $label,
                'mode'      => $mode,
                'sha256'    => $actual,
                'size'      => strlen($payload),
            ];
            // The payload goes LAST so a listing can read just the head of each
            // file. Written in three pieces so a 25 MB backup is not copied
            // into one more 25 MB string first.
            $final = $this->dir . '/' . $id . '.json';
            $tmp   = $this->dir . '/.tmp-' . $id . '-' . bin2hex(random_bytes(4));
            $fh    = @fopen($tmp, 'xb');
            $ok    = false;
            if ($fh !== false) {
                @chmod($tmp, 0640);
                $head = substr(json_encode($meta, self::JSON_FLAGS), 0, -1) . ',"payload":';
                $enc  = json_encode($payload, self::JSON_FLAGS);
                $ok   = fwrite($fh, $head) === strlen($head) && fwrite($fh, $enc) === strlen($enc) && fwrite($fh, '}') === 1;
                unset($enc);
                $ok = fflush($fh) && $ok;
                fclose($fh);
            }
            if (!$ok) {
                @unlink($tmp);
                throw new FinanceVaultError('ब्याकअप सर्भरमा लेख्न सकिएन — the backup could not be written.', 500);
            }
            if (!@rename($tmp, $final)) {
                @unlink($tmp);
                throw new FinanceVaultError('ब्याकअप सर्भरमा लेख्न सकिएन — the backup could not be written.', 500);
            }
            $pruned = $this->prune(self::KEEP, $id);
        } finally {
            $this->unlock($lock);
        }

        return ['id' => $id, 'createdAt' => $meta['createdAt'], 'size' => $meta['size'], 'sha256' => $actual,
                'label' => $label, 'mode' => $mode, 'pruned' => $pruned];
    }

    /**
     * Every stored backup's metadata (no payload), newest first.
     *
     * @return list<array{id:string, createdAt:string, size:int, sha256:string, label:string, mode:string, by:array{id:int,name:string}}>
     */
    public function items(): array
    {
        $out = [];
        foreach ($this->ids() as $id) {
            $m = $this->readMeta($this->dir . '/' . $id . '.json', $id);
            if ($m !== null) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /**
     * Metadata of one backup. 400 for a malformed id (before any file access), 404 when absent.
     *
     * @return array{id:string, createdAt:string, size:int, sha256:string, label:string, mode:string, by:array{id:int,name:string}}
     */
    public function meta(mixed $id): array
    {
        $path = $this->pathOf($id);
        $m = $this->readMeta($path, (string) $id);
        if ($m === null) {
            throw new FinanceVaultError('यो ब्याकअप पढ्न सकिएन — this backup file is damaged.', 500);
        }
        return $m;
    }

    /** Absolute path of an existing backup file; validates the id first. */
    public function pathOf(mixed $id): string
    {
        if (!self::isValidId($id)) {
            throw new FinanceVaultError('ब्याकअप id मिलेन — invalid backup id.', 400);
        }
        /** @var string $id */
        $path = $this->dir . '/' . $id . '.json';
        if (!is_file($path)) {
            throw new FinanceVaultError('यो ब्याकअप भेटिएन — no such backup (it may have been pruned).', 404);
        }
        $real = realpath($path);
        $dirReal = realpath($this->dir);
        if ($real === false || $dirReal === false || dirname($real) !== $dirReal) {
            throw new FinanceVaultError('यो ब्याकअप भेटिएन — no such backup.', 404);
        }
        return $real;
    }

    /**
     * The full stored record, payload included, with its checksum re-verified.
     *
     * @return array{id:string, createdAt:string, by:array, label:string, mode:string, sha256:string, size:int, payload:string}
     */
    public function load(mixed $id): array
    {
        $path = $this->pathOf($id);
        $raw = @file_get_contents($path);
        $rec = is_string($raw) ? json_decode($raw, true, 4) : null;
        if (!is_array($rec) || ($rec['id'] ?? null) !== $id || !is_string($rec['payload'] ?? null)
            || !is_string($rec['sha256'] ?? null) || !hash_equals($rec['sha256'], hash('sha256', $rec['payload']))) {
            Logger::error('Finance vault: stored backup failed its checksum', ['id' => (string) $id], 'admin');
            throw new FinanceVaultError('यो ब्याकअप बिग्रिएको छ (चेकसम मिलेन) — this backup failed its checksum.', 500);
        }
        return $rec;
    }

    /** Delete all but the newest $keep backups (never $protectId). Also sweeps stale temp files. */
    public function prune(int $keep, ?string $protectId = null): int
    {
        $deleted = 0;
        foreach (array_slice($this->ids(), max(0, $keep)) as $id) {
            if ($id === $protectId) {
                continue;
            }
            if (@unlink($this->dir . '/' . $id . '.json')) {
                $deleted++;
            }
        }
        foreach (glob($this->dir . '/.tmp-vault-*') ?: [] as $t) {
            if (is_file($t) && filemtime($t) < time() - 3600) {
                @unlink($t);
            }
        }
        return $deleted;
    }

    /** Valid backup ids on disk, newest first (the name sorts in save order). */
    private function ids(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($this->dir) ?: [] as $f) {
            if (str_ends_with($f, '.json')) {
                $stem = substr($f, 0, -5);
                if (self::isValidId($stem) && is_file($this->dir . '/' . $f)) {
                    $ids[] = $stem;
                }
            }
        }
        rsort($ids, SORT_STRING);
        return $ids;
    }

    /** Read only the head of a stored file (everything before "payload"). */
    private function readMeta(string $path, string $id): ?array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = (string) fread($fh, 8192);
        fclose($fh);
        $cut = strpos($head, ',"payload":');
        $m = null;
        if ($cut !== false) {
            $m = json_decode(substr($head, 0, $cut) . '}', true, 4);
        } elseif ((int) @filesize($path) <= self::MAX_BODY_BYTES * 2) {
            $full = json_decode((string) @file_get_contents($path), true, 4);
            if (is_array($full)) {
                unset($full['payload']);
                $m = $full;
            }
        }
        if (!is_array($m) || ($m['id'] ?? null) !== $id) {
            return null;
        }
        $by = is_array($m['by'] ?? null) ? $m['by'] : [];
        return [
            'id'        => $id,
            'createdAt' => (string) ($m['createdAt'] ?? ''),
            'size'      => (int) ($m['size'] ?? 0),
            'sha256'    => (string) ($m['sha256'] ?? ''),
            'label'     => (string) ($m['label'] ?? ''),
            'mode'      => (string) ($m['mode'] ?? ''),
            'by'        => ['id' => (int) ($by['id'] ?? 0), 'name' => (string) ($by['name'] ?? '')],
        ];
    }

    /** @return resource|null */
    private function lock()
    {
        $fh = @fopen($this->dir . '/.vault.lock', 'c');
        if ($fh !== false) {
            @flock($fh, LOCK_EX);
            return $fh;
        }
        return null;
    }

    /** @param resource|null $fh */
    private function unlock($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}

/**
 * One vault request, start to finish, with every wall in order: signed in →
 * may use the vault → method → CSRF (POST) → rate limit → action. Returns
 * what to send instead of sending it, so tests can drive it in-process.
 *
 * @param array{method?:string, query?:array<string,mixed>, body?:string|Closure|null, contentLength?:int} $req
 *        body: the raw request body, or a Closure that reads it (called only after the walls)
 * @param Closure|null $rateLimit fn(string $identifier): bool — defaults to Security::rateLimit
 * @return array{status:int, json?:array<string,mixed>, stream?:string, prefix?:string, suffix?:string}
 */
function finance_vault_handle(FinanceVault $vault, array $req, ?Closure $rateLimit = null): array
{
    try {
        $admin = Auth::admin();
        if ($admin === null) {
            throw new FinanceVaultError('सत्र सकियो — फेरि लगइन गर्नुहोस् (your session has expired, please sign in again).', 401);
        }
        if (!FinanceVault::mayUse()) {
            Logger::warning('Finance vault refused', ['admin' => $admin['username'] ?? '', 'role' => $admin['role'] ?? '']);
            throw new FinanceVaultError('सर्भर भल्ट प्रयोग गर्ने अनुमति छैन — your role may not use the finance vault.', 403);
        }
        $method = strtoupper((string) ($req['method'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'POST') {
            throw new FinanceVaultError('GET वा POST मात्र — only GET and POST are allowed.', 405);
        }
        if ($method === 'POST' && !Security::verifyCsrf()) {
            Logger::warning('CSRF rejected', ['uri' => $_SERVER['REQUEST_URI'] ?? 'finance-vault']);
            throw new FinanceVaultError('सुरक्षा टोकन सकियो — पेज रिफ्रेस गरेर फेरि प्रयास गर्नुहोस् (security token expired).', 419);
        }
        $limiter = $rateLimit ?? static fn(string $who): bool =>
            Security::rateLimit(FinanceVault::RATE_BUCKET, $who, FinanceVault::RATE_HITS, FinanceVault::RATE_WINDOW);
        if (!$limiter('admin:' . (int) $admin['id'])) {
            throw new FinanceVaultError('धेरै पटक प्रयास भयो — एक मिनेट पर्खनुहोस् (too many requests, wait a minute).', 429);
        }

        if ($method === 'GET') {
            $query  = $req['query'] ?? [];
            $action = is_string($query['action'] ?? null) ? $query['action'] : 'list';
            if ($action === 'list') {
                return ['status' => 200, 'json' => [
                    'ok'       => true,
                    'items'    => $vault->items(),
                    'keep'     => FinanceVault::KEEP,
                    'maxBytes' => FinanceVault::MAX_PAYLOAD_BYTES,
                    'tz'       => APP_TIMEZONE,
                ]];
            }
            if ($action === 'get') {
                $id   = $query['id'] ?? '';
                $meta = $vault->meta($id);          // validates the id before touching any file
                Logger::audit('finance.vault_get', 'finance_vault', $meta['id'], null, null,
                    'Encrypted finance backup downloaded (' . $meta['mode'] . ', ' . $meta['size'] . ' bytes, sha256 ' . substr($meta['sha256'], 0, 12) . ')');
                return ['status' => 200, 'stream' => $vault->pathOf($meta['id']), 'prefix' => '{"ok":true,"item":', 'suffix' => '}'];
            }
            throw new FinanceVaultError('अज्ञात action — unknown action (use list or get).', 400);
        }

        // POST
        $cl = (int) ($req['contentLength'] ?? 0);
        if ($cl > FinanceVault::MAX_BODY_BYTES) {
            throw new FinanceVaultError('ब्याकअप धेरै ठूलो छ (२५ MB भन्दा बढी) — the backup is larger than 25 MB.', 413);
        }
        $body = $req['body'] ?? null;
        if ($body instanceof Closure) {
            $body = $body();
        }
        if (!is_string($body) || $body === '') {
            throw new FinanceVaultError('खाली अनुरोध — empty request body.', 400);
        }
        if (strlen($body) > FinanceVault::MAX_BODY_BYTES) {
            throw new FinanceVaultError('ब्याकअप धेरै ठूलो छ (२५ MB भन्दा बढी) — the backup is larger than 25 MB.', 413);
        }
        $in = json_decode($body, true, 8);
        unset($body);
        if (!is_array($in)) {
            throw new FinanceVaultError('JSON पढ्न सकिएन — the request body is not JSON.', 400);
        }
        if (($in['action'] ?? null) !== 'save') {
            throw new FinanceVaultError('अज्ञात action — unknown action (use save).', 400);
        }
        $saved = $vault->save(
            ['id' => (int) $admin['id'], 'name' => (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? ''))],
            $in['label'] ?? '',
            $in['mode'] ?? null,
            $in['sha256'] ?? null,
            $in['payload'] ?? null
        );
        Logger::audit('finance.vault_save', 'finance_vault', $saved['id'], null,
            ['label' => $saved['label'], 'mode' => $saved['mode'], 'size' => $saved['size'], 'sha256' => $saved['sha256'], 'pruned' => $saved['pruned']],
            'Encrypted finance backup stored on the server');
        return ['status' => 200, 'json' => [
            'ok'        => true,
            'id'        => $saved['id'],
            'createdAt' => $saved['createdAt'],
            'size'      => $saved['size'],
            'sha256'    => $saved['sha256'],
            'pruned'    => $saved['pruned'],
        ]];
    } catch (FinanceVaultError $e) {
        $code = $e->getCode() >= 400 && $e->getCode() <= 599 ? (int) $e->getCode() : 400;
        return ['status' => $code, 'json' => ['ok' => false, 'error' => $e->getMessage(), 'code' => $code]];
    } catch (Throwable $e) {
        Logger::error('finance-vault fatal', ['err' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()], 'admin');
        return ['status' => 500, 'json' => ['ok' => false, 'error' => 'सर्भरमा समस्या भयो — something went wrong on the server; nothing was changed.', 'code' => 500]];
    }
}

if (defined('SHG_FINANCE_VAULT_LIB')) {
    return;   // tests: class + handler only
}

/* ===================================================================== HTTP */

admin_boot();   // signed out → 401 JSON (this is an /api/ URL); the vault walls follow in the handler

$result = finance_vault_handle(new FinanceVault(FinanceVault::defaultDir()), [
    'method'        => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'query'         => $_GET,
    'contentLength' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
    'body'          => static fn(): string => (string) file_get_contents('php://input', false, null, 0, FinanceVault::MAX_BODY_BYTES + 1),
]);

if (!isset($result['stream'])) {
    Response::json($result['json'] ?? ['ok' => false, 'error' => 'No response.', 'code' => 500], $result['status']);
}

// GET ?action=get — stream the stored record as the "item" without decoding a
// 25 MB file into memory. The handle is opened first, so a concurrent prune
// cannot cut the response short.
$fh = @fopen((string) $result['stream'], 'rb');
if ($fh === false) {
    Response::error('यो ब्याकअप भेटिएन — no such backup (it may have been pruned).', 404);
}
$stat = fstat($fh);
$prefix = (string) $result['prefix'];
$suffix = (string) $result['suffix'];
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) (strlen($prefix) + (int) ($stat['size'] ?? 0) + strlen($suffix)));
echo $prefix;
fpassthru($fh);
fclose($fh);
echo $suffix;
exit;
