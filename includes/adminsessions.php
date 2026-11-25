<?php
/**
 * =====================================================================
 *  AdminSessions — every signed-in office browser, and a way to end one
 *  (29 Oct 2026, owner's management-system prompt, Phase 1 "Session
 *  management: view all active sessions, force-logout any user remotely").
 *
 *  WHY THIS EXISTS
 *  ---------------
 *  PHP keeps sessions as files the office cannot list, so until now the
 *  only ways to throw somebody out were to deactivate the account or change
 *  its password — both of which also hurt the person's NEXT sign-in. This
 *  registry is one row per signed-in browser: who, which device, which IP,
 *  when it started and when it was last used. Ending a row ends that
 *  browser on its next 30-second re-check in Auth::admin(), and nothing
 *  else about the account changes.
 *
 *  The row is keyed by a random token the session carries ('sk'), never by
 *  the PHP session id: session_regenerate_id() changes the id at sign-in
 *  and at a password change, and the row must survive both. Only the
 *  SHA-256 of the token is stored, so the table cannot be replayed as a
 *  cookie.
 *
 *  A session that was open BEFORE this shipped carries no token. It is
 *  registered on its first re-check (within 30 s of the deploy), so the
 *  list is complete half a minute after release without signing anyone out.
 *
 *  NOTHING HERE MAY BLOCK A SIGN-IN. Same rule as LoginLog: a missing table
 *  or a failed write is swallowed and the session simply carries on.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AdminSessions
{
    private static bool $ready = false;

    /** Create the table on first use (no hand-run migration needed). */
    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS admin_sessions (
                    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    token_hash    CHAR(64)     NOT NULL,
                    admin_id      INT UNSIGNED NOT NULL,
                    device_hash   CHAR(64)     NULL,
                    label         VARCHAR(120) NULL,
                    ip            VARCHAR(45)  NULL,
                    via           VARCHAR(20)  NOT NULL DEFAULT 'password',
                    created_at    DATETIME     NOT NULL,
                    last_seen     DATETIME     NOT NULL,
                    ended_at      DATETIME     NULL,
                    ended_by      INT UNSIGNED NULL,
                    end_reason    VARCHAR(120) NULL,
                    UNIQUE KEY uq_token (token_hash),
                    KEY idx_admin (admin_id, ended_at),
                    KEY idx_live (ended_at, last_seen)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * A new browser just signed in: mint its token, store the row and
     * return the token for the session to carry. '' when the registry is
     * unavailable — the session then works exactly as before.
     */
    public static function open(int $adminId, string $via = 'password'): string
    {
        if ($adminId <= 0 || !self::ensure()) {
            return '';
        }
        try {
            $token = Security::randomToken(24);
            $now   = date('Y-m-d H:i:s');
            Database::insert('admin_sessions', [
                'token_hash'  => self::hashToken($token),
                'admin_id'    => $adminId,
                'device_hash' => LoginLog::deviceHash() ?: null,
                'label'       => mb_substr(LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120),
                'ip'          => Security::clientIp(),
                'via'         => mb_substr($via, 0, 20),
                'created_at'  => $now,
                'last_seen'   => $now,
            ]);
            return $token;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Called from Auth::admin()'s 30-second re-check. Returns false only
     * when the office has ended this browser — every other outcome
     * (no table, DB hiccup, a session from before the registry) is true.
     *
     * @param array<string,mixed> $session the ADMIN_SESSION_KEY array; 'sk' is
     *                                      written back when one is minted
     */
    public static function stillOpen(array &$session): bool
    {
        $adminId = (int) ($session['id'] ?? 0);
        if ($adminId <= 0 || !self::ensure()) {
            return true;
        }
        try {
            $token = (string) ($session['sk'] ?? '');
            $rmid  = (int) ($session['rmid'] ?? 0);
            if ($token === '' && $rmid > 0) {
                /* 19 Nov 2026 (u7 review): a remembered device's second tab (resume grace) has no key
                   of its own — it lives on the remembered row: ended there = ended here. */
                $row = Database::fetch('SELECT id, admin_id, ended_at FROM admin_sessions WHERE id = :id', ['id' => $rmid]);
                if ($row === null || (int) $row['admin_id'] !== $adminId || !empty($row['ended_at'])) {
                    return false;
                }
                Database::update('admin_sessions', [
                    'last_seen' => date('Y-m-d H:i:s'),
                    'ip'        => Security::clientIp(),
                ], 'id = :id', ['id' => $rmid]);
                return true;
            }
            if ($token === '') {
                // Opened before the registry existed: register it now.
                $token = self::open($adminId, !empty($session['via_otp']) ? 'otp' : 'earlier');
                if ($token !== '') {
                    $session['sk'] = $token;
                }
                return true;
            }
            $row = Database::fetch(
                'SELECT id, admin_id, ended_at FROM admin_sessions WHERE token_hash = :h',
                ['h' => self::hashToken($token)]
            );
            if ($row === null) {
                // Row pruned or lost — re-register rather than sign them out.
                $fresh = self::open($adminId, 'earlier');
                if ($fresh !== '') {
                    $session['sk'] = $fresh;
                }
                return true;
            }
            if ((int) $row['admin_id'] !== $adminId || !empty($row['ended_at'])) {
                return false;
            }
            Database::update('admin_sessions', [
                'last_seen' => date('Y-m-d H:i:s'),
                'ip'        => Security::clientIp(),
            ], 'id = :id', ['id' => (int) $row['id']]);
            return true;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** This browser signed out on its own. */
    public static function close(string $token, string $reason = 'signed out'): void
    {
        if ($token === '' || !self::ensure()) {
            return;
        }
        try {
            Database::update('admin_sessions', [
                'ended_at'   => date('Y-m-d H:i:s'),
                'end_reason' => mb_substr($reason, 0, 120),
            ], 'token_hash = :h AND ended_at IS NULL', ['h' => self::hashToken($token)]);
        } catch (Throwable $e) {
        }
    }

    /** Is this row the browser making the request? */
    public static function isCurrent(array $row): bool
    {
        $token = (string) ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? '');
        return $token !== '' && hash_equals((string) ($row['token_hash'] ?? ''), self::hashToken($token));
    }

    /**
     * Browsers still signed in: not ended, and used within the idle timeout
     * (a browser left idle longer is already signed out by Auth::admin()).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function active(int $adminId = 0, int $limit = 300): array
    {
        if (!self::ensure()) {
            return [];
        }
        $idle  = max(5, Settings::getInt('session_timeout_min', 45));
        $since = date('Y-m-d H:i:s', time() - $idle * 60);
        $sql = 'SELECT s.*, a.username, a.full_name, a.role, a.is_active
                  FROM admin_sessions s
                  JOIN admins a ON a.id = s.admin_id
                 WHERE s.ended_at IS NULL AND (s.last_seen >= :since'
             // 19 Nov 2026 (u7): a device that stays signed in is signed in, idle or not
             . (self::rememberReady() ? ' OR s.remember_until > NOW()' : '') . ')'
             . ($adminId > 0 ? ' AND s.admin_id = :a' : '')
             . ' ORDER BY s.last_seen DESC LIMIT ' . max(1, min(1000, $limit));
        $params = ['since' => $since];
        if ($adminId > 0) {
            $params['a'] = $adminId;
        }
        try {
            return Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** One row by id (any state), with the account it belongs to. */
    public static function get(int $id): ?array
    {
        if ($id <= 0 || !self::ensure()) {
            return null;
        }
        return Database::fetch(
            'SELECT s.*, a.username, a.full_name, a.role
               FROM admin_sessions s JOIN admins a ON a.id = s.admin_id
              WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /** End one browser. It is signed out on its next request (≤ 30 s). */
    public static function end(int $id, int $by, string $reason = 'ended by the office'): bool
    {
        if ($id <= 0 || !self::ensure()) {
            return false;
        }
        $n = Database::update('admin_sessions', [
            'ended_at'   => date('Y-m-d H:i:s'),
            'ended_by'   => $by > 0 ? $by : null,
            'end_reason' => mb_substr($reason, 0, 120),
        ], 'id = :id AND ended_at IS NULL', ['id' => $id]);
        return $n > 0;
    }

    /**
     * End every browser of one account — plus its WhatsApp staff sign-ins,
     * which never pass through Auth::admin(). $keepToken spares the browser
     * doing it when someone signs themselves out everywhere else.
     */
    public static function endAll(int $adminId, int $by, string $reason = 'signed out everywhere', string $keepToken = ''): int
    {
        if ($adminId <= 0) {
            return 0;
        }
        require_once __DIR__ . '/walogin.php';
        WaLogin::revokeAdmin($adminId, $by > 0 ? $by : null);
        if (!self::ensure()) {
            return 0;
        }
        $where  = 'admin_id = :a AND ended_at IS NULL';
        $params = ['a' => $adminId];
        if ($keepToken !== '') {
            $where      .= ' AND token_hash <> :keep';
            $params['keep'] = self::hashToken($keepToken);
        }
        return Database::update('admin_sessions', [
            'ended_at'   => date('Y-m-d H:i:s'),
            'ended_by'   => $by > 0 ? $by : null,
            'end_reason' => mb_substr($reason, 0, 120),
        ], $where, $params);
    }

    /** A revoked device takes its open browsers with it. */
    public static function endDevice(int $adminId, string $deviceHash, int $by, string $reason = 'device revoked'): int
    {
        if ($adminId <= 0 || $deviceHash === '' || !self::ensure()) {
            return 0;
        }
        return Database::update('admin_sessions', [
            'ended_at'   => date('Y-m-d H:i:s'),
            'ended_by'   => $by > 0 ? $by : null,
            'end_reason' => mb_substr($reason, 0, 120),
        ], 'admin_id = :a AND device_hash = :d AND ended_at IS NULL', ['a' => $adminId, 'd' => $deviceHash]);
    }

    /* =================================================================
     *  16 Nov 2026 (master v2.0, P1-5b): per-role device limit.
     *  Setting device_limit_<role> (int, 0 = no limit = how it always was).
     *  The roles are Auth::MATRIX_ROLES; a superadmin is never limited.
     *  Auth::finishAdminSession() asks before it opens a session; over the
     *  limit the sign-in is held (Auth::deviceHold) and admin/login.php
     *  offers to sign one of the other devices out.
     * ================================================================= */

    /** The most devices one account of this role may be signed in on at once; 0 = no limit. */
    public static function deviceLimit(string $role): int
    {
        if ($role === '' || $role === 'superadmin' || !in_array($role, Auth::MATRIX_ROLES, true)) {
            return 0;
        }
        try {
            return max(0, min(50, Settings::getInt('device_limit_' . $role, 0)));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The devices this account is signed in on right now (live rows of active(),
     * so a device idle past the timeout is not counted), one entry per device —
     * a row with no device id counts as a device of its own. $exceptHash (this
     * browser's LoginLog::deviceHash()) is left out. Newest first.
     *
     * @return list<array{hash: string, label: string, last_seen: string, ids: list<int>}>
     */
    public static function liveDevices(int $adminId, string $exceptHash = ''): array
    {
        if ($adminId <= 0) {
            return [];
        }
        $out = [];
        foreach (self::active($adminId) as $s) {
            $h = (string) ($s['device_hash'] ?? '');
            if ($h !== '' && $exceptHash !== '' && hash_equals($h, $exceptHash)) {
                continue;
            }
            $k = $h !== '' ? 'h:' . $h : 's:' . (int) $s['id'];
            if (!isset($out[$k])) {
                $out[$k] = ['hash' => $h, 'label' => (string) ($s['label'] ?? ''), 'last_seen' => (string) ($s['last_seen'] ?? ''), 'ids' => []];
            }
            $out[$k]['ids'][] = (int) $s['id'];
        }
        return array_values($out);
    }

    /** Housekeeping: drop rows that ended (or went idle) long ago — never a row still remembered. */
    public static function prune(int $days = 90): int
    {
        if (!self::ensure()) {
            return 0;
        }
        $cut = date('Y-m-d H:i:s', time() - max(7, $days) * 86400);
        try {
            return Database::delete('admin_sessions',
                'last_seen < :c' . (self::rememberReady() ? ' AND (remember_until IS NULL OR remember_until < NOW() OR ended_at IS NOT NULL)' : ''),
                ['c' => $cut]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* =================================================================
     *  19 Nov 2026 (u7, LG-1): STAY SIGNED IN ON THIS DEVICE.
     *
     *  Owner: "agent haru lai bar bar logout hunchha — ek choti login
     *  gare pachhi aafno phone ma login nai rahos". The PHP session file is
     *  deleted by the server after ~24-54 min idle whatever SESSION_LIFETIME
     *  says, so a session alone can never do this. A remember token can:
     *
     *    - minted on the session's own registry row at sign-in (every door
     *      ends in Auth::finishAdminSession), 32 random bytes; the row keeps
     *      only its SHA-256, the browser keeps it in cookie shg_rm (HttpOnly,
     *      Secure on https, SameSite=Lax, path /) — never in a URL or a page;
     *    - bound to the device: the row's device_hash (shg_dev) must match the
     *      browser presenting it, so a copied shg_rm alone opens nothing;
     *    - rotated on every use (resume): a new token and a new session key;
     *      the previous hash is honoured for PREV_GRACE seconds only, so two
     *      tabs waking at once do not throw each other out;
     *    - ended by: Sign out on that device (adminLogout closes the row), the
     *      office (Sessions → End / sign out everywhere / device revoke), a
     *      password change (the row's pw_stamp no longer matches; the person
     *      changing their own keeps THIS device — restamp()), switching the
     *      account off or locking it, the role leaving the remembered list.
     *  Field roles (setting login_remember_roles) stay login_remember_days
     *  (default 365); every other role and every 2FA account gets
     *  login_remember_office_hours (default 24). 0 switches either off.
     *  Inert until database/upgrade-2026-11-19-u7-remember-login.sql has run.
     * ================================================================= */

    public const REMEMBER_COOKIE = 'shg_rm';
    public const PREV_GRACE      = 120;   // seconds an older token still opens after a rotation
    public const DEFAULT_ROLES   = ['agent', 'counter', 'scanner', 'support', 'operations', 'staff'];

    /** Test hook: the plain token the last remember() / resume() handed to the browser. */
    public static ?string $lastRememberToken = null;

    private static ?bool $rmReady = null;

    /** Has the u7 migration added the remember columns? */
    public static function rememberReady(): bool
    {
        if (self::$rmReady === null) {
            try {
                self::$rmReady = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_sessions'
                        AND COLUMN_NAME IN ('remember_hash', 'remember_prev_hash', 'remember_rotated_at', 'remember_until', 'pw_stamp')",
                    [], 0) === 5;
            } catch (Throwable $e) {
                self::$rmReady = false;
            }
        }
        return self::$rmReady;
    }

    /**
     * How long this account may stay signed in on one device, in seconds (0 = not at all).
     *
     * @param array<string, mixed> $admin admins row (id, role)
     */
    public static function rememberWindow(array $admin): int
    {
        try {
            $role = (string) ($admin['role'] ?? '');
            $id   = (int) ($admin['id'] ?? 0);
            if ($id <= 0 || $role === '' || $role === 'viewer') {
                return 0;
            }
            if (self::rememberIsField($admin)) {
                /* 25 Nov 2026 (mp1-lg, owner plan: "30-90 din samma login rahos"): the code default is
                   90 days (was 365) and the window is capped by login_remember_max_days (default 90,
                   at most 730) - a phone that is never used for 90 days asks for its PIN door again.
                   The window still slides on every use, so a phone in daily use never signs out. */
                $cap = max(1, min(730, Settings::getInt('login_remember_max_days', 90)));
                return max(0, min($cap, Settings::getInt('login_remember_days', 90))) * 86400;
            }
            return max(0, min(720, Settings::getInt('login_remember_office_hours', 24))) * 3600;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * 19 Nov 2026 (u7 review): a FIELD account (a role in login_remember_roles, never superadmin /
     * manager / accountant, no 2FA). Only these get the long, sliding window, skip the idle timeout
     * and get the PIN relock instead of the idle sign-out. Every other remembered account gets a
     * FIXED cap from its original sign-in (login_remember_office_hours) and keeps its idle timeout.
     *
     * @param array<string, mixed> $admin admins row (id, role)
     */
    public static function rememberIsField(array $admin): bool
    {
        try {
            $role = (string) ($admin['role'] ?? '');
            $id   = (int) ($admin['id'] ?? 0);
            if ($id <= 0 || $role === '' || $role === 'viewer' || in_array($role, ['superadmin', 'manager', 'accountant'], true)) {
                return false;
            }
            $roles = array_values(array_filter(array_map('strval', Settings::getArray('login_remember_roles', self::DEFAULT_ROLES))));
            return in_array($role, $roles, true) && !Auth::admin2faEnabled($id);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 19 Nov 2026 (u7 review): should a resumed FIELD session open behind the PIN / pattern
     * relock? Yes when the idle lock is on (staff_idle_lock_min > 0, default 30), quick unlock is
     * on, the account has a PIN or pattern, and the device was last used longer ago than that.
     */
    public static function relockDue(int $adminId, string $lastSeen): bool
    {
        try {
            $min = (int) Settings::getString('staff_idle_lock_min', '30');
            if ($adminId <= 0 || $min <= 0) {
                return false;
            }
            $ts = strtotime($lastSeen);
            if ($ts !== false && $ts > time() - $min * 60) {
                return false;
            }
            require_once __DIR__ . '/quickunlock.php';
            if (!QuickUnlock::on()) {
                return false;
            }
            $st = QuickUnlock::status($adminId);
            return $st['pin'] || $st['pattern'];
        } catch (Throwable $e) {
            return false;
        }
    }

    /** This browser's device hash WITHOUT minting a device cookie ('' = none). */
    private static function presentedDeviceHash(): string
    {
        $raw = (string) ($_COOKIE[LoginLog::DEVICE_COOKIE] ?? '');
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? hash('sha256', $raw) : '';
    }

    private static function setRememberCookie(string $token, int $until): void
    {
        $_COOKIE[self::REMEMBER_COOKIE] = $token;
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires'  => $until,
            'path'     => '/',
            'secure'   => Security::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Expire this browser's remember cookie (the row is ended separately). */
    public static function forgetCookie(): void
    {
        $had = isset($_COOKIE[self::REMEMBER_COOKIE]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
        if (!$had || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600, 'path' => '/', 'secure' => Security::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    /**
     * Sign-in just finished on this browser: remember it on its own registry row.
     * Called by Auth::finishAdminSession right after open(). Never blocks a sign-in.
     *
     * @param array<string, mixed> $admin
     * @param string $sk       the session key open() returned (names the row)
     * @param string $pwStamp  Auth::pwStamp() of the password on file NOW
     * @return int the Unix time the device stays remembered until (0 = not remembered)
     */
    public static function remember(array $admin, string $sk, string $pwStamp): int
    {
        self::$lastRememberToken = null;
        if ($sk === '' || $pwStamp === '' || !self::rememberReady()) {
            return 0;
        }
        $window = self::rememberWindow($admin);
        $dev    = self::presentedDeviceHash();
        if ($window <= 0 || $dev === '') {
            self::forgetCookie();   // an older device's token must not outlive a sign-in that is not remembered
            return 0;
        }
        try {
            $token = bin2hex(random_bytes(32));
            $until = time() + $window;
            $n = Database::update('admin_sessions', [
                'remember_hash'       => self::hashToken($token),
                'remember_prev_hash'  => null,
                'remember_rotated_at' => date('Y-m-d H:i:s'),
                'remember_until'      => date('Y-m-d H:i:s', $until),
                'pw_stamp'            => $pwStamp,
            ], 'token_hash = :h AND admin_id = :a AND ended_at IS NULL',
               ['h' => self::hashToken($sk), 'a' => (int) $admin['id']]);
            if ($n < 1) {
                return 0;
            }
            self::setRememberCookie($token, $until);
            self::$lastRememberToken = $token;
            if (random_int(1, 50) === 1) {
                self::prune();   // 19 Nov 2026 (u7 review): nothing else calls it — housekeeping about once every 50 sign-ins
            }
            return $until;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The person changed their OWN password on this browser: this device stays
     * remembered with the new password (every other remembered device of the
     * account is refused on its next resume — its stamp is the old one).
     */
    public static function restamp(string $sk, string $pwStamp, int $rowId = 0, int $adminId = 0): void
    {
        if ($pwStamp === '' || !self::rememberReady()) {
            return;
        }
        /* 19 Nov 2026 (u7 review): the remembered row is found by the session key, OR by the row id
           a resumed session carries ('rmid' — a sibling tab's session has no key of its own), OR by
           this browser's own remember cookie — always only rows of this account. */
        $or = [];
        $params = [];
        if ($sk !== '') {
            $or[] = 'token_hash = :h';
            $params['h'] = self::hashToken($sk);
        }
        if ($adminId > 0) {
            if ($rowId > 0) {
                $or[] = 'id = :rid';
                $params['rid'] = $rowId;
            }
            $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $raw) === 1) {
                $or[] = 'remember_hash = :c1 OR remember_prev_hash = :c2';
                $params['c1'] = self::hashToken($raw);
                $params['c2'] = $params['c1'];
            }
        }
        if ($or === []) {
            return;
        }
        $where = '(' . implode(' OR ', $or) . ') AND ended_at IS NULL AND remember_hash IS NOT NULL';
        if ($adminId > 0) {
            $where .= ' AND admin_id = :a';
            $params['a'] = $adminId;
        }
        try {
            Database::update('admin_sessions', ['pw_stamp' => $pwStamp], $where, $params);
        } catch (Throwable $e) {
        }
    }

    /**
     * Before anything else on a request with no staff session: does this browser
     * carry a remember token that still opens its account? On success the session
     * is open again (Auth::resumeSession, via 'remember') and both tokens rotated.
     * Every refusal ends the row (when it is one of ours) and expires the cookie.
     */
    public static function resume(): bool
    {
        self::$lastRememberToken = null;
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw === '') {
            return false;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1 || !self::rememberReady()) {
            self::forgetCookie();
            return false;
        }
        $h = self::hashToken($raw);
        try {
            $row = Database::fetch(
                'SELECT * FROM admin_sessions WHERE remember_hash = :h LIMIT 1', ['h' => $h]);
            $grace = false;
            if ($row === null) {
                $row = Database::fetch(
                    'SELECT * FROM admin_sessions WHERE remember_prev_hash = :h LIMIT 1', ['h' => $h]);
                $grace = $row !== null
                    && strtotime((string) ($row['remember_rotated_at'] ?? '')) >= time() - self::PREV_GRACE;
                if ($row !== null && !$grace) {
                    // an OLD token presented after its grace: a copy is in use somewhere — end the device
                    self::endRemembered($row, 'remember token reused after rotation');
                    self::forgetCookie();
                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;   // database hiccup: leave the cookie, try on the next request
        }
        if ($row === null) {
            if (!Security::rateLimit('admin_remember_miss', Security::clientIp(), 30, 600)) {
                Logger::warning('Remember token: many unknown tokens from one IP', ['ip' => Security::clientIp()], 'security');
            }
            self::forgetCookie();
            return false;
        }

        $admin = Database::fetch(
            'SELECT id, username, full_name, role, permissions, is_active, must_change_pw, password_hash, locked_until
               FROM admins WHERE id = :id LIMIT 1', ['id' => (int) $row['admin_id']]);
        $dev = self::presentedDeviceHash();
        /* 19 Nov 2026 (u7 review): only a FIELD account slides. Every other remembered account
           (office roles, any 2FA account) has a FIXED cap: login_remember_office_hours from the
           original sign-in (the row's created_at), never extended by a resume — and, like an open
           browser, it is not let back in after more than session_timeout_min idle. */
        $field  = $admin !== null && self::rememberIsField($admin);
        $window = $admin !== null ? self::rememberWindow($admin) : 0;
        $idleOk = true;
        $cap    = 0;
        if ($admin !== null && !$field) {
            $created = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $cur     = strtotime((string) ($row['remember_until'] ?? '')) ?: 0;
            $cap     = $created > 0 ? min($cur, $created + $window) : $cur;
            $idleMin = Settings::getInt('session_timeout_min', 45);
            $seen    = strtotime((string) ($row['last_seen'] ?? '')) ?: 0;
            $idleOk  = $idleMin <= 0 || $seen >= time() - $idleMin * 60;
        }
        $why = match (true) {
            !empty($row['ended_at'])                                                 => 'session ended',
            empty($row['remember_until']) || strtotime((string) $row['remember_until']) <= time() => 'remember expired',
            $dev === '' || !hash_equals((string) ($row['device_hash'] ?? ''), $dev)  => 'another device',
            $admin === null                                                          => 'account deleted',
            (int) $admin['is_active'] !== 1                                          => 'account switched off',
            !empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time() => 'account locked',
            !hash_equals((string) ($row['pw_stamp'] ?? ''), Auth::pwStamp((string) $admin['password_hash'])) => 'password changed',
            $window <= 0                                                             => 'role not remembered',
            !$field && $cap <= time()                                                => 'remember expired',
            !$idleOk                                                                 => 'idle too long',
            default                                                                  => '',
        };
        if ($why !== '') {
            if ($why !== 'session ended') {
                self::endRemembered($row, 'not resumed: ' . $why);
            }
            Logger::audit('admin.login.resume_refused', 'admin', (string) (int) $row['admin_id'], null, ['why' => $why],
                'Stay signed in: this device was not let back in (' . $why . ')');
            self::forgetCookie();
            return false;
        }

        // Rotate: a new session key and (unless a sibling tab just did) a new remember token.
        try {
            $sk = Security::randomToken(24);
            $set = ['token_hash' => self::hashToken($sk), 'last_seen' => date('Y-m-d H:i:s'), 'ip' => Security::clientIp()];
            $token = '';
            // field: a sliding window from now; everyone else: the fixed cap, never extended
            $until = $field ? min(time() + $window, time() + 730 * 86400) : $cap;
            if (!$grace) {
                $token = bin2hex(random_bytes(32));
                $set += [
                    'remember_hash' => self::hashToken($token), 'remember_prev_hash' => $h,
                    'remember_rotated_at' => date('Y-m-d H:i:s'), 'remember_until' => date('Y-m-d H:i:s', $until),
                ];
            }
            if ($grace) {
                // a sibling tab rotated a moment ago: touch the row only (an unchanged row reports 0 rows — not a failure)
                Database::update('admin_sessions', ['last_seen' => $set['last_seen'], 'ip' => $set['ip']],
                    'id = :id AND ended_at IS NULL', ['id' => (int) $row['id']]);
            } elseif (Database::update('admin_sessions', $set, 'id = :id AND ended_at IS NULL AND remember_hash = :old',
                    ['id' => (int) $row['id'], 'old' => $h]) < 1) {
                // only while the row is still the one we read: a sibling request rotated it between our read
                // and this write — then this one is a sibling tab too (the grace path), else nothing opens
                $grace = (int) Database::scalar(
                    'SELECT COUNT(*) FROM admin_sessions WHERE id = :id AND ended_at IS NULL AND remember_prev_hash = :h
                        AND remember_rotated_at >= :since',
                    ['id' => (int) $row['id'], 'h' => $h, 'since' => date('Y-m-d H:i:s', time() - self::PREV_GRACE)], 0) === 1;
                if (!$grace) {
                    return false;
                }
            }
            if (!$grace) {
                self::setRememberCookie($token, $until);
                self::$lastRememberToken = $token;
            } else {
                /* a sibling tab owns the row's key. 19 Nov 2026 (u7 review): this session is NOT
                   registered on a row of its own — it carries the remembered row's id ('rmid') and
                   stillOpen() checks that row, so the office's End on it signs this tab out too. */
                $sk = '';
                $now = strtotime((string) Database::scalar('SELECT remember_until FROM admin_sessions WHERE id = :id',
                    ['id' => (int) $row['id']], '')) ?: 0;
                if ($now > 0) {
                    $until = $field ? $now : min($now, $cap);
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        // a field device back after more than staff_idle_lock_min opens behind its PIN / pattern
        // (a sibling-tab resume cannot see how long the device was idle — the sibling just touched
        // the row — so it always opens behind the PIN / pattern when the account has one)
        $lock = $field && self::relockDue((int) $admin['id'], $grace ? '' : (string) ($row['last_seen'] ?? ''));
        Auth::resumeSession($admin, $row, $sk, (int) $until, $field, $lock);
        return true;
    }

    /** @param array<string, mixed> $row */
    private static function endRemembered(array $row, string $reason): void
    {
        try {
            Database::update('admin_sessions', [
                'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => mb_substr($reason, 0, 120),
            ], 'id = :id AND ended_at IS NULL', ['id' => (int) $row['id']]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Sign out on THIS device: end the row this browser's remember cookie names
     * (current or just-rotated token) and expire the cookie. Covers a session
     * that a sibling tab registered on a row of its own.
     */
    public static function closeRemembered(string $reason = 'signed out'): void
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $raw) === 1 && self::rememberReady()) {
            try {
                $h = self::hashToken($raw);
                Database::update('admin_sessions', [
                    'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => mb_substr($reason, 0, 120),
                ], '(remember_hash = :h OR remember_prev_hash = :p) AND ended_at IS NULL', ['h' => $h, 'p' => $h]);
            } catch (Throwable $e) {
            }
        }
        self::forgetCookie();
    }

    /** "remembered until" for a registry row ('' = not remembered / not ready). */
    public static function rememberedUntil(array $row): string
    {
        $u = (string) ($row['remember_until'] ?? '');
        return $u !== '' && empty($row['ended_at']) && strtotime($u) > time() ? $u : '';
    }
}
