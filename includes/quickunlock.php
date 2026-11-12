<?php
/**
 * =====================================================================
 *  includes/quickunlock.php — PIN and pattern quick unlock (1 Nov 2026).
 *
 *  Owner: "agent, staff portal ma Android / iPhone jastai pattern ra PIN le
 *  login hune — suru ma pattern, ani PIN, password, ID, forget — full
 *  flexible."
 *
 *  Any staff / agent / office account may ADD a 4-6 digit PIN and/or a 3x3
 *  pattern (Android style, nodes 0-8 read left to right, top to bottom) as a
 *  quick way through the one door. It is never the only way in: the password,
 *  the ID, the WhatsApp doors and "forgot password" stay exactly as they are,
 *  and a PIN / pattern lockout never locks the password.
 *
 *  Security:
 *    - only bcrypt hashes are stored (pin_hash / pattern_hash); a pattern is
 *      hashed as its canonical node string "0-1-2-5", never as a drawing;
 *    - IP budget (quick_unlock bucket) + per-account counter: MAX_FAILS wrong
 *      tries lock quick unlock for LOCK_MIN minutes;
 *    - the session opens through Auth::openAdminWithoutPassword(), so a
 *      switched-off / locked / 2FA account is refused and an office password
 *      reset still signs a PIN session out;
 *    - one generic "did not match" whatever is behind the box.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class QuickUnlock
{
    public const MAX_FAILS = 5;
    public const LOCK_MIN  = 15;
    public const KINDS     = ['pin', 'pattern'];

    private static ?bool $ready = null;

    private function __construct() {}

    /** The migration ran (the columns exist). */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'pattern_hash'",
                    [], 0) > 0;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Offered on the login page and the settings page. */
    public static function on(): bool
    {
        return self::ready() && Settings::getBool('quick_unlock_on', true);
    }

    /* =================================================================
     *  What may be set
     * ================================================================= */

    /** null = fine, else a user-safe reason. */
    public static function pinProblem(string $pin): ?string
    {
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            return 'PIN ४ देखि ६ अङ्कको हुनुपर्छ · The PIN must be 4 to 6 digits.';
        }
        if (count(array_unique(str_split($pin))) === 1
            || str_contains('0123456789', $pin) || str_contains('9876543210', $pin)) {
            return 'यो PIN धेरै सजिलो भयो (1111, 1234 जस्तो) · That PIN is too easy to guess (like 1111 or 1234).';
        }
        return null;
    }

    /**
     * A drawn pattern as its canonical "0-1-2-5" string, or null when it is
     * not a valid 3x3 path (a node outside 0-8, or a node used twice).
     */
    public static function canonicalPattern(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^[0-8](-[0-8])*$/', $raw) !== 1) {
            return null;
        }
        $nodes = explode('-', $raw);
        if (count($nodes) !== count(array_unique($nodes))) {
            return null;
        }
        return implode('-', $nodes);
    }

    /** null = fine, else a user-safe reason. */
    public static function patternProblem(string $raw): ?string
    {
        $p = self::canonicalPattern($raw);
        if ($p === null) {
            return 'Pattern मिलेन — फेरि कोर्नुहोस् · That pattern is not valid, please draw it again.';
        }
        if (count(explode('-', $p)) < 4) {
            return 'कम्तीमा ४ थोप्ला जोड्नुहोस् · Join at least 4 dots.';
        }
        return null;
    }

    /* =================================================================
     *  Set / remove (the account owner, after their password)
     * ================================================================= */

    /** @throws RuntimeException with a user-safe message */
    public static function set(int $adminId, string $kind, string $value): void
    {
        if (!self::ready()) {
            throw new RuntimeException('PIN / pattern is not switched on yet.');
        }
        if ($kind === 'pin') {
            if (($why = self::pinProblem($value)) !== null) {
                throw new RuntimeException($why);
            }
            $col = 'pin_hash';
        } elseif ($kind === 'pattern') {
            if (($why = self::patternProblem($value)) !== null) {
                throw new RuntimeException($why);
            }
            $value = (string) self::canonicalPattern($value);
            $col = 'pattern_hash';
        } else {
            throw new RuntimeException('Unknown unlock type.');
        }
        Database::query(
            "UPDATE admins SET {$col} = :h, unlock_failed = 0, unlock_locked_until = NULL WHERE id = :i",
            ['h' => Security::hashPassword($value), 'i' => $adminId]
        );
        Logger::audit('admin.unlock.set', 'admin', (string) $adminId, null, ['kind' => $kind], 'Quick unlock ' . $kind . ' set');
    }

    public static function remove(int $adminId, string $kind): void
    {
        if (!self::ready() || !in_array($kind, self::KINDS, true)) {
            return;
        }
        $col = $kind === 'pin' ? 'pin_hash' : 'pattern_hash';
        Database::query("UPDATE admins SET {$col} = NULL WHERE id = :i", ['i' => $adminId]);
        Logger::audit('admin.unlock.remove', 'admin', (string) $adminId, null, ['kind' => $kind], 'Quick unlock ' . $kind . ' removed');
    }

    /** @return array{pin: bool, pattern: bool} */
    public static function status(int $adminId): array
    {
        if (!self::ready()) {
            return ['pin' => false, 'pattern' => false];
        }
        $r = Database::fetch('SELECT pin_hash, pattern_hash FROM admins WHERE id = :i', ['i' => $adminId]) ?? [];
        return ['pin' => (string) ($r['pin_hash'] ?? '') !== '', 'pattern' => (string) ($r['pattern_hash'] ?? '') !== ''];
    }

    /* =================================================================
     *  Remembered phone (6 Nov 2026, owner: "login ma pattern ya PIN lai
     *  focus — tesle nai khulos, Android jasto; password tyo bigreko bela
     *  matra"). After any sign-in this browser remembers WHICH account it
     *  was (a signed cookie: id + expiry + HMAC, nothing secret in it). The
     *  next visit opens straight on that person's pattern / PIN — no ID to
     *  type. The PIN / pattern is still checked by match() with the same
     *  budget and lockout; the cookie alone opens nothing. "Not you?" and
     *  the password door stay one tap away.
     * ================================================================= */

    public const DEVICE_COOKIE = 'shg_qk';
    private const DEVICE_DAYS  = 180;

    private static function deviceSig(string $payload): string
    {
        $key = defined('APP_KEY') ? (string) APP_KEY : (defined('DB_PASS') ? 'shg-qk|' . DB_PASS : 'shg-qk');
        return substr(hash_hmac('sha256', 'qk|' . $payload, $key), 0, 32);
    }

    /** Remember the signed-in account on this browser (call after a successful sign-in). */
    public static function rememberDevice(int $adminId): void
    {
        if ($adminId <= 0 || headers_sent() || !self::on()) {
            return;
        }
        $payload = $adminId . '.' . (time() + self::DEVICE_DAYS * 86400);
        $secure  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        setcookie(self::DEVICE_COOKIE, $payload . '.' . self::deviceSig($payload), [
            'expires' => time() + self::DEVICE_DAYS * 86400, 'path' => '/admin/',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function forgetDevice(): void
    {
        if (!headers_sent()) {
            setcookie(self::DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/admin/', 'httponly' => true, 'samesite' => 'Lax']);
        }
        unset($_COOKIE[self::DEVICE_COOKIE]);
    }

    /**
     * The remembered account when this browser has one AND it can open with a
     * PIN or pattern: ['id','name','pin','pattern'] — else null.
     *
     * @return array{id: int, name: string, pin: bool, pattern: bool}|null
     */
    public static function rememberedAccount(): ?array
    {
        if (!self::on()) {
            return null;
        }
        $raw = (string) ($_COOKIE[self::DEVICE_COOKIE] ?? '');
        if (preg_match('/^(\d{1,10})\.(\d{9,11})\.([a-f0-9]{32})$/', $raw, $m) !== 1) {
            return null;
        }
        if ((int) $m[2] < time() || !hash_equals(self::deviceSig($m[1] . '.' . $m[2]), $m[3])) {
            return null;
        }
        $r = Database::fetch('SELECT id, full_name, username, pin_hash, pattern_hash FROM admins WHERE id = :i AND is_active = 1', ['i' => (int) $m[1]]);
        if ($r === null) {
            return null;
        }
        $pin = (string) ($r['pin_hash'] ?? '') !== '';
        $pat = (string) ($r['pattern_hash'] ?? '') !== '';
        if (!$pin && !$pat) {
            return null;
        }
        $name = trim((string) ($r['full_name'] ?? '')) ?: (string) $r['username'];
        return ['id' => (int) $r['id'], 'name' => $name, 'pin' => $pin, 'pattern' => $pat];
    }

    /* =================================================================
     *  Security question (12 Nov 2026) — one per account, used only by
     *  admin/recover.php to replace a forgotten PIN on a remembered phone.
     * ================================================================= */

    /** key => the question as shown and stored. */
    public const SEC_QUESTIONS = [
        'dob'    => 'तपाईंको जन्म मिति? · Your date of birth?',
        'mother' => 'आमाको नाम? · Your mother\'s name?',
        'school' => 'पहिलो स्कुलको नाम? · Name of your first school?',
        'place'  => 'जन्मेको गाउँ / सहर? · Village or town you were born in?',
    ];

    private static ?bool $secReady = null;

    /** The 12 Nov 2026 migration ran (the columns exist). */
    public static function secReady(): bool
    {
        if (self::$secReady === null) {
            try {
                self::$secReady = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'sec_a_hash'",
                    [], 0) > 0;
            } catch (Throwable $e) {
                self::$secReady = false;
            }
        }
        return self::$secReady;
    }

    /** Lower case, letters and digits only — "Ram  Maya." equals "ram maya". */
    private static function secTidy(string $answer): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($answer)));
    }

    /** null = fine, else a user-safe reason. */
    public static function secProblem(string $question, string $answer): ?string
    {
        if (trim($question) === '' || mb_strlen($question) > 160) {
            return 'सुरक्षा प्रश्न छान्नुहोस् वा लेख्नुहोस् · Choose or type a security question.';
        }
        if (mb_strlen(self::secTidy($answer)) < 3) {
            return 'उत्तर कम्तीमा ३ अक्षर / अङ्क · The answer needs at least 3 letters or digits.';
        }
        return null;
    }

    /** @throws RuntimeException with a user-safe message */
    public static function secSet(int $adminId, string $question, string $answer): void
    {
        if (!self::secReady()) {
            throw new RuntimeException('Security question is not switched on yet.');
        }
        if (($why = self::secProblem($question, $answer)) !== null) {
            throw new RuntimeException($why);
        }
        Database::query('UPDATE admins SET sec_q = :q, sec_a_hash = :h WHERE id = :i',
            ['q' => trim($question), 'h' => Security::hashPassword(self::secTidy($answer)), 'i' => $adminId]);
        Logger::audit('admin.unlock.secq', 'admin', (string) $adminId, null, null, 'Security question set');
    }

    /** The saved question, or '' when none. */
    public static function secQuestion(int $adminId): string
    {
        if (!self::secReady()) {
            return '';
        }
        $r = Database::fetch('SELECT sec_q, sec_a_hash FROM admins WHERE id = :i', ['i' => $adminId]) ?? [];
        return (string) ($r['sec_a_hash'] ?? '') !== '' ? (string) ($r['sec_q'] ?? '') : '';
    }

    public static function secCheck(int $adminId, string $answer): bool
    {
        if (!self::secReady()) {
            return false;
        }
        $h = (string) Database::scalar('SELECT sec_a_hash FROM admins WHERE id = :i AND is_active = 1', ['i' => $adminId], '');
        return $h !== '' && Security::verifyPassword(self::secTidy($answer), $h);
    }

    /* =================================================================
     *  Unlock
     * ================================================================= */

    /**
     * Check a PIN / pattern against the accounts the box points at (one ID,
     * or every active account on a mobile — "the PIN picks the ID", like the
     * password). Counts failures and locks. Does NOT open the session; the
     * caller passes the returned row to Auth::openAdminWithoutPassword().
     *
     * @param list<int> $adminIds
     * @return array{ok: bool, admin?: array<string,mixed>, error?: string}
     */
    public static function match(array $adminIds, string $kind, string $value): array
    {
        $no = ['ok' => false, 'error' => 'PIN / pattern मिलेन · PIN or pattern did not match.'];
        if (!self::on() || !in_array($kind, self::KINDS, true) || $adminIds === []) {
            return $no;
        }
        if (!Security::rateLimit('quick_unlock', Security::clientIp(), 20, 900, 900)) {
            return ['ok' => false, 'error' => 'धेरै पटक गलत भयो — १५ मिनेटपछि, वा पासवर्डले भित्र जानुहोस् · Too many tries: wait 15 minutes, or use your password.'];
        }
        if ($kind === 'pattern') {
            $value = (string) (self::canonicalPattern($value) ?? '');
        } elseif (preg_match('/^\d{4,6}$/', $value) !== 1) {
            $value = '';
        }
        if ($value === '') {
            return $no;
        }

        $col  = $kind === 'pin' ? 'pin_hash' : 'pattern_hash';
        $ids  = array_values(array_unique(array_map('intval', $adminIds)));
        $in   = implode(',', $ids);
        $rows = Database::fetchAll(
            "SELECT id, {$col} AS h, unlock_failed, unlock_locked_until FROM admins
              WHERE id IN ({$in}) AND is_active = 1 AND {$col} IS NOT NULL AND {$col} <> ''"
        );

        $hit = [];
        $tried = [];
        $locked = false;
        foreach ($rows as $r) {
            if (!empty($r['unlock_locked_until']) && strtotime((string) $r['unlock_locked_until']) > time()) {
                $locked = true;
                continue;
            }
            $tried[] = (int) $r['id'];
            if (Security::verifyPassword($value, (string) $r['h'])) {
                $hit[] = (int) $r['id'];
            }
        }

        if (count($hit) === 1) {
            Database::query('UPDATE admins SET unlock_failed = 0, unlock_locked_until = NULL WHERE id = :i', ['i' => $hit[0]]);
            $admin = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $hit[0]]);
            return $admin !== null ? ['ok' => true, 'admin' => $admin] : $no;
        }
        if (count($hit) > 1) {
            // the same PIN on two IDs of one mobile: the PIN cannot pick — the ID can
            return ['ok' => false, 'error' => 'यो PIN एकभन्दा बढी ID मा छ — ID हालेर भित्र जानुहोस् · This PIN is on more than one ID: type the ID instead of the mobile.'];
        }

        foreach ($tried as $id) {
            Database::query(
                // MySQL assigns left to right: unlock_failed below is already the new count
                'UPDATE admins SET unlock_failed = unlock_failed + 1,
                        unlock_locked_until = IF(unlock_failed >= :m, :u, unlock_locked_until)
                  WHERE id = :i',
                ['m' => self::MAX_FAILS, 'u' => date('Y-m-d H:i:s', time() + self::LOCK_MIN * 60), 'i' => $id]
            );
        }
        if ($locked && $tried === []) {
            return ['ok' => false, 'error' => 'PIN / pattern केही मिनेट बन्द छ — पासवर्डले भित्र जानुहोस् · PIN / pattern is paused for a few minutes: use your password.'];
        }
        return $no;
    }
}
