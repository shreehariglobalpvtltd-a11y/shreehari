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
                 WHERE s.ended_at IS NULL AND s.last_seen >= :since'
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
    public static function endDevice(int $adminId, string $deviceHash, int $by): int
    {
        if ($adminId <= 0 || $deviceHash === '' || !self::ensure()) {
            return 0;
        }
        return Database::update('admin_sessions', [
            'ended_at'   => date('Y-m-d H:i:s'),
            'ended_by'   => $by > 0 ? $by : null,
            'end_reason' => 'device revoked',
        ], 'admin_id = :a AND device_hash = :d AND ended_at IS NULL', ['a' => $adminId, 'd' => $deviceHash]);
    }

    /** Housekeeping: drop rows that ended (or went idle) long ago. */
    public static function prune(int $days = 90): int
    {
        if (!self::ensure()) {
            return 0;
        }
        $cut = date('Y-m-d H:i:s', time() - max(7, $days) * 86400);
        try {
            return Database::delete('admin_sessions', 'last_seen < :c', ['c' => $cut]);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
