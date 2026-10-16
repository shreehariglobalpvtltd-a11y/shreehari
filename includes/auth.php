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
            // A branch manager works the refund desk alongside accounts —
            // waiting for the accountant to log in would strand a customer
            // whose bus left without them.
            'refunds.view', 'refunds.process',
            // Settles the counter agents' wallets — commission payouts and
            // cash handovers happen at the branch, not at head office.
            'commissions.view', 'commissions.pay',
            'routes.view', 'routes.edit', 'schedules.view', 'schedules.edit', 'schedules.manage',
            'buses.view', 'buses.edit', 'drivers.view', 'drivers.edit',
            // staff.manage lets a branch manager onboard counter agents,
            // toggle inactive staff, and edit commission tiers. Was
            // referenced by admin/_guard.php:77 and admin/agent.php:26 but
            // absent from every role list, so only the isSuperadmin() bypass
            // reached it — a manager saw the Staff & Agents nav hidden.
            'staff.manage',
            // settings.manage: the switches, fares and AI knowledge a branch
            // manager may change from Settings / System Health / AI Knowledge.
            // Secrets (API keys, tokens) stay write-only for everyone.
            'settings.manage',
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
            // Read-only on the refund desk: support answers "where is my
            // money" all day, but only accounts/manager may approve.
            'refunds.view',
            'customers.view', 'messages.view',
            'support.view', 'support.reply',
            'waitlist.view', 'tickets.scan',
        ],
        'scanner' => [
            'tickets.scan',
        ],
        // Office staff (§8): verify customer payments and — when a messages
        // page exists — reply to support. Deliberately NO bookings, routes,
        // analytics, settings or financial exports. With the current admin nav
        // this shows the "Verify Payments" screen only.
        'official' => [
            'payments.view', 'payments.verify', 'payments.reject',
            'messages.view', 'support.view', 'support.reply',
        ],
        // Counter staff (5 Sep 2026): a company ticket window — Mehsana,
        // Ahmedabad, Baroda, Surat and spares, each with its OWN login so the
        // activity log can name the counter. Serves any walk-in customer:
        // search / create (counter mode) / edit passenger / move seat /
        // reschedule / cancel (refund slabs apply — no overrides) / reprint
        // the PDF, and verify the cash/UPI they just took. Deliberately NO
        // dashboard.view (revenue KPIs), NO commissions.view (agent ledgers),
        // NO staff/routes/fleet management and NO report exports.
        'counter' => [
            'bookings.view', 'bookings.edit', 'bookings.cancel',
            'payments.view', 'payments.verify', 'payments.reject',
            'refunds.view',
            'schedules.view', 'schedules.edit',
            'customers.view', 'tickets.scan',
            'messages.view', 'support.view', 'support.reply',
        ],
        // Ticketing agent (Part 2): sells seats and transfers passengers from
        // the Seat Map + Trips board, and can look up any booking for support.
        // Deliberately NO dashboard/analytics/payments/routes/settings — those
        // nav items gate on dashboard.view, which agents are NOT granted.
        'agent' => [
            'schedules.view', 'schedules.edit', 'bookings.view',
            // 4 Sep 2026 (agent bulk-cancel): an agent may cancel — one at a
            // time or in bulk — ONLY the tickets they sold themselves
            // (bookings.sold_by_admin_id = self). Never anyone else's; that
            // stays behind bookings.cancel (manager / superadmin). See
            // Auth::mayCancelBooking() — the one place the rule is spelled out.
            'bookings.cancel_own',
        ],
    ];

    /* =================================================================
     *  Cancel authorisation — one rule, used by the single-ticket cancel
     *  (booking-view.php), the register row button and the bulk endpoint.
     * ================================================================= */

    /**
     * May the signed-in staff member cancel THIS booking?
     *
     *   bookings.cancel      → any booking (manager, superadmin, extra grant)
     *   bookings.cancel_own  → only when they sold it (agents)
     *
     * @param array<string,mixed> $booking needs sold_by_admin_id
     */
    public static function mayCancelBooking(array $booking): bool
    {
        if (self::can('bookings.cancel')) {
            return true;
        }
        if (!self::can('bookings.cancel_own')) {
            return false;
        }
        $me = (int) (self::admin()['id'] ?? 0);
        return $me > 0 && (int) ($booking['sold_by_admin_id'] ?? 0) === $me;
    }

    /** True when the signed-in staff member may cancel at least something. */
    public static function mayCancelAny(): bool
    {
        return self::can('bookings.cancel') || self::can('bookings.cancel_own');
    }


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
            // Recorded too: a throttled IP is the loudest signal in the log,
            // and leaving it out would make a brute-force attempt look like
            // it simply stopped. No admin id — we deliberately refuse before
            // touching the database, so we do not know who was targeted.
            LoginLog::record($username, 'locked');
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $admin = Database::fetch(
            'SELECT id, username, password_hash, full_name, phone, role, permissions,
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
            LoginLog::record($username, 'unknown_user');
            return $genericFailure;
        }

        if ((int) $admin['is_active'] !== 1) {
            LoginLog::record($username, 'disabled', (int) $admin['id']);
            return ['ok' => false, 'error' => 'This account has been disabled. Contact the administrator.'];
        }

        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            LoginLog::record($username, 'locked', (int) $admin['id']);
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
            LoginLog::record($username, 'bad_password', (int) $admin['id']);

            return $genericFailure;
        }

        /* ---- Optional second factor (Point 10) -----------------------
           OPT-IN per admin and DEFAULT OFF, so for every account that has
           not turned it on this block does nothing and the login flow is
           byte-for-byte what it was before. When it IS on we issue a code to
           the admin's registered phone and hold the session at a pending step
           — the password alone no longer completes the sign-in.

           Availability guarantee (owner's hard constraint — no lockout): 2FA
           can only ever ADD a factor, never remove the password path's
           availability. If a code cannot be MINTED (gateway down) or there is
           no phone on file, we log it and complete with the password alone —
           exactly today's behaviour — rather than stranding the admin. */
        if (self::admin2faEnabled((int) $admin['id'])) {
            $phone = normalisePhone((string) ($admin['phone'] ?? ''));
            if ($phone !== '') {
                $otp = self::issueOtp('admin:' . (int) $admin['id'], 'login', 'whatsapp');
                if (!empty($otp['ok'])) {
                    require_once __DIR__ . '/notify.php';
                    $text = Settings::getString('company_name', APP_NAME)
                          . ' एडमिन लगइन कोड: ' . $otp['code'] . "\n"
                          . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य। यो कोड कसैलाई नबताउनुहोस्।';
                    Notify::whatsapp($phone, $text);
                    if (Settings::getBool('sms_send_otp', false)) { Notify::sms($phone, $text); }

                    // Hold at the pending step. NOTHING that opens a session has
                    // run yet — failed_logins is only cleared on real success.
                    $_SESSION['admin_2fa_pending'] = [
                        'id' => (int) $admin['id'], 'ip' => $ip, 'at' => time(),
                    ];
                    Logger::audit('admin.2fa.sent', 'admin', (string) $admin['id'], null, null,
                        'Second-factor code issued at login');
                    return ['ok' => false, 'twofa' => true, 'resend' => OTP_RESEND_SECONDS];
                }
                // Code could not be minted — degrade to password-only (no lockout).
                Logger::audit('admin.2fa.delivery_failed', 'admin', (string) $admin['id'], null, null,
                    'Second factor could not be sent — signed in with password only');
            } else {
                Logger::audit('admin.2fa.no_phone', 'admin', (string) $admin['id'], null, null,
                    '2FA enabled but no phone on file — signed in with password only');
            }
        }

        self::finishAdminSession($admin, $ip);

        return ['ok' => true];
    }

    /**
     * Admin 2FA, step 2 — verify the second-factor code held from adminLogin()
     * and open the session. Kept password-authenticated (viaOtp=false): the
     * admin DID enter their password, so the forced-password-change gate must
     * still apply, unlike the agent OTP path.
     *
     * @return array{ok: bool, twofa?: bool, error?: string}
     */
    public static function adminLogin2faVerify(string $otpCode): array
    {
        $ip      = Security::clientIp();
        $pending = $_SESSION['admin_2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id'])) {
            return ['ok' => false, 'error' => 'Your sign-in step expired — please enter your username and password again.'];
        }
        // 10-minute pending window, independent of the OTP's own expiry.
        if ((int) ($pending['at'] ?? 0) < time() - 600) {
            unset($_SESSION['admin_2fa_pending']);
            return ['ok' => false, 'error' => 'Your sign-in step expired — please enter your username and password again.'];
        }
        if (!Security::rateLimit('admin_2fa_verify', $ip, 10, 600, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'twofa' => true, 'error' => 'Too many attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $adminId = (int) $pending['id'];
        $result  = self::verifyOtp('admin:' . $adminId, trim($otpCode), 'login');
        if (empty($result['ok'])) {
            return ['ok' => false, 'twofa' => true, 'error' => (string) ($result['error'] ?? 'That code is not right.')];
        }

        // Re-load the account fresh — never trust anything but the id from the
        // pending marker, and re-assert it is still active.
        $admin = Database::fetch(
            'SELECT id, username, full_name, role, permissions, is_active, must_change_pw
               FROM admins WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $adminId]
        );
        unset($_SESSION['admin_2fa_pending']);
        if ($admin === null) {
            return ['ok' => false, 'error' => 'That account is no longer available.'];
        }

        self::finishAdminSession($admin, $ip, false);
        return ['ok' => true];
    }

    /**
     * Re-issue the admin second-factor code for the account currently held at
     * the pending step (the Resend button). Never reveals whether a pending
     * step exists to an unauthenticated caller beyond a generic message.
     *
     * @return array{ok: bool, resend?: int, error?: string}
     */
    public static function adminLogin2faResend(): array
    {
        $pending = $_SESSION['admin_2fa_pending'] ?? null;
        if (!is_array($pending) || empty($pending['id'])) {
            return ['ok' => false, 'error' => 'Your sign-in step expired — please sign in again.'];
        }
        $admin = Database::fetch('SELECT id, phone FROM admins WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $pending['id']]);
        $phone = $admin !== null ? normalisePhone((string) ($admin['phone'] ?? '')) : '';
        if ($phone === '') {
            return ['ok' => false, 'error' => 'Could not resend the code.'];
        }
        $otp = self::issueOtp('admin:' . (int) $admin['id'], 'login', 'whatsapp');
        if (empty($otp['ok'])) {
            return ['ok' => false, 'error' => (string) ($otp['error'] ?? 'Please wait a moment before requesting another code.')];
        }
        require_once __DIR__ . '/notify.php';
        $text = Settings::getString('company_name', APP_NAME)
              . ' एडमिन लगइन कोड: ' . $otp['code'] . "\n"
              . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य। यो कोड कसैलाई नबताउनुहोस्।';
        Notify::whatsapp($phone, $text);
        if (Settings::getBool('sms_send_otp', false)) { Notify::sms($phone, $text); }
        return ['ok' => true, 'resend' => OTP_RESEND_SECONDS];
    }

    /* =================================================================
     *  Admin two-factor authentication (Point 10) — opt-in, per account.
     *  Stored as a JSON list of admin ids in the 'admin_2fa_ids' setting
     *  (zero-SQL, same shape as agent_commission_overrides). Absent /
     *  empty => nobody has 2FA => login is exactly as before.
     * ================================================================= */

    private const S2FA_KEY = 'admin_2fa_ids';

    /** @return array<int,int> the admin ids with 2FA switched on */
    private static function admin2faIds(): array
    {
        $raw = Settings::getArray(self::S2FA_KEY, []);
        $out = [];
        foreach ($raw as $v) { $n = (int) $v; if ($n > 0) { $out[$n] = $n; } }
        return array_values($out);
    }

    public static function admin2faEnabled(int $adminId): bool
    {
        return in_array($adminId, self::admin2faIds(), true);
    }

    /**
     * Turn an admin's 2FA on/off. Enabling requires a phone on file (the code
     * has nowhere to go otherwise). Audited either way. Callers own the
     * authorisation check (self-service in change-password.php; a superadmin
     * rescuing another account in staff.php).
     */
    public static function setAdmin2fa(int $adminId, bool $on, int $by = 0): void
    {
        if ($adminId <= 0) { throw new RuntimeException('Unknown account.'); }
        if ($on) {
            $phone = normalisePhone((string) Database::scalar(
                'SELECT phone FROM admins WHERE id = :id', ['id' => $adminId], ''));
            if ($phone === '') {
                throw new RuntimeException('Add a mobile number to this account first — the login code is sent there.');
            }
        }
        $ids = self::admin2faIds();
        if ($on && !in_array($adminId, $ids, true)) { $ids[] = $adminId; }
        if (!$on) { $ids = array_values(array_diff($ids, [$adminId])); }
        Settings::set(self::S2FA_KEY, array_values($ids), 'json', 'security', false);
        Logger::audit('admin.2fa.' . ($on ? 'enabled' : 'disabled'), 'admin', (string) $adminId,
            null, null, 'Two-factor ' . ($on ? 'enabled' : 'disabled') . ' by admin #' . $by);
    }

    /**
     * Shared session bootstrap for BOTH staff login paths (password and
     * agent OTP), so counters, session hygiene, LoginLog device tracking
     * and audit stay identical however someone signed in.
     *
     * @param array<string, mixed> $admin admins row (id, username, full_name,
     *                                    role, permissions, must_change_pw)
     * @param bool $viaOtp an OTP-authenticated agent never knows a password,
     *                     so the forced password-change gate (which demands
     *                     the CURRENT password) would deadlock them — the
     *                     flag is carried in the session and the gate skipped.
     */
    private static function finishAdminSession(array $admin, string $ip, bool $viaOtp = false): void
    {
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
            /* Carried honestly for every door. This used to read
                   $viaOtp ? false : ...
               which silently voided the forced-password-change control for
               anyone who signed in through the agent code + mobile-OTP door:
               an account issued a temporary password with "must change"
               ticked could keep using that password indefinitely.

               The reason for the old bypass was real, not laziness —
               change-password.php demands the CURRENT password, which an OTP
               user has never typed, so honouring the flag here would have
               deadlocked them. That is fixed at the other end instead:
               change-password.php now accepts a forced change from an
               OTP-verified session without the old password, because the
               WhatsApp OTP already proved control of the registered mobile.
               The control is enforced rather than skipped, and nobody is
               locked out — which was the owner's constraint all along. */
            'must_change_pw' => (int) $admin['must_change_pw'] === 1,
            'via_otp'        => $viaOtp,
            'logged_in_at'   => time(),
            'last_seen'      => time(),
            // Stamped only by a real sign-in: admin() re-reads the admins row
            // against this clock, so a session built by hand in a test (no
            // stamp) is left exactly as the test wrote it.
            'checked_at'     => time(),
        ];

        // Record the attempt and note whether this browser is new for this
        // account. Runs before any output, since it may mint the device
        // cookie. A failure in here is swallowed by LoginLog — logging must
        // never be the reason someone cannot sign in.
        $newDevice = LoginLog::record((string) $admin['username'], 'success', (int) $admin['id']);
        $_SESSION[ADMIN_SESSION_KEY]['new_device'] = $newDevice;

        Logger::audit('admin.login', 'admin', (string) $admin['id'], null, null,
            'Signed in from ' . $ip . ($viaOtp ? ' (agent OTP)' : ''));

        if ($newDevice) {
            Logger::audit('admin.login.newdevice', 'admin', (string) $admin['id'], null,
                ['device' => LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))],
                'First sign-in from this device');
        }
    }

    /**
     * Resolve an agent-code + mobile pair to the admins row it belongs to,
     * or null. ONE generic failure for every mismatch kind — which part
     * was wrong is never revealed (no code/number enumeration).
     *
     * Agent codes are NOT a DB column: they live in the 'agent_codes'
     * settings map managed by AgentWallet (SHG-027 → admins.id).
     */
    private static function resolveAgentLogin(string $code, string $mobile): ?array
    {
        $codeNum = (int) (preg_replace('/\D/', '', $code) ?? '');
        $mobile  = normalisePhone($mobile);
        if ($codeNum < 1 || strlen($mobile) < 7) {
            return null;
        }

        require_once __DIR__ . '/agentwallet.php';
        $adminId = AgentWallet::adminForAgentCode($codeNum);
        if ($adminId === null) {
            return null;
        }

        $admin = Database::fetch(
            "SELECT id, username, full_name, phone, role, permissions,
                    is_active, locked_until, must_change_pw
               FROM admins
              WHERE id = :id AND role = 'agent' AND is_active = 1
              LIMIT 1",
            ['id' => $adminId]
        );
        if ($admin === null) {
            return null;
        }

        // Both sides normalised — admins.phone is stored as typed in Staff
        // ('+91 98765 43210' and bare '9876543210' must both match).
        $registered = normalisePhone((string) ($admin['phone'] ?? ''));
        if ($registered === '' || $registered !== $mobile) {
            return null;
        }

        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            return null;
        }

        return $admin;
    }

    /**
     * Agent login, step 1: agent code (SHG-027 / 27) + registered mobile →
     * a WhatsApp/SMS OTP to that mobile.
     *
     * The OTP identifier is namespaced 'agent:<phone>' so an agent whose
     * number is also a customer number shares neither otp_codes rows nor
     * the per-identifier rate buckets with the customer checkout flow.
     *
     * @return array{ok: bool, error?: string, resend?: int}
     */
    public static function agentLoginStart(string $code, string $mobile): array
    {
        $ip      = Security::clientIp();
        $generic = ['ok' => false, 'error' => 'Agent code and mobile number did not match our records.'];

        if (!Security::rateLimit('agent_otp', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $admin = self::resolveAgentLogin($code, $mobile);
        if ($admin === null) {
            Logger::warning('Agent OTP login: no match', ['code' => Security::clean($code, 12), 'ip' => $ip]);
            return $generic;
        }

        $phone = normalisePhone((string) $admin['phone']);
        $otp   = self::issueOtp('agent:' . $phone, 'login', 'whatsapp');
        if (!$otp['ok']) {
            return ['ok' => false, 'error' => (string) ($otp['error'] ?? 'Could not send a code right now.')];
        }

        // Auth mints, Notify delivers — same split as the customer flow.
        // The plain code goes to the phone and NOWHERE else (no debug echo).
        require_once __DIR__ . '/notify.php';
        $text = Settings::getString('company_name', APP_NAME)
              . ' एजेन्ट लगइन कोड: ' . $otp['code'] . "\n"
              . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य। यो कोड कसैलाई नबताउनुहोस्।';
        Notify::whatsapp($phone, $text);
        if (Settings::getBool('sms_send_otp', false)) {
            Notify::sms($phone, $text);
        }

        Logger::audit('agent.otp.sent', 'admin', (string) $admin['id'], null, null, 'Agent login OTP issued');

        return ['ok' => true, 'resend' => OTP_RESEND_SECONDS];
    }

    /**
     * Agent login, step 2: verify the OTP and open the staff session
     * (role stays whatever the admins row says — always 'agent' here,
     * enforced by resolveAgentLogin; the portal parameter never decides).
     *
     * @return array{ok: bool, error?: string}
     */
    public static function agentLoginVerify(string $code, string $mobile, string $otpCode): array
    {
        $ip = Security::clientIp();

        if (!Security::rateLimit('agent_otp_verify', $ip, 10, 600, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $admin = self::resolveAgentLogin($code, $mobile);
        if ($admin === null) {
            return ['ok' => false, 'error' => 'Agent code and mobile number did not match our records.'];
        }

        $phone  = normalisePhone((string) $admin['phone']);
        $result = self::verifyOtp('agent:' . $phone, trim($otpCode), 'login');
        if (!$result['ok']) {
            LoginLog::record((string) $admin['username'], 'bad_password', (int) $admin['id']);
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'That code is not right.')];
        }

        self::finishAdminSession($admin, $ip, true);

        return ['ok' => true];
    }

    /* =================================================================
     *  Agent password login (4 Sep 2026) — email + username + password.
     *
     *  Self-contained: no OTP, no WhatsApp, no gateway. All THREE must match
     *  the same active agent account; one generic failure message for every
     *  mismatch so nothing can be enumerated. Same per-IP throttle and
     *  per-account lockout counters as the office password login, and the
     *  same session bootstrap, so LoginLog / audit / device tracking stay
     *  identical however a member of staff signed in.
     * ================================================================= */

    /**
     * @return array{ok: bool, error?: string}
     */
    public static function agentPasswordLogin(string $email, string $username, string $password): array
    {
        $username = strtolower(Security::clean($username, 60));
        $email    = strtolower(Security::email($email));
        $ip       = Security::clientIp();
        $generic  = ['ok' => false, 'error' => 'Email, username and password did not match an agent account.'];

        if ($username === '' || $email === '' || $password === '') {
            return ['ok' => false, 'error' => 'Enter your email, username and password.'];
        }

        if (!Security::rateLimit('agent_pw_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            LoginLog::record($username, 'locked');
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        $admin = Database::fetch(
            "SELECT id, username, email, password_hash, full_name, phone, role, permissions,
                    is_active, locked_until, failed_logins, must_change_pw
               FROM admins
              WHERE username = :u AND role = 'agent'
              LIMIT 1",
            ['u' => $username]
        );

        if ($admin === null) {
            password_verify($password, '$2y$11$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');
            Logger::warning('Agent login: unknown username', ['username' => $username, 'ip' => $ip]);
            LoginLog::record($username, 'unknown_user');
            return $generic;
        }
        if ((int) $admin['is_active'] !== 1) {
            LoginLog::record($username, 'disabled', (int) $admin['id']);
            return ['ok' => false, 'error' => 'This agent account is not active. Contact the office.'];
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            LoginLog::record($username, 'locked', (int) $admin['id']);
            return ['ok' => false, 'error' => 'This account is temporarily locked. Please try again later.'];
        }

        // Email AND password must both match. A wrong email counts as a
        // failed attempt exactly like a wrong password, so guessing one
        // factor at a time is no cheaper than guessing both.
        $emailOk = strtolower(trim((string) ($admin['email'] ?? ''))) === $email;
        $pwOk    = Security::verifyPassword($password, (string) $admin['password_hash']);
        if (!$emailOk || !$pwOk) {
            $failed = (int) $admin['failed_logins'] + 1;
            $update = ['failed_logins' => $failed];
            if ($failed >= MAX_LOGIN_ATTEMPTS) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MIN * 60));
            }
            Database::update('admins', $update, 'id = :id', ['id' => $admin['id']]);
            Logger::warning('Agent login failed', ['username' => $username, 'ip' => $ip, 'attempt' => $failed, 'email_ok' => $emailOk]);
            LoginLog::record($username, 'bad_password', (int) $admin['id']);
            return $generic;
        }

        // Password-authenticated (viaOtp = false): a temporary password
        // handed out by the office with "must change" ticked is still
        // funnelled to change-password.php exactly like other staff.
        self::finishAdminSession($admin, $ip, false);
        Logger::audit('agent.login', 'admin', (string) $admin['id'], null, null, 'Agent signed in with email + username + password');

        return ['ok' => true];
    }

    /**
     * Office control of a staff member's login credentials (4 Sep 2026):
     * username, email and/or a new password, with an optional "must change
     * on next login" flag. Used by admin/agent.php (Login credentials panel)
     * and admin/staff.php. The CALLER owns the authorisation check
     * (staff.manage / superadmin); this method validates, enforces
     * uniqueness, writes and audits (the password itself is never logged —
     * only the fact that it changed).
     *
     * @param array{username?:string,email?:string,password?:string,must_change?:bool} $in
     *        Omitted keys are left untouched; an empty password means "keep".
     * @return array{changed: array<int,string>, username: string, email: string}
     * @throws RuntimeException with a user-safe message
     */
    public static function setStaffCredentials(int $adminId, array $in, int $by = 0): array
    {
        $row = Database::fetch(
            'SELECT id, username, email, role, must_change_pw FROM admins WHERE id = :id LIMIT 1',
            ['id' => $adminId]
        );
        if ($row === null) {
            throw new RuntimeException('Staff account not found.');
        }

        $update  = [];
        $changed = [];
        $before  = ['username' => (string) $row['username'], 'email' => (string) ($row['email'] ?? '')];
        $after   = $before;

        if (array_key_exists('username', $in)) {
            $username = strtolower(trim(Security::clean((string) $in['username'], 60)));
            if (!preg_match('/^[a-z0-9_.@\-]{3,60}$/', $username)) {
                throw new RuntimeException('Username must be 3–60 characters: letters, numbers, . _ - @ only.');
            }
            if ($username !== (string) $row['username']) {
                if (Database::exists('SELECT 1 FROM admins WHERE username = :u AND id <> :id LIMIT 1', ['u' => $username, 'id' => $adminId])) {
                    throw new RuntimeException('That username is already taken by another account.');
                }
                $update['username'] = $username;
                $after['username']  = $username;
                $changed[]          = 'username';
            }
        }

        if (array_key_exists('email', $in)) {
            $raw   = trim((string) $in['email']);
            $email = $raw === '' ? '' : strtolower(Security::email($raw));
            if ($raw !== '' && $email === '') {
                throw new RuntimeException('Enter a valid email address.');
            }
            if ($email !== strtolower((string) ($row['email'] ?? ''))) {
                if ($email !== '' && Database::exists('SELECT 1 FROM admins WHERE LOWER(email) = :e AND id <> :id LIMIT 1', ['e' => $email, 'id' => $adminId])) {
                    throw new RuntimeException('That email is already used by another staff account.');
                }
                $update['email'] = $email !== '' ? $email : null;
                $after['email']  = $email;
                $changed[]       = 'email';
            }
        }

        $password = (string) ($in['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                throw new RuntimeException('The new password must be at least 8 characters.');
            }
            $update['password_hash'] = Security::hashPassword($password);
            $update['failed_logins'] = 0;
            $update['locked_until']  = null;
            $changed[]               = 'password';
        }

        if (array_key_exists('must_change', $in)) {
            $must = !empty($in['must_change']) ? 1 : 0;
            if ($must !== (int) $row['must_change_pw']) {
                $update['must_change_pw'] = $must;
                $changed[] = $must ? 'must_change_on' : 'must_change_off';
            }
        }

        if ($update === []) {
            return ['changed' => [], 'username' => $after['username'], 'email' => $after['email']];
        }

        Database::update('admins', $update, 'id = :id', ['id' => $adminId]);

        // The agent's own open session (if any) keeps working — the id is the
        // key — but a renamed username must show the new name next time the
        // session array is rebuilt; sessions are rebuilt on every login.
        Logger::audit(
            (string) $row['role'] === 'agent' ? 'agent.credentials' : 'staff.credentials',
            'admin',
            (string) $adminId,
            $before,
            $after + ['password_changed' => in_array('password', $changed, true)],
            'Login credentials changed (' . implode(', ', $changed) . ') by admin #' . $by
        );

        return ['changed' => $changed, 'username' => $after['username'], 'email' => $after['email']];
    }

    public static function adminLogout(): void
    {
        if (isset($_SESSION[ADMIN_SESSION_KEY]['id'])) {
            Logger::audit('admin.logout', 'admin', (string) $_SESSION[ADMIN_SESSION_KEY]['id']);
            LoginLog::record(
                (string) ($_SESSION[ADMIN_SESSION_KEY]['username'] ?? ''),
                'logout',
                (int) $_SESSION[ADMIN_SESSION_KEY]['id']
            );
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

        /* Re-validate against the admins row, at most once every 30 seconds.
           Role, permissions, is_active and must_change_pw used to be read
           ONCE at sign-in and never again, so deactivating a staff member
           in staff.php, changing their role or trimming their permissions
           did nothing until they next signed in — a departed counter agent
           kept selling until their idle timeout. Now a deactivated or
           deleted account is signed out on its next request, and a changed
           role or permission set takes effect within half a minute. The
           check is skipped when the database is unreachable (the page fails
           for a better reason anyway) and for sessions that carry no
           checked_at stamp — those are built by hand in tests. */
        if (isset($admin['checked_at']) && (time() - (int) $admin['checked_at']) > 30) {
            try {
                $row = Database::fetch(
                    'SELECT is_active, role, permissions, must_change_pw, locked_until
                       FROM admins WHERE id = :id',
                    ['id' => (int) $admin['id']]
                );
            } catch (Throwable $e) {
                $row = false;
            }

            if ($row !== false) {
                $locked = $row !== null && !empty($row['locked_until'])
                    && strtotime((string) $row['locked_until']) > time();
                if ($row === null || (int) $row['is_active'] !== 1 || $locked) {
                    Logger::audit('admin.session_revoked', 'admin', (string) $admin['id'], null, null,
                        $row === null ? 'account deleted' : ($locked ? 'account locked' : 'account deactivated'));
                    // Not adminLogout(): that writes a 'logout' login-log row
                    // in the person's name and regenerates the session id,
                    // which has no session to regenerate under the CLI.
                    unset($_SESSION[ADMIN_SESSION_KEY]);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                    return null;
                }

                $_SESSION[ADMIN_SESSION_KEY]['role']           = (string) $row['role'];
                $_SESSION[ADMIN_SESSION_KEY]['permissions']    = jsonColumn($row['permissions']);
                $_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] = (int) $row['must_change_pw'] === 1;
                $_SESSION[ADMIN_SESSION_KEY]['checked_at']     = time();
                $admin = $_SESSION[ADMIN_SESSION_KEY];
            }
        }

        return $admin;
    }

    public static function isAdmin(): bool
    {
        return self::admin() !== null;
    }

    /**
     * Is the signed-in staff member a super-admin? Used to gate overrides
     * that no other role may perform — e.g. selling a staff-reserved berth.
     */
    public static function isSuperadmin(): bool
    {
        $admin = self::admin();

        return $admin !== null && (string) ($admin['role'] ?? '') === 'superadmin';
    }

    /**
     * Is the signed-in STAFF member a counter / ticketing agent?
     *
     * Deliberately NOT called isAgent() — that name is taken, and means
     * something else entirely. This codebase carries two unrelated "agent"
     * concepts:
     *   - counter agent  — admins.role = 'agent'. Sells at a counter, earns
     *     commission through agent_ledger, and owns a sale via
     *     bookings.sold_by_admin_id. This method.
     *   - referral agent — users.role = 'agent' with a ref_code, paid through
     *     the commissions table. That is what isAgent() reports on.
     * Booking data isolation gates on the first of the two; confusing them
     * would scope every query by the wrong id.
     */
    /**
     * Staff who may SELL: the office roles plus counter agents. Mirrors the
     * `canSell` rule index.php ships to counter mode, so client and server
     * agree on who this is.
     *
     * Used (6 Sep 2026) to lift the CUSTOMER-only date limits — a selling
     * staff member may record a ticket for any travel date: a paper ticket
     * from three days ago, a group two months out. Customers stay on
     * today → horizon. It never lifts a cancelled trip, a per-date OFF, or
     * the inaugural floor.
     */
    public static function isSellingStaff(): bool
    {
        if (self::admin() === null) {
            return false;
        }
        return self::isSuperadmin() || self::can('bookings.edit') || self::can('schedules.edit');
    }

    public static function isCounterAgent(): bool
    {
        $admin = self::admin();

        return $admin !== null && (string) ($admin['role'] ?? '') === 'agent';
    }

    /**
     * The admins.id that every booking query must be restricted to, or null
     * when the signed-in staff member may see the whole company.
     *
     * An id here is an obligation to filter, not a hint. A counter agent may
     * only ever see the bookings they sold themselves — their own passengers,
     * their own customers, their own money — so any page reachable with
     * bookings.view has to honour this.
     */
    public static function bookingScopeAdminId(): ?int
    {
        return self::isCounterAgent() ? (int) (self::admin()['id'] ?? 0) : null;
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
     * Thin sugar for the 'schedules.manage' permission — the one that gates
     * the new Bus Ops Control Center actions (block/unblock a schedule,
     * override the departure time, cancel with reason). Callers should not
     * need to remember the exact permission string, and the isSuperadmin()
     * bypass in can() already grants it to every super-admin.
     */
    public static function canManageSchedules(): bool
    {
        return self::can('schedules.manage');
    }

    /**
     * May the signed-in staff member change configuration — settings rows,
     * health incident state, the AI knowledge base? Super-admins always; a
     * manager through 'settings.manage'. Everyone else may read those pages
     * but every POST on them is refused.
     */
    public static function canManageSettings(): bool
    {
        return self::isSuperadmin() || self::can('settings.manage');
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

        /* Forced password change (master prompt §20).
           staff.php issues a temporary password and flags the account
           must_change_pw. That flag was stored, carried into the session and
           shown as a badge in the staff list — but nothing ever acted on it,
           so a temporary password handed out over WhatsApp stayed valid for
           as long as the holder liked. Everything except the change form, the
           sign-out link and the API stays shut until it is cleared. */
        $file = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (!empty($_SESSION[ADMIN_SESSION_KEY]['must_change_pw'])
            && !$wantsJson
            && !in_array($file, ['change-password.php', 'logout.php'], true)) {
            Response::redirect('admin/change-password.php');
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

            /* Inside the admin panel the refusal is a real page — the
               panel's own chrome, the reason in two languages and a way
               back — instead of one bare English sentence with no link,
               which is what a scanner or an official saw right after
               signing in. The status stays 403 for every caller that
               checks it (tests/role-gates-test.php). */
            if (function_exists('admin_header') && function_exists('admin_footer')) {
                $home = match ((string) ($admin['role'] ?? '')) {
                    'agent'   => '/admin/agent.php',
                    'counter' => '/admin/bookings.php',
                    'scanner' => '/admin/scan.php',
                    'official' => '/admin/payments.php',
                    default   => '/admin/index.php',
                };
                admin_header('Not allowed', '');
                echo '<div class="panel" style="max-width:560px;margin:32px auto;text-align:center;padding:32px 24px">'
                   . '<div style="font-size:44px;line-height:1">🔒</div>'
                   . '<h2 style="margin:12px 0 6px">This page is not for your role</h2>'
                   . '<p class="muted" style="margin:0 0 6px">यो पेज तपाईंको भूमिकाले खोल्न मिल्दैन। / यह पेज आपकी भूमिका के लिए नहीं है।</p>'
                   . '<p class="muted" style="margin:0 0 18px;font-size:13px">Needs <code>' . Security::e($permission) . '</code>. Ask the office if you should have it.</p>'
                   . '<a class="btn" href="' . Security::e($home) . '">← Back to my home</a>'
                   . '</div>';
                admin_footer();
                exit;
            }

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
    public static function loginUser(string $phone, string $name = '', string $country = ''): array
    {
        $phone = normalisePhone($phone);

        // Map the login country ('NP'/'IN') to the stored dialing code so a
        // Nepali customer's number is not silently assumed to be Indian. India
        // and Nepal share 10-digit mobiles, so the country cannot be recovered
        // from the number alone — it must be captured here, at sign-in.
        $cc          = strtoupper(trim($country));
        $countryCode = $cc === 'NP' ? '977' : ($cc === 'IN' ? '91' : '');

        $user = Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]);

        if ($user === null) {
            $userId = Database::insert('users', [
                'phone'          => $phone,
                'full_name'      => $name !== '' ? Security::clean($name, 120) : null,
                'role'           => 'customer',
                'country_code'   => $countryCode !== '' ? $countryCode : '91',
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
            // The name typed at sign-in is the name that prints on the ticket,
            // so a corrected spelling replaces the one on file (4 Sep 2026).
            $cleanName = $name !== '' ? Security::clean($name, 120) : '';
            if ($cleanName !== '' && mb_strlen($cleanName) >= 2 && $cleanName !== (string) ($user['full_name'] ?? '')) {
                $update['full_name'] = $cleanName;
                $user['full_name']   = $cleanName;
            }
            if ($countryCode !== '') {
                $update['country_code'] = $countryCode;
            }

            Database::update('users', $update, 'id = :id', ['id' => $user['id']]);
        }

        if ($user === null) {
            throw new RuntimeException('User record could not be created.');
        }

        if ((int) $user['is_blocked'] === 1) {
            throw new RuntimeException('This account has been suspended. Please contact support.');
        }

        /* The app door into the customer vault. `users` is the account; the
           vault is what we KNOW about the person behind it, and it is keyed
           by phone so the same human is one gem whether they signed in here,
           messaged WhatsApp, or had a ticket typed for them at the counter.
           Never allowed to break a sign-in: upsert() swallows its own errors
           and returns '' rather than throwing. */
        try {
            require_once __DIR__ . '/gemvault.php';
            GemVault::upsert(
                (string) $user['phone'],
                (string) ($user['full_name'] ?? ''),
                $countryCode,
                'app',
                (int) $user['id']
            );
        } catch (Throwable $ignored) {
            // A vault row is worth less than a customer being able to log in.
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
            // Customers have no separate login page — signing in is the OTP
            // panel inside the app at #/my. The old target (customer/login.php)
            // does not exist and would have 404'd the first caller.
            Response::redirect('#/my');
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
