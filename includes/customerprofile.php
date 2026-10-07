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
                        AND COLUMN_NAME IN ('address', 'photo_path', 'profile_verified_at')", [], 0
                ) === 3;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** The code was right: remember it for this session and save the address typed with it. */
    public static function markVerified(int $userId, string $address = ''): void
    {
        $_SESSION[self::SESSION_KEY] = $userId;
        if (!self::ready() || $userId <= 0) {
            return;
        }
        $set = ['profile_verified_at' => date('Y-m-d H:i:s')];
        $address = trim($address);
        if (mb_strlen($address) >= 3) {
            $set['address'] = mb_substr($address, 0, 255);
        }
        Database::update('users', $set, 'id = :id', ['id' => $userId]);
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
        $out = ['otpVerified' => false, 'address' => '', 'photoUrl' => ''];
        if ($userId <= 0 || self::verifiedUserId() !== $userId) {
            return $out;
        }
        $out['otpVerified'] = true;
        if (!self::ready()) {
            return $out;
        }
        $row = Database::fetch('SELECT address, photo_path FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $out['address'] = (string) ($row['address'] ?? '');
        $file = self::photoFile((string) ($row['photo_path'] ?? ''));
        if ($file !== '') {
            $out['photoUrl'] = '/api/profile.php?photo=1&v=' . substr(md5((string) $row['photo_path']), 0, 8);
        }
        return $out;
    }

    /** Save name / address (verified sessions only). */
    public static function save(int $userId, string $name, string $address): void
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
