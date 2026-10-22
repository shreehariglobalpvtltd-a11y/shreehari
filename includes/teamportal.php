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
 *
 * 22 Oct 2026 (review) — the reset is bound to the FULL number:
 *   - every account has its own WhatsApp number, from what the office typed
 *     with a country (adminIntl / staff phone_country), never guessed: a bare
 *     98XXXXXXXX is a real number in India AND Nepal. Unknown = no code;
 *   - the code is issued per full number ('team:<intl>') and a "hi VERIFY"
 *     is a 'reset' token made here for that number (WaVerify::consumeReset),
 *     never a form's proof; only the accounts on the proven full number
 *     change;
 *   - switched-off accounts, suspended / exited staff and the selling
 *     account linked to them are left out; a 2FA account gets no session;
 *   - "forgot" answers the same for every number (no list of who is in the
 *     team), with budgets per IP and per number; 10 wrong codes in a day
 *     lock the code way for 24 h;
 *   - a new or changed password ends the other staff-portal sessions;
 *     signing out forgets this browser's WhatsApp proofs.
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
    public const RESET_FAILS = 10;     // 22 Oct 2026 (review): wrong reset codes per number in a day, then 24 h without the code way

    private static ?bool $ready = null;

    /** Tests only: fn(string $intl, string $code): bool takes the reset code instead of WhatsApp. */
    public static ?Closure $deliverHook = null;

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
     * stranger). A staff card: its country picker (an ENUM, always set).
     */
    private static function staffIntl(string $phone10, array $s): string
    {
        $cc = countryDialCode((string) ($s['phone_country'] ?? ''));
        return $cc !== '' ? $cc . $phone10 : '';
    }

    /**
     * An agent / counter account's full WhatsApp number, only from what the
     * office TYPED with a country (22 Oct 2026 review, #22: a bare number used
     * to become +91, so a Nepali agent's code reached a stranger in India):
     *   1. the approved website card's phone_raw ("+977 …"; the 19 Oct import
     *      put "91" in front of Nepali numbers and the card is what the office
     *      corrects);
     *   2. the agent profile's country (admin_profiles.country_code 91 / 977);
     *   3. the approved staff card linked to the account (its country picker);
     *   4. a +91 / +977 typed in the account's own mobile;
     *   5. a bare number outside Nepal's mobile ranges (96 / 97 / 98…) can
     *      only be Indian.
     * '' = unknown: no code and no "hi VERIFY" for it — the office adds the
     * country to the number first.
     */
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
                $cc = '';   // an older database without the column
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

    /**
     * 22 Oct 2026 (review, #2): a selling account whose staff card is
     * suspended / exited / rejected is not for self-service — the card linked
     * to the account (admin_id) decides; without a link, the newest staff card
     * on the same FULL number does. A re-hired person's newer approved card
     * clears it.
     */
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
                continue;   // a refused application says nothing about an agent account
            }
            return (string) $r['status'] !== 'approved';
        }
        return false;
    }

    /** An agent / counter row that may use the team door at all (active, staff card not stopped). */
    private static function adminUsable(array $a, string $phone10): bool
    {
        return (int) $a['is_active'] === 1 && !self::staffBlocked($a, self::adminIntl($phone10, $a), $phone10);
    }

    /**
     * Where a reset may go: the accounts that can reset, grouped by their own
     * full WhatsApp number (one group per person; two only when two people in
     * two countries share the 10 digits). Accounts with no known country are
     * left out.
     *
     * @return list<array{intl: string, admins: list<array<string, mixed>>, staff: list<array<string, mixed>>}>
     */
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

    /** The resetTargets() group of one full number, or null. */
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
     *         kind: 'admin' (staff session opened) | 'staff' (portal session)
     */
    public static function login(string $phone10, string $password): array
    {
        // 22 Oct 2026 (review): one answer for "no such number", "no password yet" and "wrong password" (no list of who is in the team)
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
        // 22 Oct 2026 (review, #2): a selling account whose staff card is suspended / exited is not opened here either
        $acc['admins'] = array_values(array_filter($acc['admins'], static fn(array $a): bool => self::adminUsable($a, $phone10)));
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
                self::openStaffSession((int) $s['id']);
                return ['ok' => true, 'kind' => 'staff'];
            }
        }

        // Nothing matched: count a failure on every account with a password on this number.
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
     * Send the reset code by WhatsApp — one code per full number that has an
     * account which can reset ('team:<intl>'), to that number only.
     *
     * 22 Oct 2026 (review, #4/#20): the answer is the same for a team member,
     * a switched-off account and a number we do not know — {ok: true}, or the
     * "too many tries" budget (per IP and per number, checked BEFORE the
     * number is looked up). Whether WhatsApp delivered is logged, never shown.
     *
     * @return array{ok: bool, error?: string, debugCode?: string}
     */
    public static function sendResetCode(string $phone10): array
    {
        if (!Security::rateLimit('team_reset_ip', Security::clientIp(), 8, 600) || !Security::rateLimit('team_reset_phone', $phone10, 5, 3600)) {
            return ['ok' => false, 'error' => 'धेरै पटक भयो — केही बेरपछि फेरि · Too many tries, please wait a while and try again.'];
        }
        $out = ['ok' => true];
        $targets = self::resetTargets($phone10);
        if ($targets === []) {
            Logger::info('Team portal: reset asked for a number with no account that can reset (or no country on it)', ['phone' => maskPhone($phone10)], 'otp');
            return $out;
        }
        if (self::resetLocked($phone10)) {
            Logger::warning('Team portal: reset code NOT sent, the number is locked after wrong codes', ['phone' => maskPhone($phone10)], 'otp');
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
                $out['debugCode'] = (string) $res['code'];   // a dev box only — never in production
            }
        }
        return $out;
    }

    /** The reset code to one full number. True only when WhatsApp took it (a wa.me fallback is not a delivery). */
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

    /** 22 Oct 2026 (review, #12): RESET_FAILS wrong codes in a day lock the code way for this number for 24 h. */
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
                self::RESET_FAILS . ' wrong reset codes in a day: the code way is locked for 24 h (hi VERIFY and the office still work)');
        }
    }

    /**
     * "hi VERIFY …" for a reset: the token + the wa.me link (WaVerify,
     * purpose 'reset'), kept with the account's full number in
     * $_SESSION['team_reset']['wa'] — the only place a reset token comes from.
     * Same answer for every number: when no account here can reset (or two
     * people in two countries share the digits) the token is bound to no
     * number, so nobody can prove it.
     */
    public static function startVerify(string $phone10): array
    {
        $ip = Security::clientIp();
        if (!Security::rateLimit('wa_verify_ip', $ip, 8, 600) || !Security::rateLimit('wa_verify_phone', $phone10, 4, 600)) {
            throw new TeamPortalError('धेरै पटक भयो — १० मिनेटपछि फेरि · Too many tries, wait 10 minutes.');
        }
        $targets = self::resetTargets($phone10);
        $intl = count($targets) === 1 ? $targets[0]['intl'] : '';
        $s = WaVerify::start('reset', $phone10, '', $ip, $intl);
        $_SESSION['team_reset']['wa'] = ['token' => $s['token'], 'url' => $s['url'], 'text' => $s['text'], 'intl' => $intl];
        return $s;
    }

    /**
     * The proven full number sets a new password on its accounts, then signs
     * in. Proof = the WhatsApp code sent to that number, or the "hi VERIFY"
     * token startVerify made in this session (WaVerify::consumeReset) — never
     * a form's proof.
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
                return ['ok' => false, 'error' => 'धेरै पटक गलत कोड — २४ घण्टा कोडबाट पासवर्ड बदल्न मिल्दैन; तलको "hi VERIFY" वा कार्यालय · Too many wrong codes: the code way is locked for 24 hours. Use "hi VERIFY" below or call the office.'];
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
                // one answer whether or not the number has an account (no oracle in "no code" vs "wrong code")
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
            Logger::audit('team.password_reset', 'admin', (string) $a['id'], null, null, 'New password set at /team/ after a WhatsApp ' . $how . ' to ' . maskPhone($proven));
            $n++;
            $open ??= $a;   // the first account by id: the same one every time
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
        /* TODO (22 Oct 2026 review, #11): an agent / counter session already
           open on another device stays open after this reset. Staff-portal
           sessions end (openStaffSession / current() carry a password stamp),
           but the office session (Auth::finishAdminSession / Auth::admin(),
           includes/auth.php) has no stamp to compare — adding one changes
           every office sign-in and admin/change-password.php too, so it is
           left for a separate change. The office can end one now by switching
           the account off and on (Auth::admin() re-reads is_active every 30 s). */
        if ($twofa) {
            // 22 Oct 2026 (review, #3): a 2FA account gets no session from a phone reset — the office login still asks the 2FA step
            return ['ok' => true, 'kind' => 'none', 'twofa' => true, 'count' => $n];
        }
        if ($open !== null) {
            Auth::teamOpenSession(['must_change_pw' => 0] + $open, true);   // (review #8) "+" keeps the LEFT key: the new value first
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
            // 22 Oct 2026 (review, #11): other staff-portal sessions end on their next page; this one carries on
            if (isset($_SESSION[self::SESSION]['staff_id']) && (int) $_SESSION[self::SESSION]['staff_id'] === (int) $me['staff']['id']) {
                $_SESSION[self::SESSION]['pw'] = self::pwStamp($hash);
            }
        }
    }

    /* =================================================================
     *  Sessions
     * ================================================================= */

    /**
     * 22 Oct 2026 (review, #11): a short stamp of the password hash the
     * session was opened with. current() compares it with the row, so a new
     * or changed password ends every other staff-portal session.
     */
    private static function pwStamp(string $hash): string
    {
        return substr(hash('sha256', 'shg-team|' . $hash), 0, 24);
    }

    private static function openStaffSession(int $staffId): void
    {
        session_regenerate_id(true);
        $hash = (string) Database::scalar('SELECT password_hash FROM staff_applications WHERE id = :i', ['i' => $staffId], '');
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
        // 22 Oct 2026 (review, #5): this browser's WhatsApp proofs go too — the next person on a shared computer starts clean
        unset($_SESSION['wa_verified'], $_SESSION['wa_tokens'], $_SESSION['wa_tried'], $_SESSION['team_reset'], $_SESSION['staff_otp_ok']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
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
        if ($staff === null || (string) $staff['status'] !== 'approved' || empty($staff['password_hash'])
            || !hash_equals((string) ($t['pw'] ?? ''), self::pwStamp((string) $staff['password_hash']))) {
            self::closeStaffSession();   // suspended / left / password cleared by the office / a new password since: out
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
