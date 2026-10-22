<?php
/**
 * includes/teamportal.php — the team portal at /team/ (22 Oct 2026).
 *
 * Owner: one login for the three kinds of people who work with us —
 * Pvt. Ltd. partner companies, agents and S Hari staff — tied to the mobile
 * they registered with. Owner's choice of design: "Password + forgot via
 * WhatsApp".
 *
 *   Sign in      mobile + password. The mobile finds the person's accounts:
 *                  - an agent / counter account (admins; partners are agent
 *                    accounts of kind org) -> the normal staff session
 *                    (Auth::teamOpenSession), so selling tickets is the same
 *                    agent panel / counter screens as before;
 *                  - an approved staff application with its own password
 *                    (staff_applications.password_hash) -> a portal-only
 *                    session ($_SESSION['shg_team']): ID card, CV, agreement.
 *                Same IP budget and per-account lockout as the office login.
 *   Forgot /     the mobile -> a 6-digit WhatsApp code (AiSensy login
 *   first time   template first, like the staff form), or "hi VERIFY …"
 *                sent from the person's own WhatsApp (WaVerify) when the
 *                code does not arrive -> a new password for EVERY account on
 *                that number, then signed in.
 *
 * Never for super-admins and managers: they keep the office login + 2FA.
 * An account with 2FA switched on is sent to the office login as well.
 */

declare(strict_types=1);

require_once __DIR__ . '/staffapplications.php';
require_once __DIR__ . '/agentapplications.php';
require_once __DIR__ . '/waverify.php';

/** Refusals that are safe to show. */
final class TeamPortalError extends RuntimeException {}

final class TeamPortal
{
    public const SESSION  = 'shg_team';
    public const IDLE_SEC = 2700;      // 45 minutes, like the office session
    public const PW_MIN   = 8;

    private static ?bool $ready = null;

    private function __construct() {}

    /** upgrade-2026-10-22-team-login.sql is there. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = StaffApps::available() && (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_applications'
                        AND COLUMN_NAME IN ('password_hash','failed_logins','locked_until','last_login_at','admin_id','cv_json')",
                    [], 0
                ) === 6;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** The 10-digit mobile out of whatever was typed (+91 / +977 / spaces), or ''. */
    public static function phone10(string $raw): string
    {
        $p = normalisePhone($raw);
        return strlen($p) === 10 && ctype_digit($p) && !isPlaceholderPhone($p) ? $p : '';
    }

    /* =================================================================
     *  Who has that number
     * ================================================================= */

    /**
     * Every account on a mobile: agent / counter accounts, then approved
     * (or suspended) staff applications.
     *
     * @return array{admins: list<array<string, mixed>>, staff: list<array<string, mixed>>}
     */
    public static function accounts(string $phone10): array
    {
        $staff = self::ready() ? Database::fetchAll(
            "SELECT id, full_name, phone, phone_country, status, password_hash, failed_logins, locked_until, admin_id
               FROM staff_applications WHERE phone = :p AND status IN ('approved','suspended')",
            ['p' => $phone10]
        ) : [];
        return ['admins' => Auth::teamAccounts($phone10), 'staff' => $staff];
    }

    /**
     * International digits for WhatsApp, never from what the visitor types
     * (a code sent to the same digits in the other country would reach a
     * stranger): the staff row's country, else the country the office typed
     * on the agent's website card (phone_raw, "+977 …"), else the account's.
     */
    private static function intl(string $phone10, array $acc): string
    {
        foreach ($acc['staff'] as $s) {
            return (countryDialCode((string) $s['phone_country']) ?: '977') . $phone10;
        }
        foreach ($acc['admins'] as $a) {
            // 22 Oct 2026: imported accounts carry "91" in front of Nepali numbers; the card is what the office corrects
            $raw = AgentApplications::available() ? (string) Database::scalar(
                "SELECT phone_raw FROM agent_applications WHERE admin_id = :a AND status = 'approved' AND phone = :p AND phone_raw IS NOT NULL ORDER BY id DESC LIMIT 1",
                ['a' => (int) $a['id'], 'p' => $phone10], '') : '';
            $cc = $raw !== '' ? countryDialCode(resolvePhoneCountry('', $raw)) : '';
            if ($cc !== '') {
                return $cc . $phone10;
            }
        }
        foreach ($acc['admins'] as $a) {
            $cc = countryDialCode(resolvePhoneCountry('', (string) $a['phone']));
            return ($cc !== '' ? $cc : '91') . $phone10;
        }
        return '91' . $phone10;
    }

    /* =================================================================
     *  Sign in
     * ================================================================= */

    /**
     * @return array{ok: bool, kind?: string, error?: string, first?: bool}
     *         kind: 'admin' (staff session opened) | 'staff' (portal session)
     */
    public static function login(string $phone10, string $password): array
    {
        $generic = ['ok' => false, 'error' => 'मोबाइल नम्बर वा पासवर्ड मिलेन · Mobile or password did not match.'];
        if ($phone10 === '' || $password === '') {
            return ['ok' => false, 'error' => 'मोबाइल नम्बर र पासवर्ड लेख्नुहोस् · Enter your mobile and password.'];
        }
        $ip = Security::clientIp();
        if (!Security::rateLimit('team_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            LoginLog::record('team:' . $phone10, 'locked');
            return ['ok' => false, 'error' => 'धेरै पटक गलत भयो — ' . LOGIN_LOCKOUT_MIN . ' मिनेटपछि फेरि प्रयास गर्नुहोस् · Too many tries, wait ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }
        $acc = self::accounts($phone10);
        $now = time();
        $withPw = 0;

        foreach ($acc['admins'] as $a) {
            if ((int) $a['is_active'] !== 1 || (string) $a['password_hash'] === '') {
                continue;
            }
            $withPw++;
            if (!empty($a['locked_until']) && strtotime((string) $a['locked_until']) > $now) {
                continue;
            }
            if (Security::verifyPassword($password, (string) $a['password_hash'])) {
                if (Auth::admin2faEnabled((int) $a['id'])) {
                    return ['ok' => false, 'error' => 'यो खातामा 2FA छ — कार्यालयको लगइनबाट जानुहोस् · This account uses 2FA: please sign in at the office login.', 'twofa' => true];
                }
                Auth::teamOpenSession($a, false);
                self::closeStaffSession();
                return ['ok' => true, 'kind' => 'admin'];
            }
        }
        foreach ($acc['staff'] as $s) {
            if ((string) $s['status'] !== 'approved' || empty($s['password_hash'])) {
                continue;
            }
            $withPw++;
            if (!empty($s['locked_until']) && strtotime((string) $s['locked_until']) > $now) {
                continue;
            }
            if (Security::verifyPassword($password, (string) $s['password_hash'])) {
                self::openStaffSession((int) $s['id']);
                return ['ok' => true, 'kind' => 'staff'];
            }
        }

        // Nothing matched: count a failure on every account with a password on this number.
        foreach ($acc['admins'] as $a) {
            if ((string) $a['password_hash'] === '' || (int) $a['is_active'] !== 1) {
                continue;
            }
            $f = (int) $a['failed_logins'] + 1;
            Database::update('admins', ['failed_logins' => $f] + ($f >= MAX_LOGIN_ATTEMPTS ? ['locked_until' => date('Y-m-d H:i:s', $now + LOGIN_LOCKOUT_MIN * 60)] : []),
                'id = :id', ['id' => (int) $a['id']]);
            LoginLog::record((string) $a['username'], 'bad_password', (int) $a['id']);
        }
        foreach ($acc['staff'] as $s) {
            if (empty($s['password_hash'])) {
                continue;
            }
            $f = (int) $s['failed_logins'] + 1;
            Database::update('staff_applications', ['failed_logins' => $f] + ($f >= MAX_LOGIN_ATTEMPTS ? ['locked_until' => date('Y-m-d H:i:s', $now + LOGIN_LOCKOUT_MIN * 60)] : []),
                'id = :id', ['id' => (int) $s['id']]);
        }
        if ($acc['admins'] === [] && $acc['staff'] === []) {
            LoginLog::record('team:' . $phone10, 'unknown_user');
            password_verify($password, '$2y$11$usesomesillystringfoeu1f3.O0hOZ8W4l0sN2q7dPzYlXqR5Z8Vy');
        }
        Logger::warning('Team portal: sign-in failed', ['phone' => maskPhone($phone10), 'ip' => $ip, 'accounts' => count($acc['admins']) + count($acc['staff'])]);
        // Registered, but no password yet: say how to make one (the number is public on the website anyway).
        if ($withPw === 0 && ($acc['admins'] !== [] || $acc['staff'] !== [])) {
            return ['ok' => false, 'first' => true, 'error' => 'यो नम्बरमा अझै पासवर्ड बनेको छैन — "पासवर्ड बनाउनुहोस् / बिर्सनुभयो?" थिच्नुहोस् · No password yet: tap "Forgot / set password".'];
        }
        return $generic;
    }

    /* =================================================================
     *  Forgot / first password
     * ================================================================= */

    /**
     * Send the reset code by WhatsApp. "sent" is true only when WhatsApp
     * delivered it (a wa.me fallback is not a delivery) — otherwise the page
     * offers "hi VERIFY" from the person's own WhatsApp.
     *
     * @return array{ok: bool, sent: bool, error?: string, intl: string, debugCode?: string}
     */
    public static function sendResetCode(string $phone10): array
    {
        $acc = self::accounts($phone10);
        if ($acc['admins'] === [] && array_filter($acc['staff'], static fn($s) => (string) $s['status'] === 'approved') === []) {
            return ['ok' => false, 'sent' => false, 'intl' => '',
                'error' => 'यो नम्बर हाम्रो टोलीमा दर्ता छैन · This number is not registered with us (agent, partner or staff). Please call the office.'];
        }
        if (self::blockedOnly($acc)) {
            return ['ok' => false, 'sent' => false, 'intl' => '',
                'error' => 'यो खाता बन्द छ — कार्यालयमा सम्पर्क गर्नुहोस् · This account is switched off. Please call the office.'];
        }
        $intl = self::intl($phone10, $acc);
        if (!Security::rateLimit('team_reset_ip', Security::clientIp(), 8, 600)) {
            return ['ok' => false, 'sent' => false, 'intl' => $intl, 'error' => 'धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.'];
        }
        $capH = max(1, Settings::getInt('otp_global_hourly_cap', 150));
        $capD = max(1, Settings::getInt('otp_global_daily_cap', 800));
        if (!Security::rateLimit('otp_send_global_h', 'global', $capH, 3600) || !Security::rateLimit('otp_send_global_d', 'global', $capD, 86400)) {
            Logger::warning('Team portal: reset code NOT sent, global OTP ceiling reached', ['phone' => maskPhone($phone10)], 'otp');
            return ['ok' => true, 'sent' => false, 'intl' => $intl];
        }
        $res = Auth::issueOtp('team:' . $phone10, 'reset', 'whatsapp');
        if (!$res['ok']) {
            return ['ok' => false, 'sent' => false, 'intl' => $intl, 'error' => (string) ($res['error'] ?? 'Could not send a code right now.')];
        }
        $code = (string) $res['code'];
        $sent = false;
        require_once __DIR__ . '/notify.php';
        try {
            if (!class_exists('AiSensyAPI') && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
            }
            if (class_exists('AiSensyAPI') && AiSensyAPI::enabled()) {
                $r = AiSensyAPI::sendLoginOtp($intl, $code, '');
                $sent = (bool) $r['success'];
            } else {
                $text = Settings::getString('company_name', APP_NAME) . ' टिम पोर्टल पासवर्ड कोड: ' . $code . ' (' . OTP_EXPIRY_MINUTES
                      . ' मिनेट सम्म मान्य)। यो कोड कसैलाई नबताउनुहोस्।';
                $sent = Notify::whatsapp($intl, $text, null, null, [], null, ['purpose' => 'otp']) === true;   // a wa.me link is not a delivery
            }
        } catch (Throwable $e) {
            $sent = false;
        }
        Logger::info('Team portal: reset code ' . ($sent ? 'sent' : 'NOT delivered'), ['phone' => maskPhone($phone10)], 'otp');
        $out = ['ok' => true, 'sent' => $sent, 'intl' => $intl];
        if (APP_DEBUG) {
            $out['debugCode'] = $code;   // a dev box only — never in production
        }
        return $out;
    }

    /** Every account on the number is switched off / suspended. */
    private static function blockedOnly(array $acc): bool
    {
        foreach ($acc['admins'] as $a) {
            if ((int) $a['is_active'] === 1) {
                return false;
            }
        }
        foreach ($acc['staff'] as $s) {
            if ((string) $s['status'] === 'approved') {
                return false;
            }
        }
        return true;
    }

    /** "hi VERIFY …" for a reset: the token + the wa.me link (WaVerify, purpose 'reset'). */
    public static function startVerify(string $phone10): array
    {
        $acc = self::accounts($phone10);
        if ($acc['admins'] === [] && $acc['staff'] === []) {
            throw new TeamPortalError('यो नम्बर हाम्रो टोलीमा दर्ता छैन · This number is not registered with us.');
        }
        $intl = self::intl($phone10, $acc);
        if (!Security::rateLimit('wa_verify_ip', Security::clientIp(), 8, 600) || !Security::rateLimit('wa_verify_phone', $phone10, 4, 600)) {
            throw new TeamPortalError('धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.');
        }
        return WaVerify::start('reset', $phone10, str_starts_with($intl, '977') ? 'NP' : 'IN', Security::clientIp());
    }

    /**
     * The proven mobile sets a new password on every account it has, then
     * signs in. Proof = the WhatsApp code, or a "hi VERIFY" this session made.
     *
     * @return array{ok: bool, kind?: string, error?: string, twofa?: bool, count?: int}
     */
    public static function resetPassword(string $phone10, string $code, string $pw, string $pw2): array
    {
        if (mb_strlen($pw) < self::PW_MIN) {
            return ['ok' => false, 'error' => 'पासवर्ड कम्तीमा ' . self::PW_MIN . ' अक्षरको हुनुपर्छ · At least ' . self::PW_MIN . ' characters.'];
        }
        if ($pw !== $pw2) {
            return ['ok' => false, 'error' => 'दुवै पासवर्ड एउटै हुनुपर्छ · The two passwords are not the same.'];
        }
        if (preg_match('/^\d+$/', $pw) === 1 && str_contains($phone10, $pw)) {
            return ['ok' => false, 'error' => 'मोबाइल नम्बर पासवर्ड नबनाउनुहोस् · Do not use your mobile number as the password.'];
        }
        $proven = WaVerify::sessionVerified($phone10);
        if (!$proven) {
            $v = Auth::verifyOtp('team:' . $phone10, $code, 'reset');
            if (!$v['ok']) {
                return ['ok' => false, 'error' => (string) ($v['error'] ?? 'That code did not match.')];
            }
        }
        $acc  = self::accounts($phone10);
        $hash = Security::hashPassword($pw);
        $n = 0;
        $open = null;
        foreach ($acc['admins'] as $a) {
            if ((int) $a['is_active'] !== 1) {
                continue;
            }
            Database::update('admins', ['password_hash' => $hash, 'failed_logins' => 0, 'locked_until' => null, 'must_change_pw' => 0],
                'id = :id', ['id' => (int) $a['id']]);
            Logger::audit('team.password_reset', 'admin', (string) $a['id'], null, null, 'New password set at /team/ after a WhatsApp ' . ($proven ? '"hi VERIFY"' : 'code'));
            $n++;
            if ($open === null) {
                $open = $a;
            }
        }
        $staffId = 0;
        foreach ($acc['staff'] as $s) {
            if ((string) $s['status'] !== 'approved') {
                continue;
            }
            Database::update('staff_applications', ['password_hash' => $hash, 'failed_logins' => 0, 'locked_until' => null],
                'id = :id', ['id' => (int) $s['id']]);
            Logger::audit('team.password_reset', 'staff_application', StaffApps::refFor((int) $s['id']), null, null, 'New password set at /team/');
            $n++;
            $staffId = $staffId ?: (int) $s['id'];
        }
        unset($_SESSION['wa_verified'][$phone10]);
        if ($n === 0) {
            return ['ok' => false, 'error' => 'यो खाता बन्द छ — कार्यालयमा सम्पर्क गर्नुहोस् · This account is switched off. Please call the office.'];
        }
        if ($open !== null) {
            if (Auth::admin2faEnabled((int) $open['id'])) {
                return ['ok' => true, 'kind' => 'none', 'twofa' => true, 'count' => $n];
            }
            Auth::teamOpenSession($open + ['must_change_pw' => 0], true);
            self::closeStaffSession();
            return ['ok' => true, 'kind' => 'admin', 'count' => $n];
        }
        self::openStaffSession($staffId);
        return ['ok' => true, 'kind' => 'staff', 'count' => $n];
    }

    /**
     * A signed-in person changes their own password (current one required).
     * Signed in with an account: that account, and the staff card on the
     * same number, get the new one, so one password keeps working for both.
     */
    public static function changePassword(array $me, string $old, string $pw, string $pw2): void
    {
        if (mb_strlen($pw) < self::PW_MIN) {
            throw new TeamPortalError('पासवर्ड कम्तीमा ' . self::PW_MIN . ' अक्षरको हुनुपर्छ · At least ' . self::PW_MIN . ' characters.');
        }
        if ($pw !== $pw2) {
            throw new TeamPortalError('दुवै पासवर्ड एउटै हुनुपर्छ · The two passwords are not the same.');
        }
        $cur = $me['via'] === 'admin'
            ? (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => (int) $me['admin']['id']], '')
            : (string) ($me['staff']['password_hash'] ?? '');
        if (!Security::verifyPassword($old, $cur)) {
            throw new TeamPortalError('अहिलेको पासवर्ड मिलेन · The current password is wrong.');
        }
        $hash = Security::hashPassword($pw);
        if ($me['via'] === 'admin') {
            Database::update('admins', ['password_hash' => $hash, 'must_change_pw' => 0], 'id = :id', ['id' => (int) $me['admin']['id']]);
            Logger::audit('team.password_change', 'admin', (string) $me['admin']['id'], null, null, 'Password changed at /team/');
        }
        if ($me['staff'] !== null && (string) $me['staff']['status'] === 'approved') {
            Database::update('staff_applications', ['password_hash' => $hash], 'id = :id', ['id' => (int) $me['staff']['id']]);
            Logger::audit('team.password_change', 'staff_application', (string) $me['staff']['ref'], null, null, 'Password changed at /team/');
        }
    }

    /* =================================================================
     *  Sessions
     * ================================================================= */

    private static function openStaffSession(int $staffId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION] = ['staff_id' => $staffId, 'at' => time(), 'seen' => time()];
        Database::update('staff_applications', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')],
            'id = :id', ['id' => $staffId]);
        Logger::audit('team.login', 'staff_application', StaffApps::refFor($staffId), null, null, 'Team portal sign-in (staff)');
    }

    private static function closeStaffSession(): void
    {
        unset($_SESSION[self::SESSION]);
    }

    public static function logout(): void
    {
        if (Auth::admin() !== null) {
            Auth::adminLogout();
        }
        self::closeStaffSession();
        session_regenerate_id(true);
    }

    /**
     * The person signed in at /team/ (or already signed in to the office),
     * with their cards found by account link and by mobile.
     *
     * @return array{via: string, admin: ?array, staff: ?array, agent: ?array, phone10: string, name: string}|null
     */
    public static function current(): ?array
    {
        $admin = Auth::admin();
        $staff = null;
        $agent = null;
        if ($admin !== null) {
            $row = Database::fetch('SELECT id, phone, full_name, role FROM admins WHERE id = :i', ['i' => (int) $admin['id']]);
            $phone10 = self::phone10((string) ($row['phone'] ?? ''));
            if (self::ready()) {
                $sid = (int) Database::scalar(
                    "SELECT id FROM staff_applications WHERE status IN ('approved','suspended','exited')
                        AND (admin_id = :a OR (:p1 <> '' AND phone = :p2)) ORDER BY (admin_id = :a2) DESC, id DESC LIMIT 1",
                    ['a' => (int) $admin['id'], 'a2' => (int) $admin['id'], 'p1' => $phone10, 'p2' => $phone10], 0);
                $staff = $sid > 0 ? StaffApps::find($sid) : null;
            }
            if (AgentApplications::available()) {
                $aid = (int) Database::scalar(
                    "SELECT id FROM agent_applications WHERE status = 'approved' AND admin_id = :a ORDER BY id DESC LIMIT 1",
                    ['a' => (int) $admin['id']], 0);
                $agent = $aid > 0 ? AgentApplications::find($aid) : null;
            }
            return ['via' => 'admin', 'admin' => $admin, 'staff' => $staff, 'agent' => $agent, 'phone10' => $phone10,
                    'name' => (string) ($agent['name'] ?? $staff['full_name'] ?? $admin['full_name'] ?? '')];
        }
        $t = $_SESSION[self::SESSION] ?? null;
        if (!is_array($t) || empty($t['staff_id']) || !self::ready()) {
            return null;
        }
        if ((int) ($t['seen'] ?? 0) < time() - self::IDLE_SEC) {
            self::closeStaffSession();
            return null;
        }
        $staff = StaffApps::find((int) $t['staff_id']);
        if ($staff === null || (string) $staff['status'] !== 'approved' || empty($staff['password_hash'])) {
            self::closeStaffSession();   // suspended / left / password cleared by the office: out
            return null;
        }
        $_SESSION[self::SESSION]['seen'] = time();
        return ['via' => 'staff', 'admin' => null, 'staff' => $staff, 'agent' => null, 'phone10' => (string) $staff['phone'],
                'name' => (string) $staff['full_name']];
    }

    /** Where "Sell tickets" goes for a signed-in account, or null (no selling role). */
    public static function sellUrl(?array $admin): ?string
    {
        return match ((string) ($admin['role'] ?? '')) {
            'agent'   => '/admin/agent.php',
            'counter' => '/admin/bookings.php',
            'superadmin', 'manager' => '/admin/smartbook.php',
            default   => null,
        };
    }

    /** The ID card refs this person may download: staff first, then agent / partner. */
    public static function cardRefs(array $me): array
    {
        $out = [];
        if ($me['staff'] !== null && in_array((string) $me['staff']['status'], ['approved', 'suspended', 'exited'], true)) {
            $out[] = (string) $me['staff']['ref'];
        }
        if ($me['agent'] !== null && !empty($me['agent']['ref'])) {
            $out[] = (string) $me['agent']['ref'];
        }
        return $out;
    }

    /* =================================================================
     *  Agreements, signed in the portal (22 Oct 2026: "agreements fully
     *  digital"). The text is the same page the office prints (built from
     *  the owner's paper forms); signing records who, which version, when,
     *  from where, with the signature, in agreement_signatures.
     * ================================================================= */

    public const AGREEMENTS = [
        'staff'   => ['title' => 'Staff agreement · कर्मचारी सम्झौता', 'version' => StaffApps::AGREEMENT_VERSION, 'url' => '/agreements/staff-agreement.html'],
        'agent'   => ['title' => 'Individual agent / connector agreement · एजेन्ट सम्झौता', 'version' => '2.3', 'url' => '/agreements/agent-agreement-%s.html'],
        'partner' => ['title' => 'Organization / Pvt. Ltd. partner agreement · साझेदार सम्झौता', 'version' => '2.2', 'url' => '/agreements/partner-agreement-%s.html'],
    ];

    /** agreement_signatures.party_ref / signature_data are there. */
    public static function signReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agreement_signatures' AND COLUMN_NAME IN ('party_ref','signature_data')",
                    [], 0
                ) === 2;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * The agreements this person signs: staff and / or agent / partner.
     *
     * @return list<array<string, mixed>>  doc, party, id, ref, name, phone, verified, url, version, title, lang
     */
    public static function agreementsFor(array $me): array
    {
        $out = [];
        if ($me['staff'] !== null && (string) $me['staff']['status'] === 'approved') {
            $s = $me['staff'];
            $out[] = ['doc' => 'staff', 'party' => 'staff', 'id' => (int) $s['id'], 'ref' => (string) $s['ref'], 'name' => (string) $s['full_name'],
                      'phone' => (string) $s['phone'], 'verified' => (int) $s['phone_verified'], 'lang' => 'ne'] + self::AGREEMENTS['staff'];
        }
        if ($me['agent'] !== null) {
            $a = $me['agent'];
            $doc = (string) $a['type'] === 'org' ? 'partner' : 'agent';
            $lang = in_array((string) $a['lang'], ['ne', 'hi', 'en', 'gu'], true) ? (string) $a['lang'] : 'ne';
            $d = self::AGREEMENTS[$doc];
            $out[] = ['doc' => $doc, 'party' => 'agent', 'id' => (int) $a['id'], 'ref' => (string) $a['ref'], 'name' => (string) $a['name'],
                      'phone' => (string) $a['phone'], 'verified' => (int) ($a['phone_verified'] ?? 0), 'lang' => $lang,
                      'url' => sprintf($d['url'], $lang), 'version' => $d['version'], 'title' => $d['title']];
        }
        return $out;
    }

    /** The newest signature of this version for this party, or null. */
    public static function lastSign(array $ag): ?array
    {
        return Database::fetch(
            "SELECT * FROM agreement_signatures WHERE doc = :d AND party_type = :t AND party_id = :i AND version = :v ORDER BY id DESC LIMIT 1",
            ['d' => $ag['doc'], 't' => $ag['party'], 'i' => $ag['id'], 'v' => $ag['version']]
        );
    }

    /** Sign one agreement with a finger signature (data: URI). */
    public static function sign(array $me, string $doc, mixed $sig, bool $agree): array
    {
        if (!$agree) {
            throw new TeamPortalError('"मैले पढें र मान्छु" मा ✓ लगाउनुहोस् · Tick "I have read and accept".');
        }
        $ag = null;
        foreach (self::agreementsFor($me) as $a) {
            if ($a['doc'] === $doc) {
                $ag = $a;
            }
        }
        if ($ag === null) {
            throw new TeamPortalError('This agreement is not yours to sign.');
        }
        $clean = AgentApplications::cleanSignature($sig);
        if ($clean === null || strlen($clean) < 600) {
            throw new TeamPortalError('बाकसभित्र औंलाले सही गर्नुहोस् · Please sign inside the box.');
        }
        $ip = Security::clientIp();
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $path = null;
        if ($ag['doc'] === 'staff') {
            $bytes = (string) base64_decode(substr($clean, (int) strpos($clean, ',') + 1), true);
            $mime = str_starts_with($clean, 'data:image/png') ? 'image/png' : 'image/jpeg';
            $path = StaffApps::saveFile($ag['id'], 'signature', $bytes, $mime);
        }
        $row = [
            'doc' => $ag['doc'], 'version' => $ag['version'], 'event' => 'portal', 'party_type' => $ag['party'], 'party_id' => $ag['id'],
            'full_name' => mb_substr($ag['name'], 0, 120), 'phone' => $ag['phone'], 'phone_verified' => $ag['verified'] ? 1 : 0,
            'signature_path' => $path, 'ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($ua, 0, 255),
        ];
        if (self::signReady()) {
            $row['party_ref'] = $ag['ref'];
            $row['signature_data'] = $ag['doc'] === 'staff' ? null : $clean;
        }
        $sid = (int) Database::insert('agreement_signatures', $row);
        if ($ag['party'] === 'agent') {
            // the office's agreement state follows (signed today, end date from the agreement rules)
            AgentApplications::update($ag['id'], ['agreement_status' => 'signed', 'agreement_signed_at' => date('Y-m-d')], (int) ($me['admin']['id'] ?? 0));
        }
        Logger::audit('team.agreement_signed', $ag['party'] === 'agent' ? 'agent_application' : 'staff_application', $ag['ref'], null,
            ['doc' => $ag['doc'], 'version' => $ag['version'], 'sign_id' => $sid], 'Agreement signed in the team portal');
        return $ag + ['sign_id' => $sid];
    }

    /** The signature image of a signature row, as a data: URI, or null. */
    public static function signImage(array $sign): ?string
    {
        if (!empty($sign['signature_data'])) {
            return (string) $sign['signature_data'];
        }
        if (!empty($sign['signature_path']) && ($abs = StaffApps::absPath((string) $sign['signature_path'])) !== null) {
            $b = (string) file_get_contents($abs);
            $mime = str_starts_with($b, "\x89PNG") ? 'image/png' : 'image/jpeg';
            return 'data:' . $mime . ';base64,' . base64_encode($b);
        }
        return null;
    }

    /* =================================================================
     *  The office's side (Admin -> Staff applications / Agreements)
     * ================================================================= */

    /**
     * Link a staff member to a ticket-selling account (agent / counter) by
     * its username or mobile; '' unlinks. The person then signs in at /team/
     * with that account and sees their staff card too.
     */
    public static function linkAccount(int $staffId, string $who, int $by): ?array
    {
        $r = StaffApps::find($staffId);
        if ($r === null || !self::ready()) {
            throw new TeamPortalError('That application is not there.');
        }
        $who = trim($who);
        $acc = null;
        if ($who !== '') {
            $acc = Database::fetch("SELECT id, username, role, full_name FROM admins WHERE username = :u AND role IN ('agent','counter') LIMIT 1", ['u' => strtolower($who)]);
            if ($acc === null && ($p = self::phone10($who)) !== '') {
                $acc = Auth::teamAccounts($p)[0] ?? null;
            }
            if ($acc === null) {
                throw new TeamPortalError('No agent or counter account has that username or mobile.');
            }
        }
        Database::update('staff_applications', ['admin_id' => $acc !== null ? (int) $acc['id'] : null], 'id = :id', ['id' => $staffId]);
        Logger::audit('staff_app.account', 'staff_application', (string) $r['ref'], ['admin_id' => $r['admin_id'] ?? null],
            ['admin_id' => $acc['id'] ?? null], $acc !== null ? 'Linked to account ' . $acc['username'] : 'Account unlinked');
        return $acc;
    }

    /** The office clears a staff member's portal password: they make a new one with a WhatsApp code. */
    public static function clearPassword(int $staffId, int $by): void
    {
        $r = StaffApps::find($staffId);
        if ($r === null || !self::ready()) {
            throw new TeamPortalError('That application is not there.');
        }
        Database::update('staff_applications', ['password_hash' => null, 'failed_logins' => 0, 'locked_until' => null], 'id = :id', ['id' => $staffId]);
        Logger::audit('staff_app.password_clear', 'staff_application', (string) $r['ref'], null, null, 'Team portal password cleared by admin #' . $by);
    }

    /** One line about a staff member's portal login, for the office. */
    public static function loginLine(array $staff): string
    {
        if (!self::ready()) {
            return '';
        }
        $acc = !empty($staff['admin_id']) ? Database::fetch('SELECT username, role, is_active FROM admins WHERE id = :i', ['i' => (int) $staff['admin_id']]) : null;
        $bits = [!empty($staff['password_hash']) ? '🔑 password set' : '⚪ no password yet (they make one at /team/ with a WhatsApp code)'];
        if (!empty($staff['last_login_at'])) {
            $bits[] = 'last sign-in ' . date('d M Y H:i', strtotime((string) $staff['last_login_at']) ?: time());
        }
        if (!empty($staff['locked_until']) && strtotime((string) $staff['locked_until']) > time()) {
            $bits[] = '🔒 locked until ' . date('H:i', strtotime((string) $staff['locked_until']));
        }
        if ($acc !== null) {
            $bits[] = '🎫 sells tickets as ' . $acc['username'] . ' (' . $acc['role'] . ((int) $acc['is_active'] === 1 ? '' : ', switched off') . ')';
        }
        return implode(' · ', $bits);
    }

    /* =================================================================
     *  CV — education and experience (agents: the same; partners: a
     *  company profile)
     * ================================================================= */

    public const EDU_LEVELS = AgentApplications::CV_QUALS;
    public const ORG_FIELDS = [
        'reg_no' => 'Company registration No. · दर्ता नं.', 'pan' => 'PAN / VAT / GST', 'est' => 'Established (year) · स्थापना वर्ष',
        'director' => 'Director / owner · सञ्चालक', 'staff' => 'Staff count · कर्मचारी संख्या', 'branches' => 'Offices / branches · शाखा',
        'services' => 'Services · सेवाहरू',
    ];

    /** The CV of a staff or agent_applications row, with a staff form's own answers as the start. */
    public static function cv(?array $row, string $table): array
    {
        $cv = json_decode((string) ($row['cv_json'] ?? ''), true);
        $cv = is_array($cv) ? $cv : [];
        $cv += ['edu' => [], 'exp' => [], 'langs' => '', 'skills' => '', 'about' => '', 'org' => []];
        if ($table === 'staff' && $row !== null && $cv['edu'] === [] && !empty($row['qualification'])) {
            $cv['edu'][] = ['level' => (string) $row['qualification'], 'school' => '', 'year' => ''];
        }
        if ($table === 'staff' && $row !== null && $cv['exp'] === [] && (!empty($row['prev_employer']) || (int) ($row['experience_years'] ?? 0) > 0)) {
            $cv['exp'][] = ['org' => (string) ($row['prev_employer'] ?? ''), 'role' => (string) ($row['prev_role'] ?? ''), 'years' => (string) (int) ($row['experience_years'] ?? 0)];
        }
        // an agent / partner: what the quick form asked (cv_qual, cv_exp, cv_prev)
        $f = $table === 'agent' && $row !== null ? (is_array($row['fields'] ?? null) ? $row['fields'] : (json_decode((string) ($row['fields_json'] ?? ''), true) ?: [])) : [];
        if ($cv['edu'] === [] && isset($f['cv_qual'], self::EDU_LEVELS[(string) $f['cv_qual']])) {
            $cv['edu'][] = ['level' => (string) $f['cv_qual'], 'school' => '', 'year' => ''];
        }
        if ($cv['exp'] === [] && (($f['cv_prev'] ?? '') !== '' || ($f['cv_exp'] ?? '') !== '')) {
            $cv['exp'][] = ['org' => (string) ($f['cv_prev'] ?? ''), 'role' => '', 'years' => preg_replace('/[^\d.]/', '', (string) ($f['cv_exp'] ?? '')) ?? ''];
        }
        return $cv;
    }

    /** Clean a posted CV form. */
    public static function cleanCv(array $in, bool $org): array
    {
        $line = static fn($v, int $n): string => StaffApps::oneLine(Security::clean(strip_tags((string) ($v ?? '')), $n), $n);
        $cv = ['edu' => [], 'exp' => [], 'langs' => $line($in['langs'] ?? '', 120), 'skills' => $line($in['skills'] ?? '', 200),
               'about' => $line($in['about'] ?? '', 500), 'org' => []];
        $lv = (array) ($in['edu_level'] ?? []);
        foreach ($lv as $i => $l) {
            $l = (string) $l;
            $school = $line(($in['edu_school'] ?? [])[$i] ?? '', 120);
            $year = preg_replace('/\D/', '', (string) (($in['edu_year'] ?? [])[$i] ?? '')) ?? '';
            if (($l !== '' && isset(self::EDU_LEVELS[$l])) || $school !== '') {
                $cv['edu'][] = ['level' => isset(self::EDU_LEVELS[$l]) ? $l : 'other', 'school' => $school, 'year' => substr($year, 0, 4)];
            }
            if (count($cv['edu']) >= 5) {
                break;
            }
        }
        foreach ((array) ($in['exp_org'] ?? []) as $i => $o) {
            $o = $line($o, 120);
            $role = $line(($in['exp_role'] ?? [])[$i] ?? '', 80);
            $yrs = preg_replace('/[^\d.]/', '', (string) (($in['exp_years'] ?? [])[$i] ?? '')) ?? '';
            if ($o !== '' || $role !== '') {
                $cv['exp'][] = ['org' => $o, 'role' => $role, 'years' => substr($yrs, 0, 4)];
            }
            if (count($cv['exp']) >= 6) {
                break;
            }
        }
        if ($org) {
            foreach (self::ORG_FIELDS as $k => $_) {
                $v = $line(($in['org'] ?? [])[$k] ?? '', $k === 'services' ? 300 : 120);
                if ($v !== '') {
                    $cv['org'][$k] = $v;
                }
            }
        }
        return $cv;
    }

    /** Save a CV on the person's own row. */
    public static function saveCv(string $table, int $id, array $cv): void
    {
        $json = json_encode($cv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        if ($table === 'staff') {
            Database::update('staff_applications', ['cv_json' => $json], 'id = :id', ['id' => $id]);
            Logger::audit('team.cv', 'staff_application', StaffApps::refFor($id), null, null, 'CV saved at /team/');
        } else {
            Database::update('agent_applications', ['cv_json' => $json], 'id = :id', ['id' => $id]);
            Logger::audit('team.cv', 'agent_application', AgentApplications::refFor($id), null, null, 'CV saved at /team/');
        }
    }

    /** A CV as HTML (portal print view and the office's detail pages). */
    public static function cvHtml(array $cv, bool $org): string
    {
        $h = static fn($v): string => Security::e((string) $v);
        $out = '';
        if ($org && $cv['org'] !== []) {
            $out .= '<h3>Company profile · कम्पनी विवरण</h3><dl class="cv-dl">';
            foreach (self::ORG_FIELDS as $k => $lbl) {
                if (!empty($cv['org'][$k])) {
                    $out .= '<dt>' . $h($lbl) . '</dt><dd>' . $h($cv['org'][$k]) . '</dd>';
                }
            }
            $out .= '</dl>';
        }
        if ($cv['edu'] !== []) {
            $out .= '<h3>Education · शिक्षा</h3><ul class="cv-ul">';
            foreach ($cv['edu'] as $e) {
                $out .= '<li><b>' . $h(self::EDU_LEVELS[$e['level']] ?? $e['level']) . '</b>' . ($e['school'] !== '' ? ' — ' . $h($e['school']) : '') . ($e['year'] !== '' ? ' (' . $h($e['year']) . ')' : '') . '</li>';
            }
            $out .= '</ul>';
        }
        if ($cv['exp'] !== []) {
            $out .= '<h3>Experience · अनुभव</h3><ul class="cv-ul">';
            foreach ($cv['exp'] as $e) {
                $out .= '<li><b>' . $h($e['role'] !== '' ? $e['role'] : '—') . '</b>' . ($e['org'] !== '' ? ' — ' . $h($e['org']) : '') . ($e['years'] !== '' ? ' · ' . $h($e['years']) . ' yr' : '') . '</li>';
            }
            $out .= '</ul>';
        }
        foreach (['langs' => 'Languages · भाषा', 'skills' => 'Skills · सीप', 'about' => 'About · बारेमा'] as $k => $lbl) {
            if ($cv[$k] !== '') {
                $out .= '<h3>' . $h($lbl) . '</h3><p>' . $h($cv[$k]) . '</p>';
            }
        }
        return $out !== '' ? $out : '<p class="muted">No CV yet · अझै CV भरिएको छैन।</p>';
    }
}
