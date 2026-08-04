<?php
/**
 * =====================================================================
 *  Auth — admin sessions with role permissions, and customer sign-in
 *  by one-time password.
 *
 *  Customers never need a password: the phone number plus an OTP is the
 *  identity, matching how the original booking flow worked.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Auth
{
    /**
     * What each staff role is allowed to do.
     * 'superadmin' is granted everything implicitly.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_PERMISSIONS = [
        'manager' => [
            'dashboard.view', 'bookings.view', 'bookings.edit', 'bookings.cancel',
            'payments.view', 'payments.verify', 'payments.reject',
            'routes.view', 'routes.edit', 'schedules.view', 'schedules.edit',
            'buses.view', 'buses.edit', 'drivers.view', 'drivers.edit',
            'customers.view', 'coupons.view', 'coupons.edit',
            'reports.view', 'liveops.view', 'liveops.edit',
            'messages.view', 'support.view', 'support.reply',
            'tickets.scan', 'waitlist.view',
        ],
        'accountant' => [
            'dashboard.view', 'bookings.view',
            'payments.view', 'payments.verify', 'payments.reject',
            'refunds.view', 'refunds.process',
            'commissions.view', 'commissions.pay',
            'reports.view', 'reports.export',
        ],
        'support' => [
            'dashboard.view', 'bookings.view',
            'customers.view', 'messages.view',
            'support.view', 'support.reply',
            'waitlist.view', 'tickets.scan',
        ],
        'scanner' => [
            'tickets.scan',
        ],
    ];


    /* =================================================================
     *  Admin authentication
     * ================================================================= */

    /**
     * Attempt a staff login.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function adminLogin(string $username, string $password): array
    {
        $username = Security::clean($username, 60);
        $ip       = Security::clientIp();

        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'Enter your username and password.'];
        }

        // Throttle by IP before touching the database.
        if (!Security::rateLimit('admin_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $admin = Database::fetch(
            'SELECT id, username, password_hash, full_name, role, permissions,
                    is_active, locked_until, failed_logins, must_change_pw
               FROM admins
              WHERE username = :u
              LIMIT 1',
            ['u' => $username]
        );

        // Same message whether the user exists or not — no account enumeration.
        $genericFailure = ['ok' => false, 'error' => 'Incorrect username or password.'];

        if ($admin === null) {
            // Spend roughly the same time as a real verify would.
            password_verify($password, '$2y$11$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');
            Logger::warning('Admin login: unknown username', ['username' => $username, 'ip' => $ip]);
            return $genericFailure;
        }

        if ((int) $admin['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'This account has been disabled. Contact the administrator.'];
        }

        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'This account is temporarily locked. Please try again later.'];
        }

        if (!Security::verifyPassword($password, (string) $admin['password_hash'])) {
            $failed = (int) $admin['failed_logins'] + 1;

            $update = ['failed_logins' => $failed];
            if ($failed >= MAX_LOGIN_ATTEMPTS) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MIN * 60));
            }

            Database::update('admins', $update, 'id = :id', ['id' => $admin['id']]);
            Logger::warning('Admin login failed', ['username' => $username, 'ip' => $ip, 'attempt' => $failed]);

            return $genericFailure;
        }

        // Success — clear counters and start a fresh session id.
        Database::update('admins', [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ], 'id = :id', ['id' => $admin['id']]);

        session_regenerate_id(true);

        $_SESSION[ADMIN_SESSION_KEY] = [
            'id'             => (int) $admin['id'],
            'username'       => (string) $admin['username'],
            'full_name'      => (string) $admin['full_name'],
            'role'           => (string) $admin['role'],
            'permissions'    => jsonColumn($admin['permissions']),
            'must_change_pw' => (int) $admin['must_change_pw'] === 1,
            'logged_in_at'   => time(),
            'last_seen'      => time(),
        ];

        Logger::audit('admin.login', 'admin', (string) $admin['id'], null, null, 'Signed in from ' . $ip);

        return ['ok' => true];
    }

    public static function adminLogout(): void
    {
        if (isset($_SESSION[ADMIN_SESSION_KEY]['id'])) {
            Logger::audit('admin.logout', 'admin', (string) $_SESSION[ADMIN_SESSION_KEY]['id']);
        }

        unset($_SESSION[ADMIN_SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * The signed-in staff member, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function admin(): ?array
    {
        $admin = $_SESSION[ADMIN_SESSION_KEY] ?? null;

        if (!is_array($admin) || empty($admin['id'])) {
            return null;
        }

        // Idle timeout.
        $idleLimit = Settings::getInt('session_timeout_min', 45) * 60;
        if ($idleLimit > 0 && (time() - (int) ($admin['last_seen'] ?? 0)) > $idleLimit) {
            self::adminLogout();
            return null;
        }

        $_SESSION[ADMIN_SESSION_KEY]['last_seen'] = time();

        return $admin;
    }

    public static function isAdmin(): bool
    {
        return self::admin() !== null;
    }

    /**
     * Does the signed-in staff member hold this permission?
     */
    public static function can(string $permission): bool
    {
        $admin = self::admin();

        if ($admin === null) {
            return false;
        }

        $role = (string) ($admin['role'] ?? '');

        if ($role === 'superadmin') {
            return true;
        }

        $rolePerms  = self::ROLE_PERMISSIONS[$role] ?? [];
        $extraPerms = is_array($admin['permissions'] ?? null) ? $admin['permissions'] : [];

        return in_array($permission, $rolePerms, true)
            || in_array($permission, $extraPerms, true);
    }

    /**
     * Require a signed-in admin, optionally holding a permission.
     * Redirects HTML requests to the login page; answers JSON for the API.
     */
    public static function requireAdmin(string $permission = ''): array
    {
        $admin = self::admin();

        $wantsJson = str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');

        if ($admin === null) {
            if ($wantsJson) {
                Response::unauthorized('Your session has expired. Please sign in again.');
            }
            // Preserve the deep link (e.g. payments.php?pnr=SHG-...) so signing
            // in lands the admin back where they meant to go.
            $file = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
            $qs   = (string) ($_SERVER['QUERY_STRING'] ?? '');
            $next = $file . ($qs !== '' ? '?' . $qs : '');
            Response::redirect('admin/login.php?next=' . rawurlencode($next));
        }

        if ($permission !== '' && !self::can($permission)) {
            Logger::warning('Permission denied', [
                'admin'      => $admin['username'] ?? '',
                'permission' => $permission,
            ]);

            if ($wantsJson) {
                Response::forbidden('Your role does not allow that action.');
            }

            http_response_code(403);
            exit('You do not have permission to view this page.');
        }

        return $admin;
    }


    /* =================================================================
     *  Customer authentication (OTP)
     * ================================================================= */

    /**
     * Generate and store an OTP. Returns the plain code so the caller
     * can hand it to the SMS/WhatsApp/email sender — it is never stored
     * in plain form and never returned to the browser.
     *
     * @return array{ok: bool, error?: string, code?: string, expires?: int}
     */
    public static function issueOtp(string $identifier, string $purpose = 'login', string $channel = 'sms'): array
    {
        $identifier = Security::clean($identifier, 191);

        if ($identifier === '') {
            return ['ok' => false, 'error' => 'Enter your mobile number.'];
        }

        // One OTP per minute, six per hour, per identifier.
        if (!Security::rateLimit('otp_send_' . $purpose, $identifier, 1, OTP_RESEND_SECONDS)) {
            return ['ok' => false, 'error' => 'Please wait a moment before requesting another code.'];
        }
        if (!Security::rateLimit('otp_hourly_' . $purpose, $identifier, 6, 3600)) {
            return ['ok' => false, 'error' => 'Too many codes requested. Please try again in an hour.'];
        }

        // Invalidate any outstanding codes for this purpose.
        Database::update(
            'otp_codes',
            ['is_used' => 1],
            'identifier = :i AND purpose = :p AND is_used = 0',
            ['i' => $identifier, 'p' => $purpose]
        );

        $code      = Security::numericCode(OTP_LENGTH);
        $expiresAt = time() + (OTP_EXPIRY_MINUTES * 60);

        Database::insert('otp_codes', [
            'identifier' => $identifier,
            'channel'    => $channel,
            'purpose'    => $purpose,
            'code_hash'  => password_hash($code, PASSWORD_BCRYPT),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'ip_address' => Security::clientIp(),
        ]);

        return ['ok' => true, 'code' => $code, 'expires' => $expiresAt];
    }

    /**
     * Check a submitted OTP.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function verifyOtp(string $identifier, string $code, string $purpose = 'login'): array
    {
        $identifier = Security::clean($identifier, 191);
        $code       = Security::digits($code, OTP_LENGTH);

        if ($code === '') {
            return ['ok' => false, 'error' => 'Enter the code we sent you.'];
        }

        if (!Security::rateLimit('otp_verify', $identifier, 10, 600)) {
            return ['ok' => false, 'error' => 'Too many attempts. Please request a new code.'];
        }

        $row = Database::fetch(
            'SELECT id, code_hash, attempts, max_attempts, expires_at
               FROM otp_codes
              WHERE identifier = :i AND purpose = :p AND is_used = 0
              ORDER BY id DESC
              LIMIT 1',
            ['i' => $identifier, 'p' => $purpose]
        );

        if ($row === null) {
            return ['ok' => false, 'error' => 'That code is no longer valid. Please request a new one.'];
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            Database::update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => $row['id']]);
            return ['ok' => false, 'error' => 'That code has expired. Please request a new one.'];
        }

        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            Database::update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => $row['id']]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
        }

        if (!password_verify($code, (string) $row['code_hash'])) {
            Database::update(
                'otp_codes',
                ['attempts' => (int) $row['attempts'] + 1],
                'id = :id',
                ['id' => $row['id']]
            );
            return ['ok' => false, 'error' => 'That code is incorrect.'];
        }

        Database::update('otp_codes', ['is_used' => 1], 'id = :id', ['id' => $row['id']]);

        return ['ok' => true];
    }

    /**
     * Sign a customer in, creating the account on first use.
     *
     * @return array<string, mixed> the user row
     */
    public static function loginUser(string $phone, string $name = ''): array
    {
        $phone = normalisePhone($phone);

        $user = Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]);

        if ($user === null) {
            $userId = Database::insert('users', [
                'phone'          => $phone,
                'full_name'      => $name !== '' ? Security::clean($name, 120) : null,
                'role'           => 'customer',
                'phone_verified' => 1,
                'last_login_at'  => date('Y-m-d H:i:s'),
            ]);

            $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
            Logger::info('New customer registered', ['phone' => maskPhone($phone)]);
        } else {
            $update = [
                'phone_verified' => 1,
                'last_login_at'  => date('Y-m-d H:i:s'),
            ];
            if ($name !== '' && empty($user['full_name'])) {
                $update['full_name'] = Security::clean($name, 120);
            }

            Database::update('users', $update, 'id = :id', ['id' => $user['id']]);
        }

        if ($user === null) {
            throw new RuntimeException('User record could not be created.');
        }

        if ((int) $user['is_blocked'] === 1) {
            throw new RuntimeException('This account has been suspended. Please contact support.');
        }

        session_regenerate_id(true);

        $_SESSION[USER_SESSION_KEY] = [
            'id'        => (int) $user['id'],
            'phone'     => (string) $user['phone'],
            'name'      => (string) ($user['full_name'] ?? ''),
            'role'      => (string) $user['role'],
            'ref_code'  => (string) ($user['ref_code'] ?? ''),
            'logged_in' => time(),
            'last_seen' => time(),
        ];

        return $user;
    }

    public static function logoutUser(): void
    {
        unset($_SESSION[USER_SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * Session summary of the signed-in customer, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        $user = $_SESSION[USER_SESSION_KEY] ?? null;

        if (!is_array($user) || empty($user['id'])) {
            return null;
        }

        $idleLimit = Settings::getInt('session_timeout_min', 45) * 60;
        if ($idleLimit > 0 && (time() - (int) ($user['last_seen'] ?? 0)) > $idleLimit) {
            self::logoutUser();
            return null;
        }

        $_SESSION[USER_SESSION_KEY]['last_seen'] = time();

        return $user;
    }

    /**
     * Fresh user row from the database (points, tier, wallet).
     *
     * @return array<string, mixed>|null
     */
    public static function userRecord(): ?array
    {
        $session = self::user();

        if ($session === null) {
            return null;
        }

        return Database::fetch('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $session['id']]);
    }

    public static function isUser(): bool
    {
        return self::user() !== null;
    }

    public static function isAgent(): bool
    {
        $user = self::user();
        return $user !== null && ($user['role'] ?? '') === 'agent';
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireUser(): array
    {
        $user = self::user();

        if ($user === null) {
            if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/')) {
                Response::unauthorized();
            }
            Response::redirect('customer/login.php');
        }

        return $user;
    }

    /**
     * The token that owns this visitor's seat holds. Anonymous visitors
     * get one too, so seat locking works before sign-in.
     */
    public static function lockToken(): string
    {
        if (empty($_SESSION['lock_token'])) {
            $_SESSION['lock_token'] = Security::randomToken(24);
        }

        return (string) $_SESSION['lock_token'];
    }
}
