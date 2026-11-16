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
    /** The only screens a view-only account may open (5 Oct 2026). */
    public const VIEWER_PAGES = [
        'index.php', 'analytics.php', 'accounting.php', 'agent-ranking.php', 'feedback.php', 'counters.php',
        'change-password.php', 'logout.php', 'pending-count.php', 'live-status.php',
        'home.php',   // 30 Oct 2026: the one home page every sign-in lands on (admin/home.php)
    ];

    /* =================================================================
     *  One door + one home (30 Oct 2026, owner: "login system dherai
     *  messy chha — number diyo bhane number + WhatsApp OTP, natra
     *  'hi' pathaune, natra simple agent ID ra simple password. Euta
     *  dashboard — agent, admin, staff sabai euta dashboard bhitra,
     *  khali kholda pharak"). Switch: settings row login_one_door_on
     *  (code default ON). 0 brings back every old door and landing.
     * ================================================================= */

    /** Is the one staff door (admin/login.php) + the one home (admin/home.php) switched on? */
    public static function oneDoorOn(): bool
    {
        try {
            return Settings::getBool('login_one_door_on', true);
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Where a signed-in staff member's day starts. One door on: everybody lands on
     * /admin/home.php (big tiles by role). Off: the old per-role map, exactly as before.
     */
    public static function homeUrl(?array $admin = null): string
    {
        if (self::oneDoorOn()) {
            return '/admin/home.php';
        }
        $admin ??= self::admin();
        return match ((string) ($admin['role'] ?? '')) {
            'agent'    => '/admin/agent.php',
            'counter'  => '/admin/bookings.php',
            'scanner'  => '/admin/scan.php',
            'official' => '/admin/payments.php',
            default    => '/admin/index.php',
        };
    }

    /**
     * 30 Oct 2026 (one door): what was typed in "Mobile number or ID" when it is not a mobile —
     * an agent code (SHG-0029, SHG0029, 0029, 29), a staff number (SHG-S-0003), an email, or a
     * plain username — turned into the username Auth::adminLogin() checks. Never an error and
     * never a hint: an unknown ID is passed through and fails with the same generic message.
     */
    public static function resolveLoginId(string $raw): string
    {
        $id = trim(Security::clean($raw, 120));
        if ($id === '') {
            return '';
        }
        try {
            if (preg_match('/^(?:SHG[\s\-]*)?0*(\d{1,5})$/i', $id, $m) === 1 && (int) $m[1] > 0) {
                require_once __DIR__ . '/agentwallet.php';
                $aid = AgentWallet::adminForAgentCode((int) $m[1]);
                if ($aid !== null) {
                    $u = (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => $aid], '');
                    if ($u !== '') {
                        return $u;
                    }
                }
            } elseif (preg_match('/^SHG[\s\-]*S[\s\-]*0*(\d{1,6})$/i', $id, $m) === 1 && (int) $m[1] > 0) {
                $u = (string) Database::scalar(
                    'SELECT a.username FROM ap_members m JOIN admins a ON a.id = m.admin_id WHERE m.staff_no = :n LIMIT 1',
                    ['n' => (int) $m[1]], '');
                if ($u !== '') {
                    return $u;
                }
            } elseif (str_contains($id, '@')) {
                $e = strtolower(Security::email($id));
                if ($e !== '') {
                    $u = (string) Database::scalar('SELECT username FROM admins WHERE LOWER(email) = :e ORDER BY id LIMIT 1', ['e' => $e], '');
                    if ($u !== '') {
                        return $u;
                    }
                }
            }
        } catch (Throwable $e) {
            // a missing ap_members table or a settings hiccup: fall through to the plain username
        }
        return Security::clean($id, 60);
    }

    private const ROLE_PERMISSIONS = [
        'manager' => [
            'dashboard.view', 'bookings.view', 'bookings.edit', 'bookings.cancel',
            // 15 Oct 2026 (owner: Quick Edit): a manager may correct WHO sold a
            // ticket (Auth::mayReassignSeller) - the commission follows the seller,
            // cash never moves, every change is audit-logged. Was super-admin only.
            'bookings.reassign',
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
            // reports.export (4 Oct 2026): admin/export.php now asks for it; a
            // manager could always download the register and still can.
            'reports.view', 'reports.export', 'liveops.view', 'liveops.edit',
            'messages.view', 'support.view', 'support.reply',
            // 5 Oct 2026: launch customer campaigns (admin/campaigns.php).
            'marketing.send',
            'tickets.scan', 'waitlist.view',
            // 9 Oct 2026: the office side of Office chat (admin/staff-chat.php) -
            // answers every agent / counter thread. Field roles (agent, counter)
            // use bookings.view and only ever see their own threads.
            'staffmsg.office',
        ],
        'accountant' => [
            'dashboard.view', 'bookings.view',
            'payments.view', 'payments.verify', 'payments.reject',
            'refunds.view', 'refunds.process',
            'commissions.view', 'commissions.pay',
            'reports.view', 'reports.export',
            // settlement / payout threads land with accounts (9 Oct 2026)
            'staffmsg.office',
        ],
        // Operations admin (5 Oct 2026, owner's Point 10): runs the day - tickets,
        // seats, buses, routes, the agent register and customers. Deliberately
        // NO commissions.pay (cannot settle or pay an agent), NO reports.view
        // (company money, accounting, payroll), NO refunds.process and NO
        // settings.manage. Sees an agent's figures (commissions.view) because
        // the agent register is part of the job; cannot change them.
        'operations' => [
            'dashboard.view', 'bookings.view', 'bookings.edit', 'bookings.cancel',
            'payments.view', 'payments.verify', 'payments.reject',
            'refunds.view', 'commissions.view',
            'routes.view', 'routes.edit', 'schedules.view', 'schedules.edit', 'schedules.manage',
            'buses.view', 'buses.edit', 'drivers.view', 'drivers.edit',
            'staff.manage', 'customers.view', 'coupons.view',
            'liveops.view', 'liveops.edit', 'messages.view', 'support.view', 'support.reply',
            'tickets.scan', 'waitlist.view',
            'staffmsg.office',   // Office chat, office side (9 Oct 2026)
        ],
        // Viewer (5 Oct 2026): a partner or investor who may LOOK. The dashboard,
        // analytics and the money reports - and nothing that names a passenger
        // (no bookings.view / customers.view). Read-only is enforced, not just
        // hidden: requireAdmin() refuses every non-GET request from this role
        // and every page outside VIEWER_PAGES (message log, audit log and the
        // settings screens gate on dashboard.view too, and those are not for a guest).
        'viewer' => [
            'dashboard.view', 'reports.view',
        ],
        'support' => [
            'dashboard.view', 'bookings.view',
            // Read-only on the refund desk: support answers "where is my
            // money" all day, but only accounts/manager may approve.
            'refunds.view',
            'customers.view', 'messages.view',
            'support.view', 'support.reply',
            'waitlist.view', 'tickets.scan',
            'staffmsg.office',   // Office chat, office side (9 Oct 2026)
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
            // 23 Oct 2026 (owner: "agent haru lai scanner ni rakhdeu"): the boarding scanner. admin/scan.php
            // lets an agent check a SIGNED QR, or a ticket they sold themselves - never a typed stranger's PNR.
            'tickets.scan',
        ],
    ];

    /* =================================================================
     *  Permission matrix (29 Oct 2026, owner's management-system prompt,
     *  Phase 1 "Role × Feature checkbox grid"). ROLE_PERMISSIONS above stays
     *  the default; the super-admin may tick a box on or off per role from
     *  admin/permissions.php, saved in the settings row
     *  `role_permissions_override` as {role: {permission: true|false}}.
     *  can() reads the merged set. Untouched boxes follow the code, so a
     *  permission added to a role in a later release still reaches it.
     * ================================================================= */

    /**
     * Every permission the code checks, grouped by module, with what it opens.
     * The matrix shows exactly these; an override for anything else is ignored.
     */
    public const PERMISSION_CATALOG = [
        'Dashboard & Reports' => [
            'dashboard.view'  => 'Dashboard, analytics, settings screens (read)',
            'reports.view'    => 'Company money: accounting, payroll, counters',
            'reports.export'  => 'Download registers / Excel exports',
        ],
        'Bookings' => [
            'bookings.view'       => 'Ticket register, sell from the seat map',
            'bookings.edit'       => 'Change passenger, seat, pickup on any ticket',
            'bookings.cancel'     => 'Cancel ANY ticket',
            'bookings.cancel_own' => 'Cancel only tickets they sold',
            'bookings.reassign'   => 'Change who sold a ticket (commission follows)',
            'tickets.scan'        => 'Boarding QR scanner',
            'waitlist.view'       => 'Waitlist',
        ],
        'Payments & Refunds' => [
            'payments.view'   => 'Payments queue',
            'payments.verify' => 'Verify a payment',
            'payments.reject' => 'Reject a payment',
            'refunds.view'    => 'Refund desk (read)',
            'refunds.process' => 'Approve / pay a refund',
        ],
        'Commissions & Wallet' => [
            'commissions.view' => 'Agent ledgers, sales, settlements (read)',
            'commissions.pay'  => 'Settle / pay an agent',
        ],
        'Routes, Schedules & Fleet' => [
            'routes.view'      => 'Routes & stops (read)',
            'routes.edit'      => 'Edit routes, stops, fares',
            'schedules.view'   => 'Trips, seat map, calendar (read)',
            'schedules.edit'   => 'Edit a schedule',
            'schedules.manage' => 'Block / cancel / retime a bus, routine, GPS',
            'buses.view'       => 'Fleet (read)',
            'buses.edit'       => 'Edit buses',
            'drivers.view'     => 'Drivers & crew (read)',
            'drivers.edit'     => 'Edit drivers & crew',
            'liveops.view'     => 'Live operations (read)',
            'liveops.edit'     => 'Live operations (act)',
        ],
        'People & Customers' => [
            'staff.manage'   => 'Staff, agents, logins, team approvals',
            'customers.view' => 'Customer CRM, agents register, invites',
            'coupons.view'   => 'Offers & coupons (read)',
            'coupons.edit'   => 'Create / edit offers',
        ],
        'WhatsApp, Support & Marketing' => [
            'messages.view'   => 'Message logs',
            'support.view'    => 'Support inbox (read)',
            'support.reply'   => 'Reply to customers',
            'marketing.send'  => 'Launch customer campaigns',
            'staffmsg.office' => 'Office chat with agents / counters (office side)',
        ],
        'Settings' => [
            'settings.manage' => 'Change switches, fares, AI knowledge',
        ],
    ];

    /** Permissions that move money or open other people's data — shown in red. */
    public const SENSITIVE_PERMISSIONS = [
        'bookings.cancel', 'bookings.reassign', 'refunds.process', 'commissions.pay',
        'staff.manage', 'settings.manage', 'reports.export', 'reports.view', 'marketing.send',
    ];

    /** Roles the matrix may change. superadmin is everything, always. */
    public const MATRIX_ROLES = ['manager', 'accountant', 'operations', 'support', 'official', 'counter', 'agent', 'scanner', 'viewer'];

    /** @return array<string, string> permission => label, flat */
    public static function permissionList(): array
    {
        $out = [];
        foreach (self::PERMISSION_CATALOG as $perms) {
            $out += $perms;
        }
        return $out;
    }

    /** The code's own list for a role (before any override). @return array<int,string> */
    public static function defaultRolePermissions(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * What a role may do right now: the code's list with the super-admin's
     * ticks applied. A broken setting falls back to the code's list.
     *
     * @return array<int,string>
     */
    public static function rolePermissions(string $role): array
    {
        $perms = self::ROLE_PERMISSIONS[$role] ?? [];
        if (!in_array($role, self::MATRIX_ROLES, true)) {
            return $perms;
        }
        $over = Settings::getArray('role_permissions_override', []);
        if (!isset($over[$role]) || !is_array($over[$role])) {
            return $perms;
        }
        $known = self::permissionList();
        foreach ($over[$role] as $perm => $on) {
            $perm = (string) $perm;
            if (!isset($known[$perm])) {
                continue;
            }
            if (filter_var($on, FILTER_VALIDATE_BOOLEAN)) {
                if (!in_array($perm, $perms, true)) {
                    $perms[] = $perm;
                }
            } else {
                $perms = array_values(array_diff($perms, [$perm]));
            }
        }
        return $perms;
    }

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
     *  Quick Edit field rights (15 Oct 2026, owner: "agent / counter le
     *  kun kun kura afai milauna pauni - admin le rojne")
     * ================================================================= */

    /**
     * Default per-role field rules; settings `ticket_edit_rules` (json, Admin
     * -> Settings -> Booking rules) overrides them key by key. Only the two
     * FIELD roles are listed: every office role keeps its permission
     * (bookings.edit / payments.verify) exactly as before.
     */
    /* 31 Oct 2026 (owner: "aafule kateko ticket ko seat haru shift garna milos"): an agent moves the
       seat and the pickup of ITS OWN tickets too (mayEditField pins it to sold_by = me, the seat map
       Transfer already allowed it) — Seats::transferSeat checks the seat is free and redraws the ticket. */
    public const TICKET_EDIT_DEFAULTS = [
        'agent'   => ['name' => true, 'phone' => true, 'pickup' => true, 'seat' => true],
        'counter' => ['name' => true, 'phone' => true, 'pickup' => true, 'seat' => true, 'payment' => true],
    ];

    /** Fields the rules may name (anything else is ignored). */
    public const TICKET_EDIT_FIELDS = ['name', 'phone', 'email', 'pickup', 'gender', 'age', 'id', 'seat', 'payment', 'remark'];

    /**
     * The live field rules: defaults, overridden per role and per field by
     * the setting. A broken setting falls back to the defaults.
     *
     * @return array{agent: array<string,bool>, counter: array<string,bool>}
     */
    public static function ticketEditRules(): array
    {
        $out = self::TICKET_EDIT_DEFAULTS;
        $cfg = Settings::getArray('ticket_edit_rules', []);
        foreach (['agent', 'counter'] as $role) {
            if (!isset($cfg[$role]) || !is_array($cfg[$role])) {
                continue;
            }
            foreach ($cfg[$role] as $field => $on) {
                if (in_array((string) $field, self::TICKET_EDIT_FIELDS, true)) {
                    $out[$role][(string) $field] = filter_var($on, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        return $out;
    }

    /**
     * May the signed-in staff member change WHO sold a ticket (and with it the
     * commission)? Super-admins always; anyone granted bookings.reassign
     * (managers by default since 15 Oct 2026). The one rule for booking-view,
     * Assign Agents and the seat map.
     */
    public static function mayReassignSeller(): bool
    {
        return self::isSuperadmin() || self::can('bookings.reassign');
    }

    /**
     * May the signed-in staff member correct one field of THIS ticket
     * directly (Quick Edit)? Fields: name, phone, email, pickup, gender, age,
     * id, seat, payment, remark, plus fare (super-admin only) and agent
     * (mayReassignSeller). Otherwise the page offers "Request correction".
     *
     *   super-admin      everything
     *   counter agent    only a field the rules switch ON, only on a ticket
     *                    they sold, only while it is pending / confirmed
     *   counter (desk)   bookings.edit (payments.verify for payment), unless
     *                    the rules switch that field OFF
     *   every other role its permission, as before
     *
     * The trip's edit window (TripStatus) is checked by the handlers.
     *
     * @param array<string,mixed> $booking needs sold_by_admin_id, status
     */
    public static function mayEditField(array $booking, string $field): bool
    {
        $admin = self::admin();
        if ($admin === null) {
            return false;
        }
        if (self::isSuperadmin()) {
            return true;
        }
        if ($field === 'fare') {
            return false;
        }
        if ($field === 'agent') {
            return self::mayReassignSeller();
        }
        if (!in_array($field, self::TICKET_EDIT_FIELDS, true)) {
            return false;
        }

        $role  = (string) ($admin['role'] ?? '');
        $rules = self::ticketEditRules();
        if ($role === 'agent') {
            /* Never payment for an agent (review fix): a cash -> UPI switch
               deletes the cash_due row, i.e. the agent would erase the cash
               they owe. They use Request correction instead. */
            if ($field === 'payment') {
                return false;
            }
            $me = (int) ($admin['id'] ?? 0);
            return ($rules['agent'][$field] ?? false) === true
                && $me > 0 && (int) ($booking['sold_by_admin_id'] ?? 0) === $me
                && in_array((string) ($booking['status'] ?? ''), ['pending', 'confirmed'], true);
        }

        $base = $field === 'payment' ? self::can('payments.verify') : self::can('bookings.edit');
        if ($role === 'counter') {
            return $base && ($rules['counter'][$field] ?? true) === true;
        }
        return $base;
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
            password_verify($password, '$2y$12$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');   // Security::HASH_COST
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
            // 31 Oct 2026 (review): a lock that has run out starts the count again — one wrong try
            // every 15 minutes used to re-lock the account for good (failed_logins never went down).
            $failed = self::failedSoFar($admin) + 1;

            $update = ['failed_logins' => $failed];
            if ($failed >= MAX_LOGIN_ATTEMPTS) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + (LOGIN_LOCKOUT_MIN * 60));
            } elseif (!empty($admin['locked_until'])) {
                $update['locked_until'] = null;   // the old lock is over
            }

            Database::update('admins', $update, 'id = :id', ['id' => $admin['id']]);
            Logger::warning('Admin login failed', ['username' => $username, 'ip' => $ip, 'attempt' => $failed]);
            LoginLog::record($username, 'bad_password', (int) $admin['id']);

            return $genericFailure;
        }

        /* 16 Nov 2026: an older, cheaper hash (cost 10 / 11) moves to Security::HASH_COST now that the
           right password is in hand. The 2FA hold and the session below are stamped with the hash now
           on file; the account's OTHER open sessions end on their 30 s re-check, as after a password
           change (once per account, only on this password door). */
        $admin['password_hash'] = self::upgradePasswordHash((int) $admin['id'], $password, (string) $admin['password_hash']);

        /* ---- Optional second factor (Point 10) -----------------------
           OPT-IN per admin and DEFAULT OFF, so for every account that has
           not turned it on this block does nothing and the login flow is
           byte-for-byte what it was before. When it IS on we issue a code to
           the admin's registered phone and hold the session at a pending step
           — the password alone no longer completes the sign-in.

           Availability guarantee (owner's hard constraint — no lockout): 2FA
           can only ever ADD a factor, never remove the password path's
           availability. If there is no phone on file we log it and complete
           with the password alone rather than stranding the admin. (31 Oct
           2026: a code REFUSED by the resend limits no longer does that — see
           below; the code step is held instead.) */
        $held = self::begin2fa($admin, $ip);
        if ($held !== null) {
            return $held;
        }

        // 22 Oct 2026 (review #11 re-attack): stamped with the password checked above, never re-read.
        // 16 Nov 2026 (P1-5b): 'devices' = the door passed but the session is held at the device-limit
        // step (deviceHold); 'ok' stays true so every caller moves on, and admin/login.php shows the step.
        if (!self::finishAdminSession($admin, $ip, false, self::pwStamp((string) $admin['password_hash']))) {
            return ['ok' => true, 'devices' => true];
        }

        return ['ok' => true];
    }

    /**
     * The office second factor, lifted out of adminLogin() on 15 Nov 2026 so the
     * code + PIN / pattern door (includes/codelogin.php) asks a 2FA account for the
     * same WhatsApp code after its PIN, exactly as after its password. null = this
     * account has no 2FA (or no phone on file: the old no-lockout rule) — the
     * caller opens the session itself. Otherwise the result to return: the code
     * step is held in $_SESSION['admin_2fa_pending'] and NOTHING is open yet.
     *
     * @param array<string, mixed> $admin admins row with id, phone, password_hash
     * @return array<string, mixed>|null
     */
    public static function begin2fa(array $admin, string $ip): ?array
    {
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
                    // 22 Oct 2026 (review #11 re-attack): 'pw' = the stamp of the
                    // password checked HERE. The code step opens the session only
                    // while that password is still the one on file, and stamps
                    // the session with it — a reset during the 10 minutes used to
                    // hand the old password's sign-in the NEW password's stamp.
                    $_SESSION['admin_2fa_pending'] = [
                        'id' => (int) $admin['id'], 'ip' => $ip, 'at' => time(),
                        'pw' => self::pwStamp((string) $admin['password_hash']),
                    ];
                    Logger::audit('admin.2fa.sent', 'admin', (string) $admin['id'], null, null,
                        'Second-factor code issued at login');
                    return ['ok' => false, 'twofa' => true, 'resend' => OTP_RESEND_SECONDS];
                }
                /* 31 Oct 2026 (review): issueOtp() refuses only on its own limits (1 a minute, 6-8 an
                   hour for this account). That used to "degrade to password-only", so typing the
                   password twice within a minute signed in WITHOUT the code. A refused code is never a
                   reason to skip it: hold the code step (the code already sent still works; Resend
                   after the minute), and only an account with no phone on file signs in with the
                   password alone. */
                $_SESSION['admin_2fa_pending'] = [
                    'id' => (int) $admin['id'], 'ip' => $ip, 'at' => time(),
                    'pw' => self::pwStamp((string) $admin['password_hash']),
                ];
                Logger::audit('admin.2fa.held', 'admin', (string) $admin['id'], null, null,
                    'Second-factor code not re-sent (' . (string) ($otp['error'] ?? 'limit') . ') — held at the code step');
                return ['ok' => false, 'twofa' => true, 'wait' => true, 'resend' => OTP_RESEND_SECONDS,
                        'error' => (string) ($otp['error'] ?? 'Please wait a moment before requesting another code.')];
            } else {
                Logger::audit('admin.2fa.no_phone', 'admin', (string) $admin['id'], null, null,
                    '2FA enabled but no phone on file — signed in with password only');
            }
        }
        return null;
    }

    /**
     * 15 Nov 2026 (code-first login): open the session after a PIN / pattern the
     * code door checked. Same refusals as openAdminWithoutPassword() (switched off,
     * locked, no password on file); a 2FA account is NOT refused any more but held
     * at the WhatsApp code step (begin2fa), so the PIN is its first factor and the
     * office MFA still applies. Returns ['ok'=>true] or the 2FA hold.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     * @throws RuntimeException with a user-safe message
     */
    public static function openAfterQuick(array $admin, string $how): array
    {
        if ((int) ($admin['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('This account is switched off.');
        }
        if ((string) ($admin['password_hash'] ?? '') === '') {
            throw new RuntimeException('This account has no password yet - ask the office to set one.');
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            throw new RuntimeException('This account is locked for a few minutes.');
        }
        $ip = Security::clientIp();
        $held = self::begin2fa($admin, $ip);
        if ($held !== null) {
            $_SESSION['admin_2fa_pending']['via'] = $how;   // 16 Nov 2026: the session the code opens says PIN / pattern, not password
            Logger::audit('admin.login.' . $how . '_2fa', 'admin', (string) $admin['id'], null, null, 'PIN / pattern accepted; WhatsApp 2FA code step held');
            return $held;
        }
        // 16 Nov 2026: via = the door ('pin' / 'pattern' / 'whatsapp_hi' / 'whatsapp_otp'); a PIN / pattern
        // sign-in under a forced change is NOT via_otp, so it still asks for the current password (viaOtpFor).
        if (!self::finishAdminSession($admin, $ip, self::viaOtpFor($how, $admin), self::pwStamp((string) $admin['password_hash']), $how)) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }
        Logger::audit('admin.login.' . $how, 'admin', (string) $admin['id'], null, null, 'Signed in with code + ' . $how);
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
            'SELECT id, username, full_name, role, permissions, is_active, must_change_pw, password_hash
               FROM admins WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $adminId]
        );
        unset($_SESSION['admin_2fa_pending']);
        if ($admin === null) {
            return ['ok' => false, 'error' => 'That account is no longer available.'];
        }

        /* 22 Oct 2026 (review #11 re-attack): the password typed at step 1
           must still be the one on file. A marker written before this change
           carries no 'pw' and goes on as it did (it expires in 10 minutes). */
        $now = self::pwStamp((string) $admin['password_hash']);
        $pw  = isset($pending['pw']) ? (string) $pending['pw'] : $now;
        if (!hash_equals($now, $pw)) {
            Logger::audit('admin.2fa.stale_password', 'admin', (string) $adminId, null, null,
                'Second-factor code refused: the password was changed after step 1');
            return ['ok' => false, 'error' => 'This account\'s password was changed — please sign in again with the new password.'];
        }

        if (!self::finishAdminSession($admin, $ip, false, $pw, (string) ($pending['via'] ?? ''))) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }
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
     * 31 Oct 2026 (review): the wrong-password count to add to — 0 once an old lock has run out, so
     * one more wrong try after the 15 minutes does not lock the account again at once.
     *
     * @param array<string, mixed> $row admins / staff_applications row (failed_logins, locked_until)
     */
    public static function failedSoFar(array $row): int
    {
        $until = (string) ($row['locked_until'] ?? '');
        if ($until !== '' && (strtotime($until) ?: 0) <= time()) {
            return 0;
        }
        return (int) ($row['failed_logins'] ?? 0);
    }

    /**
     * 31 Oct 2026 (review): every half-done sign-in step a browser can hold — the one door's state,
     * a "which ID?" list (WhatsApp code or password), a proven password reset and an office 2FA
     * step. Cleared on every sign-in, sign-out, "Start again" and new door start, so the next
     * person on a shared counter PC can never finish someone else's step.
     */
    public const DOOR_KEYS = ['one_door', 'ap_login_pick', 'team_pick', 'team_reset', 'admin_2fa_pending', 'code_login', 'admin_device_pending'];

    public static function clearDoorState(): void
    {
        foreach (self::DOOR_KEYS as $k) {
            /* 16 Nov 2026 (P1-5b): the request that just HELD a sign-in at the device limit keeps the
               hold — the doors tidy up after a "successful" sign-in (OneDoor::clear(), TeamPortal) and
               would otherwise drop it before the page can show the step. Any later request clears it. */
            if ($k === self::DEVICE_HOLD_KEY && self::$heldThisRequest) {
                continue;
            }
            unset($_SESSION[$k]);
        }
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
     * @param string|null $pwStamp 22 Oct 2026 (review #11 re-attack): the
     *                     pwStamp() of the password hash the door CHECKED (or,
     *                     for a reset, just wrote). Null only for a door that
     *                     checks no password (agent code + mobile OTP): that
     *                     session takes the stamp of the row as it is now.
     * @param bool $deviceChecked 16 Nov 2026 (P1-5b): true only from deviceForce(),
     *                     which has just made room — the device limit is not asked again.
     * @return bool true = the session is open; false = held at the device-limit step
     *                     (deviceHold(); nothing is open and nothing about the account changed)
     */
    private static function finishAdminSession(array $admin, string $ip, bool $viaOtp = false, ?string $pwStamp = null, string $via = '', bool $deviceChecked = false): bool
    {
        // 16 Nov 2026: the door that opened this session ('password', 'otp', 'pin', 'pattern',
        // 'whatsapp_hi', 'passkey' ...) — shown in Sessions (live + history), never a permission input.
        $via = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($via)) ?? '', 0, 20);
        if ($via === '') {
            $via = $viaOtp ? 'otp' : 'password';
        }
        self::$heldThisRequest = false;   // a new sign-in: an older hold goes with the rest
        self::clearDoorState();   // 31 Oct 2026 (review): no leftover pick / reset / 2FA step outlives a sign-in

        /* 16 Nov 2026 (master v2.0, P1-5b): every door ends here, so the per-role device limit is
           asked here, after every factor the door wanted (password, PIN, 2FA code ...). Over the
           limit the sign-in is HELD, like admin_2fa_pending; admin/login.php shows the other
           devices and "sign the other device out and continue" (deviceForce) or Cancel. Limit 0
           (the default for every role) = exactly as before. */
        if (!$deviceChecked && self::holdForDeviceLimit($admin, $viaOtp, $pwStamp, $via)) {
            return false;
        }

        // Success — clear counters and start a fresh session id.
        Database::update('admins', [
            'failed_logins' => 0,
            'locked_until'  => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ], 'id = :id', ['id' => $admin['id']]);

        session_regenerate_id(true);

        /* The session carries the password the door checked, never the row
           re-read afterwards: a reset landing between the check and this
           read used to give a sign-in with the OLD password the NEW stamp,
           and admin() then never signed it out. The row is still read, as
           late as possible: if it has already moved on, the first re-check
           runs on the very next request (checked_at 0) instead of 30 s
           later; a reset after this read is caught by that 30 s re-check. */
        $current = self::adminPwStamp((int) $admin['id']);
        $stamp   = $pwStamp ?? $current;

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
            'via'            => $via,
            'logged_in_at'   => time(),
            'last_seen'      => time(),
            // Stamped only by a real sign-in: admin() re-reads the admins row
            // against this clock, so a session built by hand in a test (no
            // stamp) is left exactly as the test wrote it.
            'checked_at'     => hash_equals($current, $stamp) ? time() : 0,
            // 22 Oct 2026 (review #11, office part): the password this session
            // was opened with (re-attack: the one the door checked, $pwStamp).
            'pw'             => $stamp,
        ];

        // Record the attempt and note whether this browser is new for this
        // account. Runs before any output, since it may mint the device
        // cookie. A failure in here is swallowed by LoginLog — logging must
        // never be the reason someone cannot sign in.
        $newDevice = LoginLog::record((string) $admin['username'], 'success', (int) $admin['id'], $via);
        $_SESSION[ADMIN_SESSION_KEY]['new_device'] = $newDevice;

        // 29 Oct 2026: one row per signed-in browser, so the office can see
        // it and end it (admin/sessions.php). After LoginLog, which mints the
        // device cookie the row records. '' (no registry) changes nothing.
        $_SESSION[ADMIN_SESSION_KEY]['sk'] = AdminSessions::open((int) $admin['id'], $via);

        Logger::audit('admin.login', 'admin', (string) $admin['id'], null, null,
            'Signed in from ' . $ip . ($via === 'password' ? '' : ' (' . ($via === 'otp' ? 'agent OTP' : str_replace('_', ' ', $via)) . ')'));

        if ($newDevice) {
            Logger::audit('admin.login.newdevice', 'admin', (string) $admin['id'], null,
                ['device' => LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))],
                'First sign-in from this device');
        }
        return true;
    }

    /* =================================================================
     *  16 Nov 2026 (master v2.0, P1-5b + P1-5c): per-role device limit.
     *  Setting device_limit_<role> (AdminSessions::deviceLimit; 0 = no
     *  limit, the default). A door that would open a session on one device
     *  too many is held in $_SESSION['admin_device_pending'] (a DOOR_KEY, so
     *  "start again" / sign-out drop it). The hold carries only what the
     *  door had proven — the account id, its password stamp, how — and the
     *  list of the OTHER devices offered (kept server-side; the form posts
     *  only 'd1', 'd2' ...). Nothing in the hold is a secret of another
     *  device: session tokens are never in it.
     * ================================================================= */

    public const DEVICE_HOLD_KEY = 'admin_device_pending';
    public const DEVICE_HOLD_TTL = 300;   // 5 minutes to choose; then sign in again

    /** Set when THIS request held a sign-in (clearDoorState then keeps the hold; see there). */
    private static bool $heldThisRequest = false;

    /**
     * @param array<string, mixed> $admin
     * @return bool true = held (the caller opens nothing)
     */
    private static function holdForDeviceLimit(array $admin, bool $viaOtp, ?string $pwStamp, string $via): bool
    {
        try {
            $id    = (int) ($admin['id'] ?? 0);
            $limit = AdminSessions::deviceLimit((string) ($admin['role'] ?? ''));
            if ($id <= 0 || $limit <= 0) {
                return false;
            }
            $others = AdminSessions::liveDevices($id, LoginLog::deviceHash());
            if (count($others) < $limit) {
                return false;
            }
            $_SESSION[self::DEVICE_HOLD_KEY] = [
                'id' => $id, 'at' => time(), 'otp' => $viaOtp, 'pw' => $pwStamp, 'via' => $via,
                'limit' => $limit, 'offer' => self::deviceOffer($others),
            ];
            self::$heldThisRequest = true;
            Logger::audit('admin.login.device_limit', 'admin', (string) $id, null,
                ['limit' => $limit, 'other_devices' => count($others), 'via' => $via,
                 'device' => LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))],
                'Sign-in held: the account is already on its device limit');
            return true;
        } catch (Throwable $e) {
            // never the reason a sign-in fails: no limit check = how it always was
            unset($_SESSION[self::DEVICE_HOLD_KEY]);
            return false;
        }
    }

    /**
     * The other devices as the step offers them: 'd1' => device, oldest use first
     * (the stale one is the likely one to sign out, so it is pre-ticked).
     *
     * @param list<array{hash: string, label: string, last_seen: string, ids: list<int>}> $devices
     * @return array<string, array{hash: string, label: string, last_seen: string, ids: list<int>}>
     */
    private static function deviceOffer(array $devices): array
    {
        usort($devices, static fn(array $a, array $b): int => strcmp((string) $a['last_seen'], (string) $b['last_seen']));
        $out = [];
        foreach (array_values($devices) as $i => $d) {
            $out['d' . ($i + 1)] = [
                'hash'      => (string) $d['hash'],
                'label'     => mb_substr((string) $d['label'] !== '' ? (string) $d['label'] : 'Unknown device', 0, 120),
                'last_seen' => (string) $d['last_seen'],
                'ids'       => array_map('intval', (array) $d['ids']),
            ];
        }
        return $out;
    }

    /**
     * The sign-in this browser has held at the device-limit step, or null.
     *
     * @return array{id: int, at: int, otp: bool, pw: ?string, via: string, limit: int,
     *               offer: array<string, array{hash: string, label: string, last_seen: string, ids: list<int>}>}|null
     */
    public static function deviceHold(): ?array
    {
        $p = $_SESSION[self::DEVICE_HOLD_KEY] ?? null;
        if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0) {
            return null;
        }
        if ((int) ($p['at'] ?? 0) < time() - self::DEVICE_HOLD_TTL) {
            unset($_SESSION[self::DEVICE_HOLD_KEY]);
            return null;
        }
        return $p;
    }

    /** "Cancel" on the device step: nothing opens, the other devices stay signed in. */
    public static function deviceCancel(): void
    {
        $p = self::deviceHold();
        unset($_SESSION[self::DEVICE_HOLD_KEY]);
        if ($p !== null) {
            Logger::audit('admin.login.device_cancel', 'admin', (string) (int) $p['id'], null, null,
                'Device limit: sign-in cancelled — nothing opened, the other devices stay signed in');
        }
    }

    /**
     * "अर्को यन्त्र बन्द गरी जारी राख्नुहोस् · Sign the other device out and continue": end every
     * session of the device the person ticked (one of the offer keys), audit admin.login.force, and
     * open the held session. The account is re-read and must still be active with the password the
     * door checked (as adminLogin2faVerify does). Still over the limit (the limit was lowered, or a
     * device signed in meanwhile) -> the step again with what is left.
     *
     * @return array{ok: bool, error?: string, expired?: bool}
     */
    public static function deviceForce(string $key): array
    {
        $p = self::deviceHold();
        if ($p === null) {
            return ['ok' => false, 'expired' => true, 'error' => 'समय सकियो — फेरि लगइन गर्नुहोस् · That took too long, please sign in again.'];
        }
        $dev = ((array) ($p['offer'] ?? []))[$key] ?? null;
        if (!is_array($dev)) {
            return ['ok' => false, 'error' => 'बन्द गर्ने यन्त्र छान्नुहोस् · Choose the device to sign out.'];
        }
        $id = (int) $p['id'];
        $admin = Database::fetch(
            'SELECT id, username, full_name, role, permissions, is_active, must_change_pw, password_hash
               FROM admins WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => $id]
        );
        $pw = isset($p['pw']) && is_string($p['pw']) ? $p['pw'] : null;
        if ($admin === null || ($pw !== null && !hash_equals(self::pwStamp((string) $admin['password_hash']), $pw))) {
            unset($_SESSION[self::DEVICE_HOLD_KEY]);
            Logger::audit('admin.login.device_stale', 'admin', (string) $id, null, null,
                'Device limit: held sign-in dropped (account switched off or password changed meanwhile)');
            return ['ok' => false, 'expired' => true, 'error' => 'फेरि लगइन गर्नुहोस् · Please sign in again.'];
        }

        $reason = 'signed out from another device (device limit)';
        $ended  = 0;
        if ((string) $dev['hash'] !== '') {
            $ended = AdminSessions::endDevice($id, (string) $dev['hash'], $id, $reason);
        } else {
            foreach ((array) $dev['ids'] as $sid) {   // ids came from this account's own live rows (deviceOffer)
                $ended += AdminSessions::end((int) $sid, $id, $reason) ? 1 : 0;
            }
        }
        $limit = AdminSessions::deviceLimit((string) $admin['role']);
        Logger::audit('admin.login.force', 'admin', (string) $id,
            ['device' => (string) $dev['label'], 'last_seen' => (string) $dev['last_seen'], 'sessions' => count((array) $dev['ids'])],
            ['device' => LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'sessions_ended' => $ended, 'limit' => $limit],
            'Device limit: signed the other device out and continued');

        $left = $limit > 0 ? AdminSessions::liveDevices($id, LoginLog::deviceHash()) : [];
        if ($limit > 0 && count($left) >= $limit) {
            $_SESSION[self::DEVICE_HOLD_KEY]['offer'] = self::deviceOffer($left);
            $_SESSION[self::DEVICE_HOLD_KEY]['limit'] = $limit;
            return ['ok' => false, 'error' => 'अझै ' . count($left) . ' यन्त्रमा खुला छ — अर्को एउटा पनि बन्द गर्नुहोस् · Still signed in on '
                . count($left) . ' other device(s): sign out one more.'];
        }
        $open = self::finishAdminSession($admin, Security::clientIp(), !empty($p['otp']), $pw, (string) ($p['via'] ?? ''), true);
        return ['ok' => $open];
    }

    /**
     * 22 Oct 2026 (review #11, office part): a short stamp of an account's
     * password hash. Every office session carries the stamp of the password
     * it was opened with ('pw'); admin() compares it with the admins row on
     * its 30-second re-check, so a changed or reset password ends every OTHER
     * session of that account. Keyed with APP_KEY, so the session file holds
     * nothing that helps anyone with the hash itself. Public since the
     * re-attack: WaLogin keeps the same stamp for its WhatsApp sign-ins.
     */
    public static function pwStamp(string $hash): string
    {
        return substr(hash('sha256', APP_KEY . '|' . $hash), 0, 16);
    }

    /**
     * 16 Nov 2026: re-hash a password the caller has JUST verified when its hash is older /
     * cheaper than Security::HASH_COST. Written only while the row still holds the hash that
     * was checked (a reset landing in between wins). Returns the hash now on file; any failure
     * leaves the old one, which still verifies — never a reason a sign-in fails.
     */
    private static function upgradePasswordHash(int $adminId, string $plain, string $hash): string
    {
        if ($adminId <= 0 || $plain === '' || !Security::needsRehash($hash)) {
            return $hash;
        }
        try {
            $new = Security::hashPassword($plain);
            return Database::run('UPDATE admins SET password_hash = :n WHERE id = :i AND password_hash = :o',
                ['n' => $new, 'i' => $adminId, 'o' => $hash]) === 1 ? $new : $hash;
        } catch (Throwable $e) {
            return $hash;
        }
    }

    /**
     * 16 Nov 2026 (P1-6d): 'via_otp' for a session opened by a no-password door. It does two jobs:
     * change-password.php lets a FORCED change (must_change_pw) skip the current password, and the
     * top bar / home hide "change password" (no password was typed). A WhatsApp proof, a passkey or
     * an office check keep via_otp = true as before. A PIN / pattern must never be enough to replace
     * the account's password, so a PIN / pattern sign-in on an account under a forced change is NOT
     * via_otp (the current password is asked). Every other PIN / pattern session keeps via_otp = true:
     * nothing is forced there, so change-password.php asks the current password anyway and the
     * "change password" link stays hidden as before. A forced change switched on later (a new password
     * ends the session; "must change" alone does not) drops via_otp in admin()'s 30 s re-check. The
     * door itself is carried explicitly in the session as 'via' ('pin' / 'pattern' / ...).
     *
     * @param array<string, mixed> $admin
     */
    private static function viaOtpFor(string $how, array $admin): bool
    {
        return !in_array($how, ['pin', 'pattern'], true) || (int) ($admin['must_change_pw'] ?? 0) !== 1;
    }

    /** The stamp of the password on file for this account right now. */
    private static function adminPwStamp(int $adminId): string
    {
        return self::pwStamp((string) Database::scalar(
            'SELECT password_hash FROM admins WHERE id = :id', ['id' => $adminId], ''));
    }

    /**
     * 22 Oct 2026 (review #11, office part): the person signed in here just
     * changed their OWN password — give THIS session the new stamp so they
     * carry on; every other session of the account ends on its next re-check
     * (within 30 s). Call it after the new hash is written. Does nothing when
     * this browser is not signed in as that account (the office resetting
     * somebody else), so callers need not check.
     */
    public static function refreshOwnPwStamp(int $adminId): void
    {
        if ($adminId <= 0 || (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) !== $adminId) {
            return;
        }
        $_SESSION[ADMIN_SESSION_KEY]['pw'] = self::adminPwStamp($adminId);
    }

    /**
     * 22 Oct 2026 (review #11 re-attack): call right after a new
     * admins.password_hash is written for an existing account — from EVERY
     * place that writes one (change-password.php, Staff, the credentials
     * panel, the team portal). It does three things:
     *   - the account's WhatsApp staff sign-ins (wa_logins) end now. They
     *     never pass through admin(), so the stamp could not reach them, and
     *     a WhatsApp login opened with the old password ran on for its 12 h;
     *   - this browser, when it is signed in as that account, takes the new
     *     stamp and a new session id (so a copied cookie of it ends too) —
     *     but only when it was opened with the password just replaced
     *     ($oldHash): a session that was already out of date does not get
     *     to adopt the new password, it ends on its re-check like the rest;
     *   - every other office session of the account ends on its next
     *     re-check (admin(), within 30 s).
     */
    public static function passwordChanged(int $adminId, int $by = 0, ?string $oldHash = null): void
    {
        if ($adminId <= 0) {
            return;
        }
        require_once __DIR__ . '/walogin.php';
        WaLogin::revokeAdmin($adminId, $by > 0 ? $by : null);

        if ((int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) !== $adminId) {
            return;
        }
        $had = (string) ($_SESSION[ADMIN_SESSION_KEY]['pw'] ?? '');
        if ($oldHash !== null && $had !== '' && !hash_equals(self::pwStamp($oldHash), $had)) {
            return;
        }
        self::refreshOwnPwStamp($adminId);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
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
    public static function agentLoginStart(string $code, string $mobile, string $intl = ''): array
    {
        $ip      = Security::clientIp();
        $generic = ['ok' => false, 'error' => 'Agent code and mobile number did not match our records.'];

        if (!Security::rateLimit('agent_otp', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        // 30 Oct 2026: the code may be left empty — the mobile alone; when it carries several
        // IDs the person picks one after the code (agentLoginVerify → agentLoginPick).
        // 31 Oct 2026 (review): India and Nepal share 10-digit mobiles, so the door works on the WHOLE
        // WhatsApp number (country + mobile): only accounts whose own number is exactly that one, and
        // the code goes to that number — never to the +91 twin of a +977 member.
        [$admin, $intl] = self::agentDoorAccount($code, $mobile, $intl);
        if ($admin === null) {
            Logger::warning('Agent OTP login: no match', ['code' => Security::clean($code, 12), 'ip' => $ip]);
            // 30 Oct 2026: 'nomatch' lets the one door show every number the same neutral reply
            return $generic + ['nomatch' => true];
        }

        $otp   = self::issueOtp(self::agentOtpKey($intl), 'login', 'whatsapp');
        if (!$otp['ok']) {
            return ['ok' => false, 'error' => (string) ($otp['error'] ?? 'Could not send a code right now.')];
        }

        // Auth mints, Notify delivers — same split as the customer flow.
        // The plain code goes to the phone and NOWHERE else (no debug echo).
        require_once __DIR__ . '/notify.php';
        $text = Settings::getString('company_name', APP_NAME)
              . ' एजेन्ट लगइन कोड: ' . $otp['code'] . "\n"
              . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य। यो कोड कसैलाई नबताउनुहोस्।';
        Notify::whatsapp($intl, $text);
        if (Settings::getBool('sms_send_otp', false)) {
            Notify::sms($intl, $text);
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
    public static function agentLoginVerify(string $code, string $mobile, string $otpCode, string $intl = ''): array
    {
        $ip = Security::clientIp();

        if (!Security::rateLimit('agent_otp_verify', $ip, 10, 600, LOGIN_LOCKOUT_MIN * 60)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }

        // 31 Oct 2026 (review): the same whole WhatsApp number the code was sent to (agentLoginStart).
        if (trim($code) === '') {
            $intl = self::doorIntl($mobile, $intl);
            $list = self::otpDoorAccountsFor($mobile, $intl);
        } else {
            [$one, $intl] = self::agentDoorAccount($code, $mobile, '');
            $list = $one !== null ? [$one] : [];
        }
        $admin = $list[0] ?? null;
        if ($admin === null) {
            return ['ok' => false, 'error' => 'Agent code and mobile number did not match our records.'];
        }

        $phone  = normalisePhone((string) $admin['phone']);
        $result = self::verifyOtp(self::agentOtpKey($intl), trim($otpCode), 'login');
        if (!$result['ok']) {
            LoginLog::record((string) $admin['username'], 'bad_password', (int) $admin['id']);
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'That code is not right.')];
        }

        if (count($list) > 1) {
            // One WhatsApp number, several IDs: the code proved the NUMBER; the person now picks
            // which of THIS number's accounts to open (agentLoginPick). Nothing else is offered.
            return self::otpPickList($phone, $list, $intl);
        }

        if (!self::finishAdminSession($admin, $ip, true)) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }

        return ['ok' => true];
    }

    /** @param list<array<string, mixed>> $list @return array{ok: true, choose: list<array{id: int, name: string, role: string}>} */
    private static function otpPickList(string $phone, array $list, string $intl): array
    {
        $_SESSION['ap_login_pick'] = ['phone' => $phone, 'intl' => $intl, 'ids' => array_map(static fn(array $a): int => (int) $a['id'], $list), 't' => time()];
        return ['ok' => true, 'choose' => array_map(static fn(array $a): array => [
            'id' => (int) $a['id'], 'name' => (string) $a['full_name'], 'role' => (string) $a['role'],
        ], $list)];
    }

    /* ---- 31 Oct 2026 (review): the WhatsApp door works on the WHOLE number ----
       normalisePhone() strips both +91 and +977, and 98XXXXXXXX exists in both countries, so a
       10-digit match let the +91 holder of a +977 member's digits get that member's login code
       (Notify had no country hint → +91) and open the account. Every WhatsApp login (code or hi)
       now names one full number, opens only accounts whose OWN number is exactly that one
       (TeamPortal::intlFor; an account whose country cannot be told never opens this way — the
       office sets its country, or it uses its password), sends the code to that number, and keys
       the code to it. */

    /** The full WhatsApp number the door is about: $intl when it is this mobile's (+91/+977), else from what was typed (+977… → Nepal, otherwise India). */
    private static function doorIntl(string $mobile, string $intl = ''): string
    {
        $p10  = substr(normalisePhone($mobile), -10);
        $intl = preg_replace('/\D/', '', $intl) ?? '';
        if (strlen($p10) === 10 && in_array($intl, ['91' . $p10, '977' . $p10], true)) {
            return $intl;
        }
        $d = preg_replace('/\D/', '', $mobile) ?? '';
        $d = str_starts_with($d, '00') ? substr($d, 2) : $d;
        return (strlen($d) === 13 && str_starts_with($d, '977') ? '977' : '91') . $p10;
    }

    /** The whole WhatsApp number on file for an account ('' = its country cannot be told). */
    private static function accountIntl(array $admin): string
    {
        require_once __DIR__ . '/teamportal.php';
        $p10 = substr(normalisePhone((string) ($admin['phone'] ?? '')), -10);
        return strlen($p10) === 10 ? TeamPortal::intlFor($p10, $admin) : '';
    }

    /** The otp_codes key of a WhatsApp login code. India keeps its bare 10 digits (codes in flight survive the deploy); any other country carries its code, so the keys never meet. */
    private static function agentOtpKey(string $intl): string
    {
        return 'agent:' . ((strlen($intl) === 12 && str_starts_with($intl, '91')) ? substr($intl, 2) : $intl);
    }

    /**
     * The account a code login is about, with the whole number its code goes to.
     * Mobile only: the oldest OTP-door account whose own number is exactly the door's number.
     * Agent code + mobile (the older door): that agent; a number with no country on file keeps the
     * old default (+91 — what it always got), a known country gets its own.
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private static function agentDoorAccount(string $code, string $mobile, string $intl): array
    {
        if (trim($code) === '') {
            $intl = self::doorIntl($mobile, $intl);
            return [self::otpDoorAccountsFor($mobile, $intl)[0] ?? null, $intl];
        }
        $admin = self::resolveAgentLogin($code, $mobile);
        if ($admin === null) {
            return [null, ''];
        }
        $own = self::accountIntl($admin);
        return [$admin, $own !== '' ? $own : self::doorIntl((string) $admin['phone'])];
    }

    /** otpDoorAccounts() of this mobile whose own WhatsApp number is exactly $intl. @return list<array<string, mixed>> */
    public static function otpDoorAccountsFor(string $mobile, string $intl): array
    {
        $intl = preg_replace('/\D/', '', $intl) ?? '';
        if ($intl === '') {
            return [];
        }
        $out = [];
        foreach (self::otpDoorAccounts($mobile) as $a) {
            $own = self::accountIntl($a);
            if ($own !== '' && $own === $intl) {
                $out[] = $a;
            } elseif ($own === '') {
                Logger::info('WhatsApp sign-in refused: no country on file for account #' . (int) $a['id'] . ' — set +91 / +977 in its profile', [], 'otp');
            }
        }
        return $out;
    }

    /**
     * 30 Oct 2026 (one door, "send hi on WhatsApp"): the number was proved by a WhatsApp message from
     * exactly $intl (WaVerify::consumeLogin). Open what the mobile-only code door would open — the
     * OTP_DOOR_ROLES accounts on this mobile, no 2FA — and only those whose number on file is exactly
     * $intl (31 Oct 2026, review: an account with no country on file no longer opens for either
     * country). Several → the same "which ID?" pick as the code door.
     *
     * @return array{ok: bool, choose?: list<array<string, mixed>>, none?: bool, error?: string}
     */
    public static function otpDoorOpen(string $mobile, string $how, string $intl = ''): array
    {
        $phone = normalisePhone($mobile);
        $list = self::otpDoorAccountsFor($phone, $intl);
        if ($list === []) {
            return ['ok' => false, 'none' => true];
        }
        if (count($list) > 1) {
            return self::otpPickList($phone, $list, (string) preg_replace('/\D/', '', $intl));
        }
        if (!self::finishAdminSession($list[0], Security::clientIp(), true)) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }
        Logger::audit('admin.login.' . preg_replace('/[^a-z_]/', '', $how), 'admin', (string) $list[0]['id'], null, null,
            'Signed in with WhatsApp (' . $how . ') — no password typed');
        return ['ok' => true];
    }

    /** Roles the mobile + WhatsApp-code door may open (no password typed): the member roles only, never office ones. */
    public const OTP_DOOR_ROLES = ['agent', 'counter', 'scanner'];

    /**
     * 30 Oct 2026 (owner: one WhatsApp number may carry up to 3 IDs): every account the
     * mobile-only WhatsApp-code door may open for this mobile — active, not locked, a
     * member role (OTP_DOOR_ROLES), no 2FA. Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function otpDoorAccounts(string $mobile): array
    {
        $mobile = normalisePhone($mobile);
        if (strlen($mobile) < 7) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll(
            "SELECT id, username, full_name, phone, role, permissions, is_active, locked_until, must_change_pw
               FROM admins
              WHERE is_active = 1 AND role IN ('" . implode("','", self::OTP_DOOR_ROLES) . "') AND phone IS NOT NULL AND phone <> ''
              ORDER BY id ASC"
        ) as $a) {
            if (normalisePhone((string) $a['phone']) !== $mobile) {
                continue;
            }
            if (!empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time()) {
                continue;
            }
            if (self::admin2faEnabled((int) $a['id'])) {
                continue;
            }
            $out[] = $a;
        }
        return $out;
    }

    /**
     * Agent login, step 3 (only when the mobile carries several IDs): open the one picked.
     * Only an account in the list THIS session proved the WhatsApp code for (5 minutes),
     * and only while it still has that mobile, is active and a member role.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function agentLoginPick(int $adminId): array
    {
        $p = $_SESSION['ap_login_pick'] ?? null;
        if (!is_array($p) || (int) ($p['t'] ?? 0) < time() - 300 || !in_array($adminId, array_map('intval', (array) ($p['ids'] ?? [])), true)) {
            unset($_SESSION['ap_login_pick']);   // one try: a pick outside the list ends it (sign in again)
            return ['ok' => false, 'error' => 'फेरि लगइन गर्नुहोस् · Please sign in again.'];
        }
        $admin = null;
        // 31 Oct 2026 (review): still on the very WhatsApp number that was proved (a list from before carries none → sign in again)
        foreach (self::otpDoorAccountsFor((string) $p['phone'], (string) ($p['intl'] ?? '')) as $a) {
            if ((int) $a['id'] === $adminId) {
                $admin = $a;
            }
        }
        unset($_SESSION['ap_login_pick']);
        if ($admin === null) {
            return ['ok' => false, 'error' => 'यो ID अहिले खुल्दैन · That ID cannot be opened now — please sign in again.'];
        }
        if (!self::finishAdminSession($admin, Security::clientIp(), true)) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }
        Logger::audit('agent.login.pick', 'admin', (string) $adminId, null, null, 'Picked one of ' . count((array) $p['ids']) . ' IDs on one WhatsApp number');
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
            password_verify($password, '$2y$12$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');   // Security::HASH_COST
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
        if (!self::finishAdminSession($admin, $ip, false, self::pwStamp((string) $admin['password_hash']))) {
            return ['ok' => true, 'devices' => true];   // 16 Nov 2026 (P1-5b): held at the device-limit step
        }
        Logger::audit('agent.login', 'admin', (string) $admin['id'], null, null, 'Agent signed in with email + username + password');

        return ['ok' => true];
    }

    /* =================================================================
     *  Team portal door (22 Oct 2026, owner: one login for partners, agents
     *  and staff, tied to the mobile they registered with — "Password +
     *  forgot via WhatsApp"). includes/teamportal.php drives it; these two
     *  keep the session bootstrap in one place, so LoginLog, audit, device
     *  tracking and the must-change rule stay exactly as for every door.
     * ================================================================= */

    /** Account roles that may sign in at /team/ with mobile + password. */
    public const TEAM_ROLES = ['agent', 'counter'];

    /**
     * Agent / counter accounts whose registered mobile is this 10-digit
     * number (admins.phone is stored as typed, so compared normalised).
     *
     * @return list<array<string, mixed>>
     */
    public static function teamAccounts(string $phone10): array
    {
        if (strlen($phone10) !== 10) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll(
            "SELECT id, username, email, password_hash, full_name, phone, role, permissions,
                    is_active, locked_until, failed_logins, must_change_pw
               FROM admins
              WHERE role IN ('" . implode("','", self::TEAM_ROLES) . "') AND phone IS NOT NULL AND phone <> ''
              ORDER BY id ASC"
        ) as $a) {
            if (normalisePhone((string) $a['phone']) === $phone10) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * 23 Oct 2026 (owner: "admin, staff, counter - sabai login portal ma yei lock system"): EVERY
     * active account whose registered mobile is this number, any role - the biometric doors
     * (passkey, camera face, birth date + ID) open the office too.
     *
     * @return list<array<string, mixed>>
     */
    public static function adminsByPhone(string $phone10): array
    {
        if (strlen($phone10) !== 10) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll(
            "SELECT id, username, email, password_hash, full_name, phone, role, permissions,
                    is_active, locked_until, failed_logins, must_change_pw
               FROM admins
              WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''
              ORDER BY id ASC"
        ) as $a) {
            if (normalisePhone((string) $a['phone']) === $phone10) {
                $out[] = $a;
            }
        }
        return $out;
    }

    /**
     * Open the session for an account a door proved WITHOUT a password - a passkey the device
     * signed, the face on the record, or the birth date + ID number. Any role. The account must
     * be active, not locked, with a password on file (the session is stamped with it, so a
     * reset by the office still signs this session out). 2FA accounts keep their code door.
     *
     * @throws RuntimeException with a user-safe message
     */
    public static function openAdminWithoutPassword(array $admin, string $how): void
    {
        if ((int) ($admin['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('This account is switched off.');
        }
        if ((string) ($admin['password_hash'] ?? '') === '') {
            throw new RuntimeException('This account has no password yet - ask the office to set one.');
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            throw new RuntimeException('This account is locked for a few minutes.');
        }
        if (self::admin2faEnabled((int) $admin['id'])) {
            throw new RuntimeException('This account uses 2FA - sign in with the username, password and code.');
        }
        // via_otp = true: no password was typed, so a forced change (must_change_pw) asks for the new one only -
        // change-password.php would otherwise demand a "current password" the person never received (23 Oct 2026).
        // 16 Nov 2026: except a PIN / pattern (the classic door's quick unlock) under a forced change: that asks the current password.
        // 16 Nov 2026 (P1-5b): false = held at the device-limit step (Auth::deviceHold); the page the
        // caller sends the person to bounces to admin/login.php, which shows that step.
        if (self::finishAdminSession($admin, Security::clientIp(), self::viaOtpFor($how, $admin), self::pwStamp((string) $admin['password_hash']), $how)) {
            Logger::audit('admin.login.' . $how, 'admin', (string) $admin['id'], null, null, 'Signed in without a password: ' . $how);
        }
    }

    /**
     * Open the staff session for one of teamAccounts()' rows after the
     * team door checked the password ($viaOtp false) or the person proved
     * the mobile with a WhatsApp code and set a new password ($viaOtp true).
     * 22 Oct 2026 (review #11 re-attack): $admin['password_hash'] must be the
     * hash the door checked, or the one the reset just wrote — the session
     * is stamped with it.
     */
    public static function teamOpenSession(array $admin, bool $viaOtp, bool $anyRole = false): void
    {
        // $anyRole (30 Oct 2026, one door): mobile + password opens ANY role's account on that number —
        // each account still checked against its own password (TeamPortal::login), 2FA ones go to the code step.
        if ((!$anyRole && !in_array((string) ($admin['role'] ?? ''), self::TEAM_ROLES, true)) || (int) ($admin['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('This account cannot use the team portal.');
        }
        if ((string) ($admin['password_hash'] ?? '') === '') {
            throw new RuntimeException('The team portal opens a session only for the password it checked.');
        }
        // 16 Nov 2026 (P1-5b): false = held at the device-limit step (Auth::deviceHold).
        if (self::finishAdminSession($admin, Security::clientIp(), $viaOtp, self::pwStamp((string) $admin['password_hash']))) {
            Logger::audit('team.login', 'admin', (string) $admin['id'], null, null,
                'Team portal: mobile + ' . ($viaOtp ? 'new password after a WhatsApp code' : 'password'));
        }
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
            'SELECT id, username, email, role, must_change_pw, password_hash FROM admins WHERE id = :id LIMIT 1',
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
        // 22 Oct 2026 (review #11, office part): a NEW PASSWORD is different —
        // every open session of the account ends within 30 s (Auth::admin()
        // compares the password stamp), except the editor's own when they
        // changed their own password here. Re-attack: and its WhatsApp
        // sign-ins end at once (passwordChanged).
        if (in_array('password', $changed, true)) {
            self::passwordChanged($adminId, $by, (string) $row['password_hash']);
        }
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
                (int) $_SESSION[ADMIN_SESSION_KEY]['id'],
                (string) ($_SESSION[ADMIN_SESSION_KEY]['via'] ?? '')   // 16 Nov 2026: the door the session came in by
            );
            AdminSessions::close((string) ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? ''));
        }

        unset($_SESSION[ADMIN_SESSION_KEY]);
        self::clearDoorState();   // 30-31 Oct 2026: no half-done door step (pick, reset, 2FA) for the next person
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
           checked_at stamp — those are built by hand in tests.

           22 Oct 2026 (review #11, office part): the password too. A session
           whose 'pw' stamp no longer matches the row is signed out like a
           deactivated one, so a changed password (change-password.php, the
           team portal, an office reset) ends the account's OTHER sessions
           within 30 s; the person who changed it keeps theirs
           (refreshOwnPwStamp). A session opened before the stamp existed has
           no 'pw' and is NOT signed out — it takes the current stamp here. */
        if (isset($admin['checked_at']) && (time() - (int) $admin['checked_at']) > 30) {
            try {
                $row = Database::fetch(
                    'SELECT is_active, role, permissions, must_change_pw, locked_until, password_hash
                       FROM admins WHERE id = :id',
                    ['id' => (int) $admin['id']]
                );
            } catch (Throwable $e) {
                $row = false;
            }

            if ($row !== false) {
                $locked = $row !== null && !empty($row['locked_until'])
                    && strtotime((string) $row['locked_until']) > time();
                $stamp     = $row !== null ? self::pwStamp((string) ($row['password_hash'] ?? '')) : '';
                $pwChanged = $row !== null && isset($admin['pw']) && !hash_equals($stamp, (string) $admin['pw']);
                // 29 Oct 2026: the office ended this browser (admin/sessions.php).
                // Asked only when everything else passed; registers a session
                // opened before the registry existed instead of ending it.
                $ended = false;
                if ($row !== null && (int) $row['is_active'] === 1 && !$locked && !$pwChanged) {
                    $ended = !AdminSessions::stillOpen($_SESSION[ADMIN_SESSION_KEY]);
                }
                if ($row === null || (int) $row['is_active'] !== 1 || $locked || $pwChanged || $ended) {
                    Logger::audit('admin.session_revoked', 'admin', (string) $admin['id'], null, null,
                        $row === null ? 'account deleted' : ($locked ? 'account locked'
                            : ((int) $row['is_active'] !== 1 ? 'account deactivated'
                            : ($ended ? 'session ended by the office' : 'password changed'))));
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
                /* 16 Nov 2026 (P1-6d review): the office can switch "must change" on WITHOUT a new
                   password (setStaffCredentials), so the forced change may start mid-session. A PIN /
                   pattern session then asks the current password too, same as viaOtpFor() at sign-in. */
                if ((int) $row['must_change_pw'] === 1
                    && in_array((string) ($_SESSION[ADMIN_SESSION_KEY]['via'] ?? ''), ['pin', 'pattern'], true)) {
                    $_SESSION[ADMIN_SESSION_KEY]['via_otp'] = false;
                }
                $_SESSION[ADMIN_SESSION_KEY]['checked_at']     = time();
                $_SESSION[ADMIN_SESSION_KEY]['pw']             = $stamp;   // same value, or the first stamp of an older session
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
     * The desk code a COUNTER login's books are pinned to, or null.
     *
     * Two clerks may share the Nepalgunj desk and each must see the desk's
     * whole day, while neither should read Surat's - so the key is the place,
     * not the person. OFF by default (setting counter_desk_isolation, switched
     * on Admin -> Counters & collection). A clerk with no desk is never scoped
     * ("nowhere" would hide every ticket). The bus itself - manifest, seat map,
     * trips board, ticket scan - stays shared: this separates the BOOKS.
     * Ported from the finish-20260927 line (4 Oct 2026).
     */
    public static function deskScopeCode(): ?string
    {
        $admin = self::admin();
        if ($admin === null || (string) ($admin['role'] ?? '') !== 'counter') {
            return null;
        }
        if (!Settings::getBool('counter_desk_isolation', false)) {
            return null;
        }
        $code = CounterDesk::forAdmin((int) ($admin['id'] ?? 0))['code'];

        return $code === '' ? null : $code;
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

        $rolePerms  = self::rolePermissions($role);
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
    /** Tests set this to run the PIN gate from the CLI (hand-built sessions skip it otherwise). */
    public static bool $pinGateInCli = false;

    /** Must this signed-in account set a PIN / pattern before any page opens? (cached once true) */
    public static function pinSetupNeeded(int $adminId): bool
    {
        if (!empty($_SESSION[ADMIN_SESSION_KEY]['qk_ok']) || (PHP_SAPI === 'cli' && !self::$pinGateInCli)) {
            return false;
        }
        try {
            require_once __DIR__ . '/codelogin.php';
            if (!CodeLogin::required()) {
                return false;
            }
            $st = QuickUnlock::status($adminId);
        } catch (Throwable $e) {
            return false;   // never lock the office out over a missing table
        }
        if ($st['pin'] || $st['pattern']) {
            $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;
            return false;
        }
        return true;
    }

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
            && !in_array($file, ['change-password.php', 'logout.php'], true)) {
            /* 4 Oct 2026: the API was let through here, so a temporary
               password could sell, verify payments and read registers through
               /api/ without ever being changed. */
            if ($wantsJson) {
                Response::forbidden('Change your temporary password first. / पहिले अस्थायी पासवर्ड बदल्नुहोस्।');
            }
            Response::redirect('admin/change-password.php');
        }

        /* 15 Nov 2026 (code-first login): no dashboard without a PIN / pattern.
           Whatever door opened this session (the old password / WhatsApp door,
           a passkey), an account with neither is sent to pin-setup.php first.
           Pages only: the APIs a page already open is calling keep answering. */
        if (!$wantsJson && !in_array($file, ['pin-setup.php', 'logout.php', 'change-password.php'], true)
            && self::pinSetupNeeded((int) $admin['id'])) {
            Response::redirect('admin/pin-setup.php');
        }

        /* 5 Oct 2026: the viewer role is read-only everywhere - pages and APIs.
           Any request that could change something (POST / PUT / DELETE) stops
           here, so no page needs its own check and a new page cannot forget. */
        if (($admin['role'] ?? '') === 'viewer' && !in_array($file, self::VIEWER_PAGES, true)) {
            if ($wantsJson) {
                Response::forbidden('This is a view-only account. / यो खाता हेर्न मात्र हो।');
            }
            Response::redirect('admin/index.php');
        }
        if (($admin['role'] ?? '') === 'viewer'
            && !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD', 'OPTIONS'], true)
            && !in_array($file, ['logout.php', 'change-password.php'], true)) {
            Logger::warning('Read-only account tried to change something', ['admin' => $admin['username'] ?? '', 'file' => $file], 'security');
            if ($wantsJson) {
                Response::forbidden('This is a view-only account. / यो खाता हेर्न मात्र हो।');
            }
            http_response_code(403);
            exit('This is a view-only account - it cannot change anything. / यो खाता हेर्न मात्र हो। <a href="javascript:history.back()">Back</a>');
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
                $home = self::homeUrl($admin);   // 30 Oct 2026: the one home (or the old role map when switched off)
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
        /* 8 Oct 2026 (D8-M1): the two buckets above count per PURPOSE, so
           login + register + verify + reset + cancel multiplied the hourly
           allowance by five for one number. This one counts every code sent
           to the number whatever it was for. Checked last, so a request the
           per-purpose limits already refused is not counted here. */
        if (!Security::rateLimit('otp_send_any', $identifier, 8, 3600)) {
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
     * $proven (8 Oct 2026, owner: "name + phone is never proof"): true only
     * when the number was just proven with the WhatsApp code (api/otp.php
     * verify). The plain name + mobile door ('quick' / "continue without
     * code") passes false: that session may book, but it never marks the
     * number verified, never renames or re-countries an EXISTING account and
     * never learns the name on file — anybody can type somebody else's
     * mobile. A brand-new account still stores what was typed.
     *
     * @return array<string, mixed> the user row
     */
    public static function loginUser(string $phone, string $name = '', string $country = '', bool $proven = true): array
    {
        $phone = normalisePhone($phone);

        // Map the login country ('NP'/'IN') to the stored dialing code so a
        // Nepali customer's number is not silently assumed to be Indian. India
        // and Nepal share 10-digit mobiles, so the country cannot be recovered
        // from the number alone — it must be captured here, at sign-in.
        $cc          = strtoupper(trim($country));
        $countryCode = $cc === 'NP' ? '977' : ($cc === 'IN' ? '91' : '');

        $user = Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]);
        $isNew = $user === null;
        // The name THIS session may show (api/config.php and the boot data
        // read it). An unproven door must not turn a mobile number into the
        // name on file, so it only ever sees what it typed itself.
        $sessionName = null;

        if ($user === null) {
            $userId = Database::insert('users', [
                'phone'          => $phone,
                'full_name'      => $name !== '' ? Security::clean($name, 120) : null,
                'role'           => 'customer',
                'country_code'   => $countryCode !== '' ? $countryCode : '91',
                'phone_verified' => $proven ? 1 : 0,
                'last_login_at'  => date('Y-m-d H:i:s'),
            ]);

            $user = Database::fetch('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
            Logger::info('New customer registered', ['phone' => maskPhone($phone)]);
        } elseif (!$proven) {
            // Unproven sign-in to an existing account: only the visit is
            // recorded. Name, country and the verified flag stay as they are.
            Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $user['id']]);
            $typed       = $name !== '' ? Security::clean($name, 120) : '';
            $sessionName = mb_strlen($typed) >= 2 ? $typed : '';
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
                // An unproven door's name and country are claims, not facts:
                // the vault (one-way enrichment) must not be renamed by them.
                ($proven || $isNew) ? (string) ($user['full_name'] ?? '') : '',
                ($proven || $isNew) ? $countryCode : '',
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
            'name'      => $sessionName ?? (string) ($user['full_name'] ?? ''),
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

    /* =================================================================
     *  Customer private access (8 Oct 2026, owner: "name + phone alone is
     *  never proof"). Booking stays open to anyone; READING or CHANGING a
     *  booking — full history, the keyless ticket, verify, cancel, undo,
     *  the AI's private tools, keyless live tracking — needs one of:
     *    · the keyed link (k)              — checked by each caller
     *    · this PNR was created in THIS browser session (book / Quick Ticket)
     *    · an OTP-verified customer session whose account owns the booking
     *    · authorised staff                — checked by each caller
     *  The walk-in placeholder 0000000000 is never proof.
     * ================================================================= */

    /** Session key holding the PNRs this browser session created. */
    public const OWN_PNRS_KEY = 'shg_own_pnrs';

    /**
     * The ONE customer rule. Staff and keyed-link rules stay in each caller.
     *
     * @param array<string, mixed> $booking a bookings row (pnr, contact_phone, user_id)
     */
    public static function customerMayOpen(array $booking): bool
    {
        $contact = (string) ($booking['contact_phone'] ?? '');
        if (isPlaceholderPhone($contact)) {
            return false;
        }

        $pnr = strtoupper(trim((string) ($booking['pnr'] ?? '')));
        if ($pnr !== '' && in_array($pnr, self::ownPnrs(), true)) {
            return true;
        }

        $user = self::user();
        if ($user === null) {
            return false;
        }
        if (!class_exists('CustomerProfile')) {
            require_once __DIR__ . '/customerprofile.php';
        }
        $vid = CustomerProfile::verifiedUserId();
        if ($vid <= 0 || $vid !== (int) ($user['id'] ?? 0)) {
            return false;
        }
        if ((int) ($booking['user_id'] ?? 0) === $vid) {
            return true;
        }
        $mine = normalisePhone((string) ($user['phone'] ?? ''));

        return $mine !== '' && $mine === normalisePhone($contact);
    }

    /** Remember a PNR this session just created (book.php / Quick Ticket). Keeps the last 20. */
    public static function rememberOwnPnr(string $pnr): void
    {
        $pnr = strtoupper(trim($pnr));
        if ($pnr === '') {
            return;
        }
        $list   = array_values(array_filter(self::ownPnrs(), static fn(string $p): bool => $p !== $pnr));
        $list[] = $pnr;
        $_SESSION[self::OWN_PNRS_KEY] = array_slice($list, -20);
    }

    /** @return list<string> the PNRs this browser session created, oldest first */
    public static function ownPnrs(): array
    {
        $raw = $_SESSION[self::OWN_PNRS_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = strtoupper($p);
            }
        }

        return array_values(array_unique($out));
    }

    /** Forget the session's own PNRs ("Not you?" / sign out on a shared phone). */
    public static function forgetOwnPnrs(): void
    {
        unset($_SESSION[self::OWN_PNRS_KEY]);
    }
}
