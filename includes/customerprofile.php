<?php
/**
 * =====================================================================
 *  CustomerProfile — the traveller's own saved details (7 Oct 2026).
 *
 *  Owner ask: "customer login - name, address, number liyera OTP WhatsApp
 *  (AiSensy) bata. Photo bhaye jhan ramro. Tyo data ticket ma use hune,
 *  arko ko lagi ticket katna milne."
 *
 *  `users` already holds the name and the number. This adds the address
 *  and a profile photo, and one rule that keeps them private:
 *
 *   · a session that proved the number with the WhatsApp code
 *     (api/otp.php verify) may read and change the address and photo;
 *   · a plain name + mobile sign-in (the "quick" door every checkout still
 *     uses, and "continue without code") can book exactly as before but
 *     never sees or edits them — anybody can type a mobile number.
 *
 *  Photos are re-encoded with GD (square, 320 px JPEG, metadata dropped)
 *  under TICKET_PATH/profiles/, which nginx never serves; api/profile.php
 *  hands a photo only to its own verified owner.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CustomerProfile
{
    private const SESSION_KEY = 'shg_cust_otp';
    private const PHOTO_PX    = 320;
    private const PHOTO_MAX   = 6 * 1024 * 1024;

    /** Has database/upgrade-2026-10-07-customer-login.sql run? */
    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                        AND COLUMN_NAME IN ('address', 'photo_path', 'profile_verified_at', 'gender', 'location')", [], 0
                ) === 5;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * The code was right: remember it for this session and save the address typed with it.
     * 19 Nov 2026 (u7, LG-2): every PROVEN door passes here (WhatsApp code / hi, e-mail code,
     * passkey) — and only those — so this is also where the phone is remembered (remember()).
     * $via names the door for the remembered row ('otp', 'whatsapp', 'email', 'passkey').
     */
    public static function markVerified(int $userId, string $address = '', array $extra = [], string $via = 'otp'): void
    {
        $_SESSION[self::SESSION_KEY] = $userId;
        if ($userId > 0) {
            self::remember($userId, $via);
        }
        if (!self::ready() || $userId <= 0) {
            return;
        }
        $set = ['profile_verified_at' => date('Y-m-d H:i:s')];
        $address = trim($address);
        if (mb_strlen($address) >= 3) {
            $set['address'] = mb_substr($address, 0, 255);
        }
        // only what was typed: a blank box at sign-in never wipes a saved value
        try {
            foreach (self::extras($extra) as $k => $v) {
                if ($v !== null) {
                    $set[$k] = $v;
                }
            }
        } catch (RuntimeException $e) {
            // a mistyped email at sign-in must not cost the sign-in
        }
        Database::update('users', $set, 'id = :id', ['id' => $userId]);
    }

    public const ID_TYPES = ['Passport', 'Voter ID', 'Aadhaar Card', 'Nepali Citizenship Card', 'Driving Licence'];

    /**
     * Gender / current location / ID document / email from a request,
     * cleaned. null = left blank (or not valid), so the caller decides
     * whether blank means "keep" (sign-in) or "clear" (profile edit).
     *
     * @param array<string, mixed> $in
     * @return array<string, string|null>
     */
    public static function extras(array $in): array
    {
        $txt = static fn(string $k, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? '')) ?? ''), 0, $max);
        $g   = $txt('gender', 10);
        $em  = strtolower($txt('email', 191));
        $it  = $txt('idType', 60);
        $num = strtoupper(preg_replace('/[^A-Za-z0-9\-\/ ]/', '', $txt('idNumber', 60)) ?? '');
        $loc = $txt('location', 255);
        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('That email does not look right.');
        }
        return [
            'gender'    => in_array($g, ['Male', 'Female', 'Other'], true) ? $g : null,
            'location'  => mb_strlen($loc) >= 2 ? $loc : null,
            'email'     => $em !== '' ? $em : null,
            'id_type'   => $num !== '' && in_array($it, self::ID_TYPES, true) ? $it : null,
            'id_number' => mb_strlen($num) >= 4 ? $num : null,
        ];
    }

    /** The signed-in customer, only when THIS session proved the number. */
    public static function verifiedUserId(): int
    {
        $u = Auth::user();
        if ($u === null) {
            return 0;
        }
        $id = (int) $u['id'];
        return (int) ($_SESSION[self::SESSION_KEY] ?? 0) === $id ? $id : 0;
    }

    /**
     * What the app may show. Address + photo only for a verified session.
     *
     * @return array{otpVerified: bool, address: string, photoUrl: string}
     */
    public static function forApp(int $userId): array
    {
        $out = ['otpVerified' => false, 'address' => '', 'photoUrl' => '', 'gender' => '', 'location' => '', 'email' => '', 'idType' => '', 'idNumber' => ''];
        if ($userId <= 0 || self::verifiedUserId() !== $userId) {
            return $out;
        }
        $out['otpVerified'] = true;
        if (!self::ready()) {
            return $out;
        }
        $row = Database::fetch('SELECT address, photo_path, gender, location, email, id_type, id_number FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $out['address']  = (string) ($row['address'] ?? '');
        $out['gender']   = (string) ($row['gender'] ?? '');
        $out['location'] = (string) ($row['location'] ?? '');
        $out['email']    = (string) ($row['email'] ?? '');
        $out['idType']   = (string) ($row['id_type'] ?? '');
        $out['idNumber'] = (string) ($row['id_number'] ?? '');
        $file = self::photoFile((string) ($row['photo_path'] ?? ''));
        if ($file !== '') {
            $out['photoUrl'] = '/api/profile.php?photo=1&v=' . substr(md5((string) $row['photo_path']), 0, 8);
        }
        return $out;
    }

    /** Save name / address (verified sessions only). */
    public static function save(int $userId, string $name, string $address, array $extra = []): void
    {
        $set  = [];
        $name = trim($name);
        if ($name !== '') {
            if (mb_strlen($name) < 2) {
                throw new RuntimeException('Please enter your name.');
            }
            $set['full_name'] = mb_substr($name, 0, 120);
        }
        if (self::ready()) {
            $set['address'] = trim($address) !== '' ? mb_substr(trim($address), 0, 255) : null;
            // the edit form shows every box, so a cleared box clears the value
            foreach (self::extras($extra) as $k => $v) {
                $set[$k] = $v;
            }
        }
        if ($set !== []) {
            Database::update('users', $set, 'id = :id', ['id' => $userId]);
        }
        if (isset($set['full_name']) && isset($_SESSION[USER_SESSION_KEY])) {
            $_SESSION[USER_SESSION_KEY]['name'] = $set['full_name'];
        }
    }

    /**
     * Store an uploaded photo: decoded and re-drawn by GD, so whatever was
     * uploaded never reaches the disk as-is.
     *
     * @param array<string, mixed> $file one $_FILES entry
     */
    public static function savePhoto(int $userId, array $file): void
    {
        if (!self::ready()) {
            throw new RuntimeException('Profile photos are not switched on yet.');
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('The photo did not upload - please try again.');
        }
        $tmp = (string) $file['tmp_name'];
        if ((int) ($file['size'] ?? 0) > self::PHOTO_MAX || filesize($tmp) > self::PHOTO_MAX) {
            throw new RuntimeException('The photo is too large (6 MB at most).');
        }
        $info = @getimagesize($tmp);
        $type = is_array($info) ? (int) $info[2] : 0;
        $src  = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default        => false,
        };
        if ($src === false) {
            throw new RuntimeException('Please choose a JPG, PNG or WebP photo.');
        }
        $src = self::upright($src, $tmp, $type);

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::PHOTO_PX, self::PHOTO_PX);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::PHOTO_PX, self::PHOTO_PX, $side, $side);
        imagedestroy($src);

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            imagedestroy($dst);
            throw new RuntimeException('Could not save the photo right now.');
        }
        $name = 'u' . $userId . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $ok   = imagejpeg($dst, $dir . '/' . $name . '.tmp', 85) && rename($dir . '/' . $name . '.tmp', $dir . '/' . $name);
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException('Could not save the photo right now.');
        }

        $old = (string) Database::scalar('SELECT photo_path FROM users WHERE id = :id', ['id' => $userId], '');
        Database::update('users', ['photo_path' => $name], 'id = :id', ['id' => $userId]);
        $oldFile = self::photoFile($old);
        if ($oldFile !== '') {
            @unlink($oldFile);
        }
    }

    public static function removePhoto(int $userId): void
    {
        if (!self::ready()) {
            return;
        }
        $old = (string) Database::scalar('SELECT photo_path FROM users WHERE id = :id', ['id' => $userId], '');
        Database::update('users', ['photo_path' => null], 'id = :id', ['id' => $userId]);
        $f = self::photoFile($old);
        if ($f !== '') {
            @unlink($f);
        }
    }

    /** Absolute path of a stored photo name, '' when missing or not one of ours. */
    public static function photoFile(string $name): string
    {
        if (!preg_match('/^u\d+-[0-9a-f]{12}\.jpg$/', $name)) {
            return '';
        }
        $f = self::dir() . '/' . $name;
        return is_file($f) ? $f : '';
    }

    public static function photoFor(int $userId): string
    {
        if (!self::ready()) {
            return '';
        }
        return self::photoFile((string) Database::scalar('SELECT photo_path FROM users WHERE id = :id', ['id' => $userId], ''));
    }

    /**
     * People this account booked for before (not the account holder), newest
     * first, for the "someone else" picker at checkout.
     *
     * @return list<array{name: string, phone: string}>
     */
    public static function othersBookedFor(int $userId, string $ownPhone): array
    {
        $rows = Database::fetchAll(
            "SELECT bp.full_name, b.contact_phone, MAX(b.id) AS last_id
               FROM bookings b
               JOIN booking_passengers bp ON bp.booking_id = b.id AND bp.is_primary = 1
              WHERE b.user_id = :u AND b.contact_phone <> :p
              GROUP BY bp.full_name, b.contact_phone
              ORDER BY last_id DESC
              LIMIT 8",
            ['u' => $userId, 'p' => $ownPhone]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['name' => (string) $r['full_name'], 'phone' => (string) $r['contact_phone']];
        }
        return $out;
    }

    private static function dir(): string
    {
        return rtrim(TICKET_PATH, '/\\') . '/profiles';
    }

    /* =================================================================
     *  19 Nov 2026 (u7, LG-1 / LG-2): STAY SIGNED IN ON THIS PHONE.
     *
     *  A passenger who PROVED the number (markVerified: WhatsApp code / hi,
     *  e-mail code, passkey — never the name + mobile "quick" door) stays
     *  signed in and verified on that phone for customer_remember_days
     *  (default 365, 0 = off). Same rules as the staff token
     *  (AdminSessions::remember): 32 random bytes in cookie shg_crm
     *  (HttpOnly, Secure on https, SameSite=Lax, path /), only the SHA-256 in
     *  customer_sessions, bound to the shg_dev device cookie, rotated on every
     *  use (the previous hash honoured for PREV_GRACE seconds), ended by
     *  "Sign out" on that phone, by a blocked account, or when another
     *  passenger signs in on it. Inert until the u7 migration has run.
     * ================================================================= */

    public const REMEMBER_COOKIE = 'shg_crm';
    public const PREV_GRACE      = 120;

    /** Test hook: the plain token the last remember() / resume() handed to the browser. */
    public static ?string $lastRememberToken = null;

    private static ?bool $rmReady = null;

    public static function rememberReady(): bool
    {
        if (self::$rmReady === null) {
            try {
                self::$rmReady = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_sessions'",
                    [], 0) > 0;
            } catch (Throwable $e) {
                self::$rmReady = false;
            }
        }
        return self::$rmReady;
    }

    public static function rememberDays(): int
    {
        try {
            return max(0, min(730, Settings::getInt('customer_remember_days', 365)));
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function deviceHashNoMint(): string
    {
        $raw = (string) ($_COOKIE[LoginLog::DEVICE_COOKIE] ?? '');
        return preg_match('/^[a-f0-9]{64}$/', $raw) === 1 ? hash('sha256', $raw) : '';
    }

    private static function setCookie(string $token, int $until): void
    {
        $_COOKIE[self::REMEMBER_COOKIE] = $token;
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => $until, 'path' => '/', 'secure' => Security::isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    private static function dropCookie(): void
    {
        $had = isset($_COOKIE[self::REMEMBER_COOKIE]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
        if (!$had || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => Security::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    /** The row this browser's cookie names (current or just-rotated token), or null. */
    private static function cookieRow(): ?array
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1 || !self::rememberReady()) {
            return null;
        }
        $h = hash('sha256', $raw);
        try {
            return Database::fetch('SELECT * FROM customer_sessions WHERE token_hash = :h OR prev_hash = :p ORDER BY id DESC LIMIT 1',
                ['h' => $h, 'p' => $h]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * A proven door just signed this passenger in: remember this phone. One live row per
     * phone: an older token of this browser is ended first. Never blocks a sign-in.
     *
     * @return int the Unix time it stays remembered until (0 = not remembered)
     */
    public static function remember(int $userId, string $via = 'otp'): int
    {
        self::$lastRememberToken = null;
        $days = self::rememberDays();
        $dev  = self::deviceHashNoMint();
        if ($dev === '' && $userId > 0 && $days > 0 && PHP_SAPI !== 'cli') {
            $dev = LoginLog::deviceHash();   // a first visit: mint the device cookie now (headers permitting)
        }
        if ($userId <= 0 || $days <= 0 || $dev === '' || !self::rememberReady()) {
            return 0;
        }
        try {
            self::forget('replaced by a new sign-in');
            $token = bin2hex(random_bytes(32));
            $until = time() + $days * 86400;
            $now   = date('Y-m-d H:i:s');
            Database::insert('customer_sessions', [
                'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'device_hash' => $dev,
                'label' => mb_substr(LoginLog::deviceLabel((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120),
                'via' => mb_substr(preg_replace('/[^a-z_]/', '', strtolower($via)) ?? '', 0, 20),
                'created_at' => $now, 'last_seen' => $now, 'rotated_at' => $now,
                'expires_at' => date('Y-m-d H:i:s', $until),
            ]);
            self::setCookie($token, $until);
            self::$lastRememberToken = $token;
            if (isset($_SESSION[USER_SESSION_KEY]) && (int) ($_SESSION[USER_SESSION_KEY]['id'] ?? 0) === $userId) {
                $_SESSION[USER_SESSION_KEY]['rm'] = $until;
            }
            if (random_int(1, 50) === 1) {
                self::prune();   // 19 Nov 2026 (u7 review): housekeeping, about once every 50 sign-ins
            }
            return $until;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * 19 Nov 2026 (u7 review): housekeeping — drop rows ended or expired long ago (never a live
     * one). Runs now and then from remember(); safe to call from a daily cron too.
     * Retention: customer_sessions_keep_days (default 90, min 7).
     */
    public static function prune(?int $days = null): int
    {
        if (!self::rememberReady()) {
            return 0;
        }
        try {
            $keep = max(7, $days ?? Settings::getInt('customer_sessions_keep_days', 90));
            $cut  = date('Y-m-d H:i:s', time() - $keep * 86400);
            return Database::delete('customer_sessions',
                '(ended_at IS NOT NULL AND ended_at < :c1) OR (expires_at < :c2)', ['c1' => $cut, 'c2' => $cut]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Before anything else, on a request with no passenger (and no staff) session: does
     * this phone carry a remember token that still opens its passenger? On success the
     * session is open AND verified again, and the token rotated. Every refusal ends the
     * row and expires the cookie.
     */
    public static function resume(): bool
    {
        self::$lastRememberToken = null;
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw === '') {
            return false;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $raw) !== 1 || !self::rememberReady()) {
            self::dropCookie();
            return false;
        }
        $h = hash('sha256', $raw);
        try {
            $row = Database::fetch('SELECT * FROM customer_sessions WHERE token_hash = :h LIMIT 1', ['h' => $h]);
            $grace = false;
            if ($row === null) {
                $row = Database::fetch('SELECT * FROM customer_sessions WHERE prev_hash = :h LIMIT 1', ['h' => $h]);
                $grace = $row !== null && strtotime((string) ($row['rotated_at'] ?? '')) >= time() - self::PREV_GRACE;
                if ($row !== null && !$grace) {
                    self::endRow((int) $row['id'], 'old token reused after rotation');
                    self::dropCookie();
                    return false;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        if ($row === null) {
            self::dropCookie();
            return false;
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => (int) $row['user_id']]);
        $dev  = self::deviceHashNoMint();
        $why = match (true) {
            !empty($row['ended_at'])                                                => 'ended',
            strtotime((string) $row['expires_at']) <= time()                        => 'expired',
            self::rememberDays() <= 0                                               => 'switched off',
            $dev === '' || !hash_equals((string) ($row['device_hash'] ?? ''), $dev) => 'another device',
            $user === null                                                          => 'account deleted',
            (int) ($user['is_blocked'] ?? 0) === 1                                  => 'account blocked',
            default                                                                 => '',
        };
        if ($why !== '') {
            self::endRow((int) $row['id'], 'not resumed: ' . $why);
            self::dropCookie();
            return false;
        }
        try {
            $now = date('Y-m-d H:i:s');
            if ($grace) {
                $until = (int) strtotime((string) $row['expires_at']);
                Database::update('customer_sessions', ['last_seen' => $now], 'id = :id', ['id' => (int) $row['id']]);
            } else {
                $token = bin2hex(random_bytes(32));
                $until = time() + self::rememberDays() * 86400;
                $n = Database::update('customer_sessions', [
                    'token_hash' => hash('sha256', $token), 'prev_hash' => $h, 'rotated_at' => $now,
                    'last_seen' => $now, 'expires_at' => date('Y-m-d H:i:s', $until),
                ], 'id = :id AND token_hash = :old AND ended_at IS NULL', ['id' => (int) $row['id'], 'old' => $h]);
                if ($n < 1) {
                    /* a parallel request (the app fires several at start) rotated it between our read and
                       this write: this one is then a sibling too — open, and let that one's cookie stand */
                    $sib = Database::fetch('SELECT expires_at FROM customer_sessions WHERE id = :id AND ended_at IS NULL
                                              AND prev_hash = :h AND rotated_at >= :since',
                        ['id' => (int) $row['id'], 'h' => $h, 'since' => date('Y-m-d H:i:s', time() - self::PREV_GRACE)]);
                    if ($sib === null) {
                        return false;
                    }
                    $until = (int) strtotime((string) $sib['expires_at']);
                } else {
                    self::setCookie($token, $until);
                    self::$lastRememberToken = $token;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        $_SESSION[USER_SESSION_KEY] = [
            'id'        => (int) $user['id'],
            'phone'     => (string) $user['phone'],
            'name'      => (string) ($user['full_name'] ?? ''),
            'role'      => (string) $user['role'],
            'ref_code'  => (string) ($user['ref_code'] ?? ''),
            'logged_in' => (int) (strtotime((string) $row['created_at']) ?: time()),
            'last_seen' => time(),
            'rm'        => $until,
        ];
        $_SESSION[self::SESSION_KEY] = (int) $user['id'];   // the number was proven when this phone was remembered
        return true;
    }

    /** "Sign out" on this phone: end the row its cookie names and expire the cookie. */
    public static function forget(string $reason = 'signed out'): void
    {
        $row = self::cookieRow();
        if ($row !== null) {
            self::endRow((int) $row['id'], $reason);
        }
        self::dropCookie();
    }

    /** Another passenger signs in on this phone: the earlier one's remember ends. */
    public static function forgetIfOther(int $userId): void
    {
        $row = self::cookieRow();
        if ($row !== null && (int) $row['user_id'] !== $userId) {
            self::endRow((int) $row['id'], 'another passenger signed in on this phone');
            self::dropCookie();
        }
    }

    private static function endRow(int $id, string $reason): void
    {
        try {
            Database::update('customer_sessions', ['ended_at' => date('Y-m-d H:i:s'), 'end_reason' => mb_substr($reason, 0, 120)],
                'id = :id AND ended_at IS NULL', ['id' => $id]);
        } catch (Throwable $e) {
        }
    }

    /** A phone photo carries its rotation in EXIF; draw it the right way up. */
    private static function upright(\GdImage $img, string $tmp, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($tmp);
        $deg  = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($deg === 0) {
            return $img;
        }
        $r = imagerotate($img, $deg, 0);
        if ($r === false) {
            return $img;
        }
        imagedestroy($img);
        return $r;
    }
}
