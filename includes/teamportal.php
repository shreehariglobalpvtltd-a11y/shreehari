<?php
/**
 * includes/teamportal.php — the team portal at /team/ (22 Oct 2026).
 *
 * One login for the three kinds of people who work with us —
 * Pvt. Ltd. partner companies, agents and S Hari staff — tied to the mobile
 * they registered with.
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
    public const RESET_FAILS = 10;
    public const SIGN_TRIES  = 6;

    private static ?bool $ready = null;

    /** Tests only: fn(string $intl, string $code): bool takes the reset code instead of WhatsApp. */
    public static ?Closure $deliverHook = null;

    private function __construct() {}

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

    public const COUNTRIES = ['91' => '🇮🇳 +91', '977' => '🇳🇵 +977'];

    public static function cc(mixed $raw): string
    {
        return (string) $raw === '977' ? '977' : '91';
    }

    private static function byCountry(array $targets, string $cc, string $phone10): array
    {
        if ($cc === '') {
            return $targets;
        }
        return array_values(array_filter($targets, static fn(array $t): bool => $t['intl'] === $cc . $phone10));
    }

    private static function otherCountry(string $intl, string $cc, string $phone10): bool
    {
        return $cc !== '' && $intl !== '' && $intl !== $cc . $phone10;
    }

    /* =================================================================
     *  Who has that number
     * ================================================================= */

    /**
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

    private static function staffIntl(string $phone10, array $s): string
    {
        $cc = countryDialCode((string) ($s['phone_country'] ?? ''));
        return $cc !== '' ? $cc . $phone10 : '';
    }

    private static function adminIntl(string $phone10, array $a): string
    {
        $id = (int) $a['id'];
        $raw = AgentApplications::available() ? (string) Database::scalar(
            "SELECT phone_raw FROM agent_applications WHERE admin_id = :a AND status = 'approved' AND phone = :p AND phone_raw IS NOT NULL ORDER BY id DESC LIMIT 1",
            ['a' => $id, 'p' => $phone10], '') : '';
        $cc = $raw !== '' ? countryDialCode(resolvePhoneCountry('', $raw)) : '';
        if ($cc === '') {
            try {
                $pc = preg_replace('/\D/', '', (string) Database::scalar('SELECT country_code FROM admin_profiles WHERE admin_id = :a', ['a' => $id], '')) ?? '';
                $cc = in_array($pc, ['91', '977'], true) ? $pc : '';
            } catch (Throwable $e) {
                $cc = '';
            }
        }
        if ($cc === '' && self::ready()) {
            $cc = countryDialCode((string) Database::scalar(
                "SELECT phone_country FROM staff_applications WHERE admin_id = :a AND phone = :p AND status = 'approved' ORDER BY id DESC LIMIT 1",
                ['a' => $id, 'p' => $phone10], ''));
        }
        if ($cc === '') {
            $cc = countryDialCode(resolvePhoneCountry('', (string) $a['phone']));
        }
        if ($cc === '' && preg_match('/^9[678]/', $phone10) !== 1) {
            $cc = '91';
        }
        return $cc !== '' ? $cc . $phone10 : '';
    }

    private static function staffBlocked(array $a, string $intl, string $phone10): bool
    {
        if (!self::ready()) {
            return false;
        }
        $rows = Database::fetchAll(
            "SELECT id, status, phone, phone_country, admin_id FROM staff_applications
              WHERE (admin_id = :a OR phone = :p) AND status IN ('approved','rejected','suspended','exited') ORDER BY id DESC",
            ['a' => (int) $a['id'], 'p' => $phone10]);
        foreach ($rows as $r) {
            if ((int) ($r['admin_id'] ?? 0) === (int) $a['id']) {
                return (string) $r['status'] !== 'approved';
            }
        }
        foreach ($rows as $r) {
            if ($intl === '' || (string) $r['status'] === 'rejected' || self::staffIntl((string) $r['phone'], $r) !== $intl) {
                continue;
            }
            return (string) $r['status'] !== 'approved';
        }
        return false;
    }

    private static function adminUsable(array $a, string $phone10): bool
    {
        return (int) $a['is_active'] === 1 && !self::staffBlocked($a, self::adminIntl($phone10, $a), $phone10);
    }

    private static function resetTargets(string $phone10): array
    {
        $acc = self::accounts($phone10);
        $groups = [];
        $add = static function (string $intl, string $kind, array $row) use (&$groups): void {
            foreach ($groups as $i => $g) {
                if ($g['intl'] === $intl) {
                    $groups[$i][$kind][] = $row;
                    return;
                }
            }
            $groups[] = ['intl' => $intl, 'admins' => [], 'staff' => []];
            $groups[count($groups) - 1][$kind][] = $row;
        };
        foreach ($acc['admins'] as $a) {
            $intl = (int) $a['is_active'] === 1 ? self::adminIntl($phone10, $a) : '';
            if ($intl === '' || self::staffBlocked($a, $intl, $phone10)) {
                continue;
            }
            $add($intl, 'admins', $a);
        }
        foreach ($acc['staff'] as $s) {
            $intl = (string) $s['status'] === 'approved' ? self::staffIntl($phone10, $s) : '';
            if ($intl !== '') {
                $add($intl, 'staff', $s);
            }
        }
        return $groups;
    }

    private static function target(array $targets, string $intl): ?array
    {
        foreach ($targets as $t) {
            if ($intl !== '' && $t['intl'] === $intl) {
                return $t;
            }
        }
        return null;
    }

    /* =================================================================
     *  Sign in
     * ================================================================= */

    /**
     * @return array{ok: bool, kind?: string, error?: string, first?: bool}
     */
    public static function login(string $phone10, string $password, string $cc = ''): array
    {
        $generic = ['ok' => false, 'error' => 'मोबाइल नम्बर वा पासवर्ड मिलेन — पहिलो पटक हो भने "पासवर्ड बनाउनुहोस् / बिर्सनुभयो?" थिच्नुहोस् · Mobile or password did not match. First time here? Tap "Set / forgot password".'];
        if ($phone10 === '' || $password === '') {
            return ['ok' => false, 'error' => 'मोबाइल नम्बर र पासवर्ड लेख्नुहोस् · Enter your mobile and password.'];
        }
        $ip = Security::clientIp();
        if (!Security::rateLimit('team_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            LoginLog::record('team:' . $phone10, 'locked');
            return ['ok' => false, 'error' => 'धेरै पटक गलत भयो — ' . LOGIN_LOCKOUT_MIN . ' मिनेटपछि फेरि प्रयास गर्नुहोस् · Too many tries, wait ' . LOGIN_LOCKOUT_MIN . ' minutes.'];
        }
        $acc = self::accounts($phone10);
        $acc['admins'] = array_values(array_filter($acc['admins'], static fn(array $a): bool => self::adminUsable($a, $phone10)));
        if ($cc !== '') {
            $acc['admins'] = array_values(array_filter($acc['admins'], static fn(array $a): bool => !self::otherCountry(self::adminIntl($phone10, $a), $cc, $phone10)));
            $acc['staff'] = array_values(array_filter($acc['staff'], static fn(array $s): bool => !self::otherCountry(self::staffIntl($phone10, $s), $cc, $phone10)));
        }
        $now = time();

        foreach ($acc['admins'] as $a) {
            if ((string) $a['password_hash'] === '') {
                continue;
            }
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
            if (!empty($s['locked_until']) && strtotime((string) $s['locked_until']) > $now) {
                continue;
            }
            if (Security::verifyPassword($password, (string) $s['password_hash'])) {
                self::openStaffSession((int) $s['id'], (string) $s['password_hash']);
                return ['ok' => true, 'kind' => 'staff'];
            }
        }

        foreach ($acc['admins'] as $a) {
            if ((string) $a['password_hash'] === '') {
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
        return $generic;
    }

    /* =================================================================
     *  Forgot / first password
     * ================================================================= */

    /**
     * @return array{ok: bool, error?: string, debugCode?: string}
     */
    public static function sendResetCode(string $phone10, string $cc = ''): array
    {
        if (!Security::rateLimit('team_reset_ip', Security::clientIp(), 8, 600) || !Security::rateLimit('team_reset_phone', $phone10, 5, 3600)) {
            return ['ok' => false, 'error' => 'धेरै पटक भयो — केही बेरपछि फेरि · Too many tries, please wait a while and try again.'];
        }
        $out = ['ok' => true];
        $t0 = microtime(true);
        $targets = self::byCountry(self::resetTargets($phone10), $cc, $phone10);
        if ($targets === []) {
            Logger::info('Team portal: reset asked for a number with no account that can reset', ['phone' => maskPhone($phone10)], 'otp');
            self::evenTime($t0, true);
            return $out;
        }
        if (self::resetLocked($phone10)) {
            Logger::warning('Team portal: reset code NOT sent, the number is locked after wrong codes', ['phone' => maskPhone($phone10)], 'otp');
            self::evenTime($t0, true);
            return $out;
        }
        $capH = max(1, Settings::getInt('otp_global_hourly_cap', 150));
        $capD = max(1, Settings::getInt('otp_global_daily_cap', 800));
        foreach ($targets as $t) {
            if (!Security::rateLimit('otp_send_global_h', 'global', $capH, 3600) || !Security::rateLimit('otp_send_global_d', 'global', $capD, 86400)) {
                Logger::warning('Team portal: reset code NOT sent, global OTP ceiling reached', ['phone' => maskPhone($phone10)], 'otp');
                break;
            }
            $res = Auth::issueOtp('team:' . $t['intl'], 'reset', 'whatsapp');
            if (!$res['ok']) {
                Logger::info('Team portal: reset code NOT issued: ' . (string) ($res['error'] ?? ''), ['phone' => maskPhone($t['intl'])], 'otp');
                continue;
            }
            $sent = self::deliverCode($t['intl'], (string) $res['code']);
            Logger::info('Team portal: reset code ' . ($sent ? 'sent' : 'NOT delivered'), ['phone' => maskPhone($t['intl'])], 'otp');
            if (APP_DEBUG && !isset($out['debugCode'])) {
                $out['debugCode'] = (string) $res['code'];
            }
        }
        self::evenTime($t0, false);
        return $out;
    }

    private static function evenTime(float $t0, bool $burnHash): void
    {
        if ($burnHash) {
            password_hash(bin2hex(random_bytes(4)), PASSWORD_BCRYPT);
        }
        $target = 0.9 + random_int(0, 300) / 1000;
        $left = $target - (microtime(true) - $t0);
        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }

    private static function deliverCode(string $intl, string $code): bool
    {
        if (self::$deliverHook !== null) {
            return (bool) (self::$deliverHook)($intl, $code);
        }
        require_once __DIR__ . '/notify.php';
        try {
            if (!class_exists('AiSensyAPI') && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
            }
            if (class_exists('AiSensyAPI') && AiSensyAPI::enabled()) {
                return (bool) AiSensyAPI::sendLoginOtp($intl, $code, '')['success'];
            }
            $text = Settings::getString('company_name', APP_NAME) . ' टिम पोर्टल — पासवर्ड बदल्ने कोड: ' . $code . ' (' . OTP_EXPIRY_MINUTES
                  . ' मिनेट सम्म मान्य)। यो कोड पासवर्ड बदल्नको लागि हो — अरूले मागे नपठाउनुहोस्।';
            return Notify::whatsapp($intl, $text, null, null, [], null, ['purpose' => 'otp']) === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function resetLocked(string $phone10): bool
    {
        $until = (string) Database::scalar("SELECT blocked_until FROM rate_limits WHERE bucket = 'team_reset_fail' AND identifier = :i", ['i' => $phone10], '');
        return $until !== '' && strtotime($until) > time();
    }

    private static function resetFailed(string $phone10): void
    {
        if (!Security::rateLimit('team_reset_fail', $phone10, self::RESET_FAILS - 1, 86400, 86400)) {
            Logger::warning('Team portal: reset by code locked for 24 h after repeated wrong codes', ['phone' => maskPhone($phone10), 'ip' => Security::clientIp()], 'otp');
            Logger::audit('team.reset_locked', 'team_portal', maskPhone($phone10), null, null,
                self::RESET_FAILS . ' wrong reset codes in a day: the code way is locked for 24 h');
        }
    }

    public static function startVerify(string $phone10, string $cc = ''): array
    {
        $ip = Security::clientIp();
        if (!Security::rateLimit('wa_verify_ip', $ip, 8, 600) || !Security::rateLimit('wa_verify_phone', $phone10, 4, 600)) {
            throw new TeamPortalError('धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.');
        }
        $targets = self::byCountry(self::resetTargets($phone10), $cc, $phone10);
        $intl = count($targets) === 1 ? $targets[0]['intl'] : '';
        $s = WaVerify::start('reset', $phone10, '', $ip, $intl);
        $_SESSION['team_reset']['wa'] = ['token' => $s['token'], 'url' => $s['url'], 'text' => $s['text'], 'intl' => $intl];
        return $s;
    }

    /**
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
        $targets = self::resetTargets($phone10);
        $proven = '';
        $how = '';
        $wa = $_SESSION['team_reset']['wa'] ?? null;
        if (is_array($wa) && (string) ($wa['intl'] ?? '') !== '' && self::target($targets, (string) $wa['intl']) !== null
            && WaVerify::consumeReset((string) ($wa['token'] ?? ''), (string) $wa['intl'])) {
            $proven = (string) $wa['intl'];
            $how = '"hi VERIFY"';
        }
        if ($proven === '') {
            if (trim($code) === '') {
                return ['ok' => false, 'error' => 'WhatsApp मा आएको ६ अङ्कको कोड लेख्नुहोस् (वा तलको "hi VERIFY") · Enter the 6-digit code from WhatsApp, or use "hi VERIFY" below.'];
            }
            if (self::resetLocked($phone10)) {
                return ['ok' => false, 'error' => 'धेरै पटक गलत कोड — २४ घण्टा कोडबाट पासवर्ड बदल्न मिल्दैन; तलको "hi VERIFY" वा कार्यालय · Too many wrong codes: code recovery is locked for 24 hours; use "hi VERIFY" below or call the office.'];
            }
            foreach ($targets as $t) {
                if (Auth::verifyOtp('team:' . $t['intl'], $code, 'reset')['ok']) {
                    $proven = $t['intl'];
                    $how = 'code';
                    break;
                }
            }
            if ($proven === '') {
                self::resetFailed($phone10);
                return ['ok' => false, 'error' => 'कोड मिलेन वा पुरानो भयो — चाहिए नयाँ कोड माग्नुहोस् · That code did not match or has expired. Ask for a new one if needed.'];
            }
        }
        $t = self::target($targets, $proven);
        $hash = Security::hashPassword($pw);
        $n = 0;
        $open = null;
        $twofa = false;
        foreach ($t['admins'] ?? [] as $a) {
            Database::update('admins', ['password_hash' => $hash, 'failed_logins' => 0, 'locked_until' => null, 'must_change_pw' => 0],
                'id = :id', ['id' => (int) $a['id']]);
            Auth::passwordChanged((int) $a['id'], 0, (string) $a['password_hash']);
            Logger::audit('team.password_reset', 'admin', (string) $a['id'], null, null, 'New password set at /team/ after a WhatsApp ' . $how . ' to ' . maskPhone($proven));
            $n++;
            $open ??= $a;
            $twofa = $twofa || Auth::admin2faEnabled((int) $a['id']);
        }
        $staffId = 0;
        foreach ($t['staff'] ?? [] as $s) {
            Database::update('staff_applications', ['password_hash' => $hash, 'failed_logins' => 0, 'locked_until' => null],
                'id = :id', ['id' => (int) $s['id']]);
            Logger::audit('team.password_reset', 'staff_application', StaffApps::refFor((int) $s['id']), null, null, 'New password set at /team/ after a WhatsApp ' . $how);
            $n++;
            $staffId = $staffId ?: (int) $s['id'];
        }
        unset($_SESSION['team_reset']['wa']);
        Database::run("DELETE FROM rate_limits WHERE bucket = 'team_reset_fail' AND identifier = :i", ['i' => $phone10]);
        if ($n === 0) {
            return ['ok' => false, 'error' => 'यो खाता बन्द छ — कार्यालयमा सम्पर्क गर्नुहोस् · This account is switched off. Please call the office.'];
        }
        if ($twofa) {
            return ['ok' => true, 'kind' => 'none', 'twofa' => true, 'count' => $n];
        }
        if ($open !== null) {
            Auth::teamOpenSession(['must_change_pw' => 0, 'password_hash' => $hash] + $open, true);
            self::closeStaffSession();
            return ['ok' => true, 'kind' => 'admin', 'count' => $n];
        }
        self::openStaffSession($staffId, $hash);
        return ['ok' => true, 'kind' => 'staff', 'count' => $n];
    }

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
            Auth::passwordChanged((int) $me['admin']['id'], 0, $cur);
        }
        if ($me['staff'] !== null && (string) $me['staff']['status'] === 'approved') {
            Database::update('staff_applications', ['password_hash' => $hash], 'id = :id', ['id' => (int) $me['staff']['id']]);
            Logger::audit('team.password_change', 'staff_application', (string) $me['staff']['ref'], null, null, 'Password changed at /team/');
            if (isset($_SESSION[self::SESSION]['staff_id']) && (int) $_SESSION[self::SESSION]['staff_id'] === (int) $me['staff']['id']) {
                $_SESSION[self::SESSION]['pw'] = self::pwStamp($hash);
                if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                    session_regenerate_id(true);
                }
            }
        }
    }

    /* =================================================================
     *  Sessions
     * ================================================================= */

    private static function pwStamp(string $hash): string
    {
        return substr(hash('sha256', 'shg-team|' . $hash), 0, 24);
    }

    private static function openStaffSession(int $staffId, string $hash): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION] = ['staff_id' => $staffId, 'at' => time(), 'seen' => time(), 'pw' => self::pwStamp($hash)];
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
        unset($_SESSION['wa_verified'], $_SESSION['wa_tokens'], $_SESSION['wa_tried'], $_SESSION['team_reset'], $_SESSION['staff_otp_ok'],
              $_SESSION['a4_mine'], $_SESSION['agr_webauthn']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function passkeyOwner(array $me): ?array
    {
        if ($me['admin'] !== null) {
            return ['admin', (int) $me['admin']['id']];
        }
        if ($me['staff'] !== null) {
            return ['staff', (int) $me['staff']['id']];
        }
        return null;
    }

    public const FACE_MIN_SCORE = 70;

    private static function candidates(string $phone10, string $cc): array
    {
        $acc = self::accounts($phone10);
        $acc['admins'] = Auth::adminsByPhone($phone10);
        $acc['admins'] = array_values(array_filter($acc['admins'], static fn(array $a): bool => self::adminUsable($a, $phone10)));
        if ($cc !== '') {
            $acc['admins'] = array_values(array_filter($acc['admins'], static fn(array $a): bool => !self::otherCountry(self::adminIntl($phone10, $a), $cc, $phone10)));
            $acc['staff']  = array_values(array_filter($acc['staff'], static fn(array $s): bool => !self::otherCountry(self::staffIntl($phone10, $s), $cc, $phone10)));
        }
        $out = [];
        $now = time();
        foreach ($acc['admins'] as $a) {
            if ((string) $a['password_hash'] === '' || (!empty($a['locked_until']) && strtotime((string) $a['locked_until']) > $now)) {
                continue;
            }
            $photo = null; $dob = ''; $idno = '';
            if (AgentApplications::available()) {
                $aid = (int) Database::scalar("SELECT id FROM agent_applications WHERE status = 'approved' AND admin_id = :a ORDER BY id DESC LIMIT 1", ['a' => (int) $a['id']], 0);
                $ag  = $aid > 0 ? AgentApplications::find($aid) : null;
                if ($ag !== null) {
                    $photo = is_string($ag['photo'] ?? null) && str_starts_with((string) $ag['photo'], 'data:image/') ? (string) $ag['photo'] : null;
                    $dob   = (string) ($ag['fields']['p_dob'] ?? '');
                    $idno  = (string) ($ag['fields']['p_idno'] ?? '');
                }
            }
            if ($photo === null && self::ready()) {
                $st = self::staffOfAccount((int) $a['id'], (string) ($a['phone'] ?? ''), $phone10);
                if ($st !== null && !empty($st['photo_path']) && ($abs = StaffApps::absPath((string) $st['photo_path'])) !== null) {
                    $photo = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($abs));
                    $dob  = $dob !== '' ? $dob : (string) ($st['dob'] ?? '');
                    $idno = $idno !== '' ? $idno : (string) ($st['id_number'] ?? '');
                }
            }
            $out[] = ['kind' => 'admin', 'row' => $a, 'photo' => $photo, 'dob' => $dob, 'idno' => $idno, 'label' => (string) $a['username']];
        }
        foreach ($acc['staff'] as $s) {
            if ((string) $s['status'] !== 'approved' || empty($s['password_hash']) || (!empty($s['locked_until']) && strtotime((string) $s['locked_until']) > $now)) {
                continue;
            }
            $full = StaffApps::find((int) $s['id']);
            if ($full === null) {
                continue;
            }
            $photo = null;
            if (!empty($full['photo_path']) && ($abs = StaffApps::absPath((string) $full['photo_path'])) !== null) {
                $photo = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($abs));
            }
            $out[] = ['kind' => 'staff', 'row' => $full, 'photo' => $photo, 'dob' => (string) ($full['dob'] ?? ''), 'idno' => (string) ($full['id_number'] ?? ''), 'label' => StaffApps::refFor((int) $s['id'])];
        }
        return $out;
    }

    private static function openCandidate(array $c, string $how): array
    {
        if ($c['kind'] === 'admin') {
            if (Auth::admin2faEnabled((int) $c['row']['id'])) {
                return ['ok' => false, 'error' => 'यो खातामा 2FA छ — username, पासवर्ड र कोडले भित्र जानुहोस् · This account uses 2FA: sign in with the username, password and code.'];
            }
            try {
                Auth::openAdminWithoutPassword($c['row'], $how);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'error' => 'यो खाता अहिले खुल्दैन — कार्यालयमा सोध्नुहोस् · This account cannot sign in now.'];
            }
            self::closeStaffSession();
            Logger::audit('team.login.' . $how, 'admin', (string) $c['row']['id'], null, null, 'Team portal: ' . $how);
            return ['ok' => true, 'kind' => 'admin'];
        }
        self::openStaffSession((int) $c['row']['id'], (string) $c['row']['password_hash']);
        Logger::audit('team.login.' . $how, 'staff_application', $c['label'], null, null, 'Team portal: ' . $how);
        return ['ok' => true, 'kind' => 'staff'];
    }

    public static function faceLogin(string $phone10, string $cc, array $frames): array
    {
        return ['ok'=>false,'error'=>'Use a registered Passkey, password or verified WhatsApp recovery. Selfie and document details are for KYC review only.'];

        require_once __DIR__ . '/facecheck.php';
        $no = ['ok' => false, 'error' => 'अनुहार मिलेन — उज्यालोमा फेरि गर्नुहोस्, वा जन्म मिति + ID नम्बर / पासवर्ड प्रयोग गर्नुहोस् · Face not recognised: try again in good light, or use birth date + ID / the password.'];
        $cands = array_values(array_filter(self::candidates($phone10, $cc), static fn(array $c): bool => $c['photo'] !== null));
        if ($cands === []) {
            Logger::warning('Team portal: face login with no photo on file', ['phone' => maskPhone($phone10)]);
            return $no;
        }
        $best = null;
        foreach (array_slice($cands, 0, 2) as $c) {
            $ref = FaceCheck::decode($c['photo'], 900_000);
            if ($ref === null) {
                continue;
            }
            $r = FaceCheck::check($frames, $ref, 'photo');
            if ($r['status'] === 'unavailable') {
                return ['ok' => false, 'error' => 'अहिले अनुहार जाँच हुन सकेन — जन्म मिति + ID नम्बर वा पासवर्ड प्रयोग गर्नुहोस् · Face check is not available right now; use birth date + ID or the password.'];
            }
            Logger::info('Team portal: face login', ['live' => $r['live'], 'match' => $r['match'], 'score' => $r['score'], 'phone' => maskPhone($phone10)], 'kyc');
            if ($r['live'] === true && $r['match'] === 'yes' && $r['score'] >= self::FACE_MIN_SCORE) {
                $best = $c;
                break;
            }
        }
        if ($best === null) {
            return $no;
        }
        return self::openCandidate($best, 'face');
    }

    public static function factsLogin(string $phone10, string $cc, string $dob, string $idno): array
    {
        return ['ok'=>false,'error'=>'Use a registered Passkey, password or verified WhatsApp recovery. Selfie and document details are for KYC review only.'];

        require_once __DIR__ . '/docverify.php';
        $no   = ['ok' => false, 'error' => 'मोबाइल, जन्म मिति वा ID नम्बर मिलेन · Mobile, birth date or ID number did not match.'];
        $want = DocVerify::fold(DocVerify::normNumber($idno));
        $dob  = trim($dob);
        if (strlen($want) < 5) {
            return ['ok' => false, 'error' => 'ID / नागरिकता / आधार नम्बर पूरै लेख्नुहोस् · Enter the whole ID number.'];
        }
        foreach (self::candidates($phone10, $cc) as $c) {
            $have = DocVerify::fold(DocVerify::normNumber($c['idno']));
            if ($have === '' || !hash_equals($have, $want)) {
                continue;
            }
            $recDob = substr((string) $c['dob'], 0, 10);
            if ($recDob !== '' && $recDob !== '0000-00-00') {
                if ($dob === '' || !hash_equals($recDob, $dob)) {
                    continue;
                }
            }
            return self::openCandidate($c, 'facts');
        }
        Logger::warning('Team portal: facts login failed', ['phone' => maskPhone($phone10)]);
        return $no;
    }

    public static function passkeyLogin(array $row): array
    {
        $kind = (string) $row['owner_kind'];
        $id   = (int) $row['owner_id'];
        $phone10 = (string) $row['phone10'];
        $no = ['ok' => false, 'error' => 'यो खाता अहिले खुल्दैन — कार्यालयमा सोध्नुहोस् · This account cannot sign in now. Please ask the office.'];
        if ($kind !== 'admin' && $kind !== 'staff') {
            return $no;
        }
        if ($kind === 'admin') {
            $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
            if ($a === null || (int) $a['is_active'] !== 1 || (string) $a['password_hash'] === '' || !self::adminUsable($a, $phone10)) {
                return $no;
            }
            if (!empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time()) {
                return $no;
            }
            if (Auth::admin2faEnabled($id)) {
                return ['ok' => false, 'error' => 'यो खातामा 2FA छ — username, पासवर्ड र कोडले भित्र जानुहोस् · This account uses 2FA: sign in with the username, password and code.'];
            }
            try {
                Auth::openAdminWithoutPassword($a, 'passkey');
            } catch (RuntimeException $e) {
                return $no;
            }
            self::closeStaffSession();
            Logger::audit('team.login.passkey', 'admin', (string) $id, null, null, 'Team portal: passkey (Face ID / fingerprint)');
            return ['ok' => true, 'kind' => 'admin'];
        }
        $s = StaffApps::find($id);
        if ($s === null || (string) $s['status'] !== 'approved' || empty($s['password_hash'])) {
            return $no;
        }
        if (!empty($s['locked_until']) && strtotime((string) $s['locked_until']) > time()) {
            return $no;
        }
        self::openStaffSession($id, (string) $s['password_hash']);
        Logger::audit('team.login.passkey', 'staff_application', StaffApps::refFor($id), null, null, 'Team portal: passkey (Face ID / fingerprint)');
        return ['ok' => true, 'kind' => 'staff'];
    }

    public static function current(): ?array
    {
        $admin = Auth::admin();
        $staff = null;
        $agent = null;
        if ($admin !== null) {
            $row = Database::fetch('SELECT id, phone, full_name, role FROM admins WHERE id = :i', ['i' => (int) $admin['id']]);
            $phone10 = self::phone10((string) ($row['phone'] ?? ''));
            if (self::ready()) {
                $staff = self::staffOfAccount((int) $admin['id'], (string) ($row['phone'] ?? ''), $phone10);
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
        if ($staff === null || (string) $staff['status'] !== 'approved' || empty($staff['password_hash'])
            || !hash_equals((string) ($t['pw'] ?? ''), self::pwStamp((string) $staff['password_hash']))) {
            self::closeStaffSession();
            return null;
        }
        $_SESSION[self::SESSION]['seen'] = time();
        return ['via' => 'staff', 'admin' => null, 'staff' => $staff, 'agent' => null, 'phone10' => (string) $staff['phone'],
                'name' => (string) $staff['full_name']];
    }

    private static function staffOfAccount(int $adminId, string $phoneRaw, string $phone10): ?array
    {
        $sid = (int) Database::scalar(
            "SELECT id FROM staff_applications WHERE admin_id = :a AND status IN ('approved','suspended','exited') ORDER BY id DESC LIMIT 1",
            ['a' => $adminId], 0);
        if ($sid === 0 && $phone10 !== '') {
            $intl = self::adminIntl($phone10, ['id' => $adminId, 'phone' => $phoneRaw]);
            foreach ($intl === '' ? [] : Database::fetchAll(
                "SELECT id, phone, phone_country FROM staff_applications
                  WHERE phone = :p AND admin_id IS NULL AND status IN ('approved','suspended') ORDER BY id DESC",
                ['p' => $phone10]) as $r) {
                if (self::staffIntl($phone10, $r) === $intl) {
                    $sid = (int) $r['id'];
                    break;
                }
            }
        }
        return $sid > 0 ? StaffApps::find($sid) : null;
    }

    public static function sellUrl(?array $admin): ?string
    {
        return match ((string) ($admin['role'] ?? '')) {
            'agent'   => '/admin/agent.php',
            'counter' => '/admin/bookings.php',
            'superadmin', 'manager' => '/admin/smartbook.php',
            default   => null,
        };
    }

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
     *  Agreements, signed in the portal
     * ================================================================= */

    public const AGREEMENTS = [
        'staff'   => ['title' => 'Staff agreement · कर्मचारी सम्झौता', 'version' => StaffApps::AGREEMENT_VERSION, 'url' => '/agreements/staff-agreement.html'],
        'agent'   => ['title' => 'Individual agent / connector agreement · एजेन्ट सम्झौता', 'version' => '2.3', 'url' => '/agreements/agent-agreement-%s.html'],
        'partner' => ['title' => 'Organization / Pvt. Ltd. partner agreement · साझेदार सम्झौता', 'version' => '2.2', 'url' => '/agreements/partner-agreement-%s.html'],
    ];

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

    public static function lastSign(array $ag): ?array
    {
        return Database::fetch(
            "SELECT * FROM agreement_signatures WHERE doc = :d AND party_type = :t AND party_id = :i AND version = :v ORDER BY id DESC LIMIT 1",
            ['d' => $ag['doc'], 't' => $ag['party'], 'i' => $ag['id'], 'v' => $ag['version']]
        );
    }

    public static function termSign(array $ag): ?array
    {
        $last = self::lastSign($ag);
        if ($last === null || $ag['party'] !== 'agent') {
            return $last;
        }
        $app = AgentApplications::find((int) $ag['id']);
        $st = (string) ($app['agreement_status'] ?? 'none');
        $start = match ($st) {
            'signed', 'expired' => (string) ($app['agreement_signed_at'] ?? ''),
            'sent'              => (string) ($app['reviewed_at'] ?? ''),
            default             => '',
        };
        if ($start > date('Y-m-d H:i:s')) {
            $start = date('Y-m-d');
        }
        return $start !== '' && (string) $last['signed_at'] < $start ? null : $last;
    }

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
        $who = $ag['party'] . ':' . $ag['id'];
        if (!Security::rateLimit('team_sign', $who, self::SIGN_TRIES, 3600)) {
            throw new TeamPortalError('धेरै पटक भयो — एक घण्टापछि फेरि · Too many tries, please wait an hour.');
        }
        $lock = 'shg_team_sign_' . $ag['party'] . '_' . (int) $ag['id'];
        if ((int) Database::scalar('SELECT GET_LOCK(:k, 5)', ['k' => $lock], 0) !== 1) {
            throw new TeamPortalError('सही सेभ हुँदैछ — केही बेरपछि पेज खोल्नुहोस् · Your signature is being saved.');
        }
        try {
            if (self::termSign($ag) !== null) {
                throw new TeamPortalError('यो सम्झौतामा सही भइसक्यो · This agreement is already signed for this term.');
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
            $renew = false;
            if ($ag['party'] === 'agent') {
                $st = (string) (AgentApplications::find((int) $ag['id'])['agreement_status'] ?? 'none');
                if (in_array($st, ['none', 'sent'], true)) {
                    AgentApplications::update($ag['id'], ['agreement_status' => 'signed', 'agreement_signed_at' => date('Y-m-d')], (int) ($me['admin']['id'] ?? 0));
                } else {
                    $renew = $st === 'expired';
                }
            }
        } finally {
            Database::scalar('SELECT RELEASE_LOCK(:k)', ['k' => $lock], 0);
        }
        Logger::audit('team.agreement_signed', $ag['party'] === 'agent' ? 'agent_application' : 'staff_application', $ag['ref'], null,
            ['doc' => $ag['doc'], 'version' => $ag['version'], 'sign_id' => $sid] + ($renew ? ['office' => 'expired, to renew'] : []),
            'Agreement signed in the team portal');
        return $ag + ['sign_id' => $sid, 'renew' => $renew];
    }

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

    public const STAFF_EDIT = [
        'full_name' => 120, 'email' => 160, 'whatsapp' => 15, 'address_perm' => 300, 'address_current' => 300,
        'district' => 80, 'province' => 80, 'emergency_contact_name' => 80, 'emergency_contact_phone' => 15,
        'emergency_relation' => 40, 'bank_name' => 80, 'bank_account' => 40, 'bank_holder' => 80, 'bank_branch' => 80,
        'bank_ifsc_or_swift' => 20,
    ];
    public const KYC = ['none' => 'Not given', 'submitted' => 'Submitted - to check', 'verified' => '✅ Verified (originals seen)', 'rejected' => '❌ Rejected'];

    public static function kycReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = StaffApps::available() && (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_applications' AND COLUMN_NAME IN ('kyc_status','kyc_verified_by','kyc_verified_at')",
                    [], 0) === 3;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function officeEditStaff(int $id, array $in, int $by): array
    {
        $r = StaffApps::find($id);
        if ($r === null) {
            throw new TeamPortalError('That application is not there.');
        }
        $set = [];
        foreach (self::STAFF_EDIT as $k => $max) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = in_array($k, ['whatsapp', 'emergency_contact_phone'], true)
                ? substr(preg_replace('/\D/', '', (string) $in[$k]) ?? '', 0, $max)
                : StaffApps::oneLine(Security::clean(strip_tags((string) $in[$k]), $max), $max);
            if ($k === 'email' && $v !== '') {
                $e = Security::email($v);
                if ($e === '') {
                    throw new TeamPortalError('Enter a valid email address.');
                }
                $v = strtolower($e);
            }
            if ($k === 'bank_ifsc_or_swift') {
                $v = strtoupper(str_replace(' ', '', $v));
            }
            if ($k === 'full_name' && mb_strlen($v) < 2) {
                throw new TeamPortalError('The name cannot be empty.');
            }
            if ($v !== (string) ($r[$k] ?? '')) {
                $set[$k] = $v !== '' ? $v : null;
            }
        }
        if (isset($in['phone']) && trim((string) $in['phone']) !== '') {
            $p = self::phone10((string) $in['phone']);
            if ($p === '') {
                throw new TeamPortalError('Enter a 10-digit mobile number.');
            }
            if ($p !== (string) $r['phone']) {
                if (Database::exists('SELECT 1 FROM staff_applications WHERE phone = :p AND id <> :i', ['p' => $p, 'i' => $id])) {
                    throw new TeamPortalError('Another staff application already has that mobile.');
                }
                $set['phone'] = $p;
                $set['phone_verified'] = 0;
            }
        }
        if (isset($in['phone_country']) && in_array((string) $in['phone_country'], ['IN', 'NP'], true) && (string) $in['phone_country'] !== (string) $r['phone_country']) {
            $set['phone_country'] = (string) $in['phone_country'];
        }
        if ($set === []) {
            return [];
        }
        Database::update('staff_applications', $set, 'id = :id', ['id' => $id]);
        $changed = array_keys($set);
        Logger::audit('staff_app.edit', 'staff_application', (string) $r['ref'], null, ['changed' => implode(', ', $changed)], 'Details corrected by admin #' . $by);
        return $changed;
    }

    public static function officeKyc(int $id, string $status, int $by): void
    {
        $r = StaffApps::find($id);
        if ($r === null || !self::kycReady()) {
            throw new TeamPortalError('That application is not there.');
        }
        if (!isset(self::KYC[$status])) {
            throw new TeamPortalError('Pick a KYC status.');
        }
        Database::update('staff_applications', [
            'kyc_status'      => $status,
            'kyc_verified_by' => $status === 'verified' || $status === 'rejected' ? ($by > 0 ? $by : null) : null,
            'kyc_verified_at' => $status === 'verified' || $status === 'rejected' ? date('Y-m-d H:i:s') : null,
        ], 'id = :id', ['id' => $id]);
        Logger::audit('staff_app.kyc', 'staff_application', (string) $r['ref'], ['kyc_status' => $r['kyc_status'] ?? null], ['kyc_status' => $status], 'KYC set by admin #' . $by);
    }

    public static function officeSetStaffPassword(int $id, string $pw, int $by): void
    {
        $r = StaffApps::find($id);
        if ($r === null || !self::ready()) {
            throw new TeamPortalError('That application is not there.');
        }
        if ((string) $r['status'] !== 'approved') {
            throw new TeamPortalError('Only an approved staff member signs in to the team portal.');
        }
        if (mb_strlen($pw) < self::PW_MIN) {
            throw new TeamPortalError('The password must be at least ' . self::PW_MIN . ' characters.');
        }
        if (preg_match('/^\d+$/', $pw) === 1 && str_contains((string) $r['phone'], $pw)) {
            throw new TeamPortalError('Do not use the mobile number as the password.');
        }
        Database::update('staff_applications', ['password_hash' => Security::hashPassword($pw), 'failed_logins' => 0, 'locked_until' => null],
            'id = :id', ['id' => $id]);
        Logger::audit('staff_app.password_set', 'staff_application', (string) $r['ref'], null, null, 'Team portal password set by admin #' . $by);
    }

    public static function makeCounterLogin(int $staffId, string $pw, int $by): array
    {
        $r = StaffApps::find($staffId);
        if ($r === null || !self::ready()) {
            throw new TeamPortalError('That application is not there.');
        }
        if ((string) $r['status'] !== 'approved') {
            throw new TeamPortalError('Approve the application first.');
        }
        if (!empty($r['admin_id'])) {
            $a = Database::fetch('SELECT id, username FROM admins WHERE id = :i', ['i' => (int) $r['admin_id']]);
            if ($a !== null) {
                return ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'made' => false];
            }
        }
        $p10 = self::phone10((string) $r['phone']);
        if ($p10 === '') {
            throw new TeamPortalError('This application has no 10-digit mobile for a login.');
        }
        $have = Auth::teamAccounts($p10)[0] ?? null;
        if ($have !== null) {
            self::linkAccount($staffId, (string) $have['username'], $by);
            return ['id' => (int) $have['id'], 'username' => (string) $have['username'], 'made' => false];
        }
        if ($pw !== '' && mb_strlen($pw) < self::PW_MIN) {
            throw new TeamPortalError('The password must be at least ' . self::PW_MIN . ' characters.');
        }
        $user = $p10;
        for ($i = 2; Database::exists('SELECT 1 FROM admins WHERE username = :u LIMIT 1', ['u' => $user]); $i++) {
            $user = $p10 . '-' . $i;
        }
        $cc  = (string) ($r['phone_country'] ?? '') === 'IN' ? '+91' : ((string) ($r['phone_country'] ?? '') === 'NP' ? '+977' : '');
        $id  = (int) Database::insert('admins', [
            'username'       => $user,
            'password_hash'  => Security::hashPassword($pw !== '' ? $pw : bin2hex(random_bytes(24))),
            'full_name'      => (string) $r['full_name'],
            'email'          => null,
            'phone'          => $cc . $p10,
            'role'           => 'counter',
            'is_active'      => 1,
            'must_change_pw' => $pw !== '' ? 1 : 0,
        ]);
        Database::update('staff_applications', ['admin_id' => $id], 'id = :id', ['id' => $staffId]);
        Logger::audit('staff.create', 'admin', $user, null, ['role' => 'counter', 'from' => (string) $r['ref']],
            'counter login made on approving the staff application (by admin #' . $by . ')');
        return ['id' => $id, 'username' => $user, 'made' => true];
    }

    public static function clearPassword(int $staffId, int $by): void
    {
        $r = StaffApps::find($staffId);
        if ($r === null || !self::ready()) {
            throw new TeamPortalError('That application is not there.');
        }
        Database::update('staff_applications', ['password_hash' => null, 'failed_logins' => 0, 'locked_until' => null], 'id = :id', ['id' => $staffId]);
        Logger::audit('staff_app.password_clear', 'staff_application', (string) $r['ref'], null, null, 'Team portal password cleared by admin #' . $by);
    }

    public static function loginLine(array $staff): string
    {
        if (!self::ready()) {
            return '';
        }
        $acc = !empty($staff['admin_id']) ? Database::fetch('SELECT username, role, is_active FROM admins WHERE id = :i', ['i' => (int) $staff['admin_id']]) : null;
        $bits = [!empty($staff['password_hash']) ? '🔑 password set' : '⚪ no password yet'];
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

    public const EDU_LEVELS = AgentApplications::CV_QUALS;
    public const ORG_FIELDS = [
        'reg_no' => 'Company registration No. · दर्ता नं.', 'pan' => 'PAN / VAT / GST', 'est' => 'Established (year) · स्थापना वर्ष',
        'director' => 'Director / owner · सञ्चालक', 'staff' => 'Staff count · कर्मचारी संख्या', 'branches' => 'Offices / branches · शाखा',
        'services' => 'Services · सेवाहरू',
    ];

    public static function cv(?array $row, string $table): array
    {
        $cv = json_decode((string) ($row['cv_json'] ?? ''), true);
        $cv = is_array($cv) ? $cv : [];
        $cv += ['edu' => [], 'exp' => [], 'langs' => '', 'skills' => '', 'about' => '', 'org' => [], 'links' => []];
        if ($table === 'staff' && $row !== null && $cv['edu'] === [] && !empty($row['qualification'])) {
            $cv['edu'][] = ['level' => (string) $row['qualification'], 'school' => '', 'year' => ''];
        }
        if ($table === 'staff' && $row !== null && $cv['exp'] === [] && (!empty($row['prev_employer']) || (int) ($row['experience_years'] ?? 0) > 0)) {
            $cv['exp'][] = ['org' => (string) ($row['prev_employer'] ?? ''), 'role' => (string) ($row['prev_role'] ?? ''), 'years' => (string) (int) ($row['experience_years'] ?? 0)];
        }
        $f = $table === 'agent' && $row !== null ? (is_array($row['fields'] ?? null) ? $row['fields'] : (json_decode((string) ($row['fields_json'] ?? ''), true) ?: [])) : [];
        if ($cv['edu'] === [] && isset($f['cv_qual'], self::EDU_LEVELS[(string) $f['cv_qual']])) {
            $cv['edu'][] = ['level' => (string) $f['cv_qual'], 'school' => '', 'year' => ''];
        }
        if ($cv['exp'] === [] && (($f['cv_prev'] ?? '') !== '' || ($f['cv_exp'] ?? '') !== '')) {
            $cv['exp'][] = ['org' => (string) ($f['cv_prev'] ?? ''), 'role' => '', 'years' => preg_replace('/[^\d.]/', '', (string) ($f['cv_exp'] ?? '')) ?? ''];
        }
        return $cv;
    }

    public static function cleanCv(array $in, bool $org): array
    {
        $line = static fn($v, int $n): string => StaffApps::oneLine(Security::clean(strip_tags((string) ($v ?? '')), $n), $n);
        $cv = ['edu' => [], 'exp' => [], 'langs' => $line($in['langs'] ?? '', 120), 'skills' => $line($in['skills'] ?? '', 200),
               'about' => $line($in['about'] ?? '', 500), 'org' => [], 'links' => []];
        $fb = trim((string) ($in['facebook'] ?? ''));
        if ($fb !== '' && preg_match('~^(https?://)?((www|m)\.)?(facebook\.com|fb\.com|fb\.me)/~i', $fb) === 1) {
            $fb = 'https://' . preg_replace('~^https?://~i', '', $fb);
            if (preg_match('~^https://(www\.|m\.)?(facebook\.com|fb\.com|fb\.me)/[\w./?=&%-]{1,120}$~i', $fb) === 1) {
                $cv['links']['facebook'] = $fb;
            }
        }
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
        if (!empty($cv['links']['facebook'])) {
            $out .= '<h3>Links</h3><p><a href="' . $h($cv['links']['facebook']) . '" target="_blank" rel="noopener nofollow">' . $h($cv['links']['facebook']) . '</a></p>';
        }
        return $out !== '' ? $out : '<p class="muted">No CV yet · अझै CV भरिएको छैन।</p>';
    }
}
