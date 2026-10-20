<?php
/**
 * =====================================================================
 *  includes/driverauth.php - the driver app's sign-in (20 Oct 2026).
 *
 *  A driver is not staff: no admin account, no session cookie, no access
 *  to anything but "send the position of the trip I am driving". The
 *  office gives each driver a 4-6 digit PIN in Admin -> Drivers (stored
 *  as drivers.pin_hash). The phone signs in once with phone + PIN and
 *  keeps a random bearer token. Only its sha256 is stored (driver_devices),
 *  so a database copy cannot sign anyone in. Changing a PIN signs every
 *  phone of that driver out.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DriverAuth
{
    public static function validPin(string $pin): bool
    {
        return preg_match('/^\d{4,6}$/', $pin) === 1;
    }

    /** Set (or clear, with '') a driver's PIN. Every signed-in phone of that driver is signed out. */
    public static function setPin(int $driverId, string $pin): void
    {
        if ($pin !== '' && !self::validPin($pin)) {
            throw new RuntimeException('The PIN must be 4 to 6 digits.');
        }
        Database::update('drivers', ['pin_hash' => $pin === '' ? null : password_hash($pin, PASSWORD_DEFAULT)],
            'id = :id', ['id' => $driverId]);
        Database::update('driver_devices', ['revoked_at' => date('Y-m-d H:i:s')],
            'driver_id = :d AND revoked_at IS NULL', ['d' => $driverId]);
    }

    /**
     * Phone + PIN -> a new device token, or null. The caller rate-limits.
     *
     * @return array{token:string, driver:array<string,mixed>, deviceId:int}|null
     */
    public static function login(string $phone, string $pin, string $label = ''): ?array
    {
        $phone = normalisePhone($phone);
        if ($phone === '' || !self::validPin($pin)) {
            return null;
        }
        $d = Database::fetch(
            'SELECT id, full_name, phone, role, pin_hash FROM drivers WHERE phone = :p AND is_active = 1 AND pin_hash IS NOT NULL',
            ['p' => $phone]
        );
        if ($d === null || !password_verify($pin, (string) $d['pin_hash'])) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $id    = Database::insert('driver_devices', [
            'driver_id'  => (int) $d['id'],
            'token_hash' => hash('sha256', $token),
            'label'      => mb_substr($label, 0, 160),
            'last_seen'  => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::audit('driver.app_login', 'driver', (string) $d['id'], null, ['device' => $id], 'driver app');
        unset($d['pin_hash']);
        return ['token' => $token, 'driver' => $d, 'deviceId' => $id];
    }

    /** The bearer token this request carries ('' when none). */
    public static function requestToken(): string
    {
        $t = (string) ($_SERVER['HTTP_X_DRIVER_TOKEN'] ?? '');
        if ($t === '') {
            $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (stripos($auth, 'Bearer ') === 0) {
                $t = trim(substr($auth, 7));
            }
        }
        return preg_match('/^[a-f0-9]{48}$/', $t) === 1 ? $t : '';
    }

    /**
     * The signed-in driver of this request, or null.
     *
     * @return array{deviceId:int, driverId:int, name:string, phone:string, role:string}|null
     */
    public static function current(?string $token = null): ?array
    {
        $token = $token ?? self::requestToken();
        if ($token === '') {
            return null;
        }
        $r = Database::fetch(
            'SELECT dd.id AS device_id, dd.last_seen, d.id AS driver_id, d.full_name, d.phone, d.role
               FROM driver_devices dd JOIN drivers d ON d.id = dd.driver_id
              WHERE dd.token_hash = :h AND dd.revoked_at IS NULL AND d.is_active = 1 AND d.pin_hash IS NOT NULL',
            ['h' => hash('sha256', $token)]
        );
        if ($r === null) {
            return null;
        }
        if ($r['last_seen'] === null || time() - (int) strtotime((string) $r['last_seen']) > 120) {
            Database::update('driver_devices', ['last_seen' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $r['device_id']]);
        }
        return ['deviceId' => (int) $r['device_id'], 'driverId' => (int) $r['driver_id'],
                'name' => (string) $r['full_name'], 'phone' => (string) $r['phone'], 'role' => (string) $r['role']];
    }

    public static function logout(string $token): void
    {
        if ($token !== '') {
            Database::update('driver_devices', ['revoked_at' => date('Y-m-d H:i:s')],
                'token_hash = :h AND revoked_at IS NULL', ['h' => hash('sha256', $token)]);
        }
    }
}
