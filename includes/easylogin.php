<?php
/**
 * =====================================================================
 *  EasyLogin - ONE simple door for every staff face (25 Nov 2026).
 *
 *  Owner: "login system saroi jhau bhayo - only code ra admin le rakheko password le
 *  khulos, no more extra. Suruma mode: agent / admin / driver-conductor / staff / counter.
 *  Username = mobile number, or the code - SHG001 bhaye '1' matra lekhe pani hune.
 *  Default password = manchhe ko pahilo naam @ 2026; user le chahe badalna milne;
 *  admin le request pachhi reset garera pheri yei."
 *
 *    mode      agent | admin | staff | counter | driver - only says which numbering a bare
 *              number means (agent SHG-0001, staff SHG-S-0001, driver no. 1). What the
 *              person may do is still their own account's role, server-side.
 *    username  10-digit mobile, or the code / number, or a plain username.
 *    password  the account's own password (or a driver's PIN), OR - while that account is
 *              still on the default (pw_is_default = 1, never for superadmin) - FirstName@2026
 *              (case does not matter). The person may change it (then the default stops);
 *              admin "Reset" puts it back (admin/login-reset.php).
 *
 *  Nothing else: no OTP, WhatsApp code, PIN-pattern setup or security question. Kept: 5 wrong
 *  tries per account -> 15 min wait, 30 per IP in 10 min, every sign-in / failure audited.
 *  Switches: login_easy_on (this door is the default), login_default_pw_on (FirstName@2026).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class EasyLogin
{
    public const MODES = ['agent', 'admin', 'staff', 'counter', 'driver'];
    public const YEAR  = '2026';

    public static function on(): bool
    {
        return Settings::getBool('login_easy_on', true);
    }

    public static function defaultOn(): bool
    {
        return Settings::getBool('login_default_pw_on', true);
    }

    /** "Ram Bahadur Thapa" -> "Ram@2026"; '' when there is no usable first name. */
    public static function defaultFor(string $fullName): string
    {
        $first = (string) (preg_split('/\s+/u', trim($fullName))[0] ?? '');
        $first = (string) preg_replace('/[^\p{L}]/u', '', $first);
        if ($first === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($first, 0, 1)) . mb_strtolower(mb_substr($first, 1)) . '@' . self::YEAR;
    }

    private static function hasCol(string $table, string $col): bool
    {
        static $memo = [];
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $memo)) {
            try {
                $memo[$k] = (int) Database::scalar(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
                    ['t' => $table, 'c' => $col], 0) > 0;
            } catch (Throwable) {
                $memo[$k] = false;
            }
        }
        return $memo[$k];
    }

    /** May this admins row still open with FirstName@2026? */
    public static function adminDefaultActive(array $a): bool
    {
        if (!self::defaultOn() || (string) ($a['role'] ?? '') === 'superadmin') {
            return false;   // the owner's own account always keeps its own password
        }
        return !self::hasCol('admins', 'pw_is_default') || (int) ($a['pw_is_default'] ?? 1) === 1;
    }

    public static function driverDefaultActive(array $d): bool
    {
        if (!self::defaultOn()) {
            return false;
        }
        return !self::hasCol('drivers', 'pw_is_default') || (int) ($d['pw_is_default'] ?? 1) === 1;
    }

    /* =================================================================
     *  Finding the account
     * ================================================================= */

    private static function digits(string $s): string
    {
        return (string) preg_replace('/\D/', '', $s);
    }

    /** The admins row the mode + typed name point at, or null. */
    public static function findAdmin(string $mode, string $raw): ?array
    {
        $raw = trim(Security::clean($raw, 80));
        if ($raw === '') {
            return null;
        }
        $d = self::digits($raw);
        // a mobile number: the one active account on it (several -> the mode's role first)
        if (strlen($d) >= 10 && preg_match('/^[+\d\s\-().]+$/', $raw) === 1) {
            $p = normalisePhone($raw);
            if ($p !== '' && strlen($p) === 10) {
                $rows = Auth::adminsByPhone($p);
                if (count($rows) > 1) {
                    $want = match ($mode) { 'agent' => ['agent'], 'counter' => ['counter'], default => [] };
                    $pick = array_values(array_filter($rows, static fn(array $r): bool => in_array((string) ($r['role'] ?? ''), $want, true)));
                    $rows = count($pick) === 1 ? $pick : $rows;
                }
                if (count($rows) === 1) {
                    return Database::fetch('SELECT * FROM admins WHERE id = :i LIMIT 1', ['i' => (int) $rows[0]['id']]);
                }
            }
            return null;
        }
        // a bare number / SHG001 / SHG-S-0003: the mode says which numbering
        if (preg_match('/^(?:SHG[\s\-]*)?(S[\s\-]*)?0*(\d{1,6})$/i', $raw, $m) === 1 && (int) $m[2] > 0) {
            $n = (int) $m[2];
            $staffNo = trim((string) $m[1]) !== '' || $mode === 'staff';
            if (!$staffNo) {
                try {
                    require_once __DIR__ . '/agentwallet.php';
                    $aid = AgentWallet::adminForAgentCode($n);
                    if ($aid !== null) {
                        return Database::fetch('SELECT * FROM admins WHERE id = :i LIMIT 1', ['i' => $aid]);
                    }
                } catch (Throwable) {
                }
            }
            try {
                $row = Database::fetch('SELECT a.* FROM ap_members m JOIN admins a ON a.id = m.admin_id WHERE m.staff_no = :n LIMIT 1', ['n' => $n]);
                if ($row !== null) {
                    return $row;
                }
            } catch (Throwable) {
            }
        }
        $u = Auth::resolveLoginId($raw);
        return $u === '' ? null : Database::fetch('SELECT * FROM admins WHERE username = :u LIMIT 1', ['u' => $u]);
    }

    /** The drivers row (driver / co-driver / conductor / host) for a mobile or a driver number. */
    public static function findDriver(string $raw): ?array
    {
        $raw = trim(Security::clean($raw, 40));
        $d = self::digits($raw);
        try {
            if (strlen($d) >= 10) {
                $p = normalisePhone($raw);
                return $p === '' ? null : Database::fetch('SELECT * FROM drivers WHERE phone = :p AND is_active = 1 LIMIT 1', ['p' => $p]);
            }
            if (preg_match('/^(?:D[\s\-]*)?0*(\d{1,6})$/i', $raw, $m) === 1 && (int) $m[1] > 0) {
                return Database::fetch('SELECT * FROM drivers WHERE id = :i AND is_active = 1 LIMIT 1', ['i' => (int) $m[1]]);
            }
        } catch (Throwable) {
        }
        return null;
    }

    /* =================================================================
     *  Checking the password
     * ================================================================= */

    public static function adminPasswordOk(array $a, string $pw): bool
    {
        if ($pw === '') {
            return false;
        }
        $hash = (string) ($a['password_hash'] ?? '');
        if ($hash !== '' && Security::verifyPassword($pw, $hash)) {
            return true;
        }
        if (self::adminDefaultActive($a)) {
            $def = self::defaultFor((string) ($a['full_name'] ?? ''));
            return $def !== '' && mb_strtolower($pw) === mb_strtolower($def);
        }
        return false;
    }

    public static function driverPasswordOk(array $d, string $pw): bool
    {
        if ($pw === '') {
            return false;
        }
        $hash = (string) ($d['pin_hash'] ?? '');
        if ($hash !== '' && Security::verifyPassword($pw, $hash)) {
            return true;
        }
        if (self::driverDefaultActive($d)) {
            $def = self::defaultFor((string) ($d['full_name'] ?? ''));
            return $def !== '' && mb_strtolower($pw) === mb_strtolower($def);
        }
        return false;
    }

    /* =================================================================
     *  The door
     * ================================================================= */

    /**
     * @return array{ok: bool, error?: string, redirect?: string}
     */
    public static function attempt(string $mode, string $user, string $pw): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'agent';
        $bad  = ['ok' => false, 'error' => 'कोड / मोबाइल वा पासवर्ड मिलेन · Code / mobile or password did not match.'];
        $ip   = Security::clientIp();
        if (!Security::rateLimit('easy_login_ip', $ip, 30, 600, 600)) {
            return ['ok' => false, 'error' => 'धेरै पटक प्रयास भयो — १० मिनेटपछि · Too many tries, wait 10 minutes.'];
        }
        $key = $mode . ':' . mb_strtolower(trim($user));
        if (!Security::rateLimit('easy_login_acct', $key, 5, 900, 900)) {
            return ['ok' => false, 'error' => '५ पटक गलत भयो — १५ मिनेटपछि, वा कार्यालयलाई रिसेट गर्न भन्नुहोस् · 5 wrong tries: wait 15 minutes or ask the office to reset.'];
        }

        if ($mode === 'driver') {
            $d = self::findDriver($user);
            if ($d === null || !self::driverPasswordOk($d, $pw)) {
                Logger::audit('driver.easy_login_fail', 'driver', $d !== null ? (string) $d['id'] : 'unknown', null, ['mode' => $mode], 'easy login');
                return $bad;
            }
            require_once __DIR__ . '/driverauth.php';
            $code = DriverAuth::pairCode((int) $d['id']);
            Logger::audit('driver.easy_login', 'driver', (string) $d['id'], null, null, 'easy login -> driver app');
            return ['ok' => true, 'redirect' => '/driver/?pair=' . rawurlencode($code)];
        }

        $a = self::findAdmin($mode, $user);
        if ($a === null || (int) ($a['is_active'] ?? 0) !== 1 || !self::adminPasswordOk($a, $pw)) {
            Logger::audit('admin.easy_login_fail', 'admin', $a !== null ? (string) $a['id'] : 'unknown', null, ['mode' => $mode], 'easy login');
            return $bad;
        }
        // owner: changing the password is the person's choice, never forced at this door
        if ((int) ($a['must_change_pw'] ?? 0) === 1) {
            Database::run('UPDATE admins SET must_change_pw = 0 WHERE id = :i', ['i' => (int) $a['id']]);
            $a['must_change_pw'] = 0;
        }
        // an account that never had a password (PIN-only agents): the default it just proved becomes it
        if ((string) ($a['password_hash'] ?? '') === '') {
            $h = Security::hashPassword($pw);
            Database::update('admins', ['password_hash' => $h], 'id = :i', ['i' => (int) $a['id']]);
            $a['password_hash'] = $h;
        }
        try {
            Auth::openAdminWithoutPassword($a, 'easy');
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[ADMIN_SESSION_KEY]) && is_array($_SESSION[ADMIN_SESSION_KEY])) {
            $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;   // no PIN / pattern setup after this door
        }
        Logger::audit('admin.easy_login', 'admin', (string) $a['id'], null, ['mode' => $mode], 'easy login');
        $home = Auth::homeUrl($a);
        if ($mode === 'counter' && (string) ($a['role'] ?? '') === 'counter') {
            $home = '/admin/smartbook.php?mode=counter';
        }
        return ['ok' => true, 'redirect' => $home];
    }

    /* =================================================================
     *  Change (own, optional) and reset (office)
     * ================================================================= */

    /** The account changed its own password: FirstName@2026 stops opening it. */
    public static function markChanged(int $adminId): void
    {
        if ($adminId > 0 && self::hasCol('admins', 'pw_is_default')) {
            Database::run('UPDATE admins SET pw_is_default = 0 WHERE id = :i', ['i' => $adminId]);
        }
    }

    /** Office reset: back to FirstName@2026 (the old own password stops working). */
    public static function resetAdmin(int $adminId, int $by): string
    {
        $a = Database::fetch('SELECT id, full_name, role FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($a === null) {
            throw new RuntimeException('Account not found.');
        }
        if ((string) $a['role'] === 'superadmin') {
            throw new RuntimeException('मालिकको खाता रिसेट हुँदैन · The superadmin account keeps its own password.');
        }
        $def = self::defaultFor((string) $a['full_name']);
        if ($def === '') {
            throw new RuntimeException('पूरा नाम छैन · This account has no name to make the password from.');
        }
        $set = ['password_hash' => Security::hashPassword($def), 'failed_logins' => 0, 'locked_until' => null];
        if (self::hasCol('admins', 'pw_is_default')) {
            $set['pw_is_default'] = 1;
        }
        if (self::hasCol('admins', 'must_change_pw')) {
            $set['must_change_pw'] = 0;
        }
        Database::update('admins', $set, 'id = :i', ['i' => $adminId]);
        Auth::passwordChanged($adminId, $by);   // signs the old sessions out
        if (self::hasCol('admins', 'pw_is_default')) {   // passwordChanged() must not undo the default flag
            Database::run('UPDATE admins SET pw_is_default = 1 WHERE id = :i', ['i' => $adminId]);
        }
        Logger::audit('admin.password_reset_default', 'admin', (string) $adminId, null, null, 'reset to FirstName@' . self::YEAR . ' by #' . $by);
        return $def;
    }

    public static function resetDriver(int $driverId, int $by): string
    {
        $d = Database::fetch('SELECT id, full_name FROM drivers WHERE id = :i', ['i' => $driverId]);
        if ($d === null) {
            throw new RuntimeException('Driver not found.');
        }
        $def = self::defaultFor((string) $d['full_name']);
        if ($def === '') {
            throw new RuntimeException('पूरा नाम छैन · No name to make the password from.');
        }
        require_once __DIR__ . '/driverauth.php';
        DriverAuth::setPin($driverId, '');   // clears the PIN and signs every phone of this person out
        if (self::hasCol('drivers', 'pw_is_default')) {
            Database::run('UPDATE drivers SET pw_is_default = 1 WHERE id = :i', ['i' => $driverId]);
        }
        Logger::audit('driver.password_reset_default', 'driver', (string) $driverId, null, null, 'reset to FirstName@' . self::YEAR . ' by #' . $by);
        return $def;
    }
}
