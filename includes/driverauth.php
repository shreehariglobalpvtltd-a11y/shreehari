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

    private static ?bool $crewLink = null;

    /**
     * 30 Oct 2026 — SQL tail for a query on `drivers d`: a drivers row linked to an
     * AgentPro crew login (ap_members.driver_id) is off while that login is off
     * (suspended / archived on Team), so a fired driver's PIN or old phone token
     * cannot start a trip or post positions. Restoring the login turns it back on.
     * '' on a database without the AgentPro link column.
     */
    private static function crewGate(): string
    {
        if (self::$crewLink === null) {
            try {
                self::$crewLink = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_members' AND COLUMN_NAME = 'driver_id'", [], 0) > 0;
            } catch (Throwable $e) {
                self::$crewLink = false;
            }
        }
        return self::$crewLink
            ? ' AND NOT EXISTS (SELECT 1 FROM ap_members apm JOIN admins aa ON aa.id = apm.admin_id
                                 WHERE apm.driver_id = d.id AND aa.is_active = 0)'
            : '';
    }

    /** Set (or clear, with '') a driver's PIN. Every signed-in phone of that driver is signed out. */
    public static function setPin(int $driverId, string $pin): void
    {
        if ($pin !== '' && !self::validPin($pin)) {
            throw new RuntimeException('The PIN must be 4 to 6 digits.');
        }
        Database::update('drivers', ['pin_hash' => $pin === '' ? null : Security::hashPassword($pin)],
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
        /* 25 Nov 2026 (easy login, owner): the mobile OR the driver number ("3", "D3"), and the PIN
           OR the password - FirstName@2026 while this person is still on the default. */
        require_once __DIR__ . '/easylogin.php';
        $raw = $phone;
        $phone = normalisePhone($phone);
        if (trim($pin) === '' || mb_strlen($pin) > 64) {
            return null;
        }
        $found = EasyLogin::findDriver($raw);
        $d = $found === null ? null : Database::fetch(
            'SELECT d.* FROM drivers d WHERE d.id = :i AND d.is_active = 1' . self::crewGate(),
            ['i' => (int) $found['id']]
        );
        if ($phone === '' && $d !== null) {
            $phone = (string) $d['phone'];
        }
        if ($d === null || !EasyLogin::driverPasswordOk($d, $pin)) {
            // 16 Nov 2026: every failed try is on the record (the PIN itself never is)
            Logger::audit('driver.app_login_fail', 'driver', $d !== null ? (string) $d['id'] : '…' . substr($phone, -4), null,
                ['reason' => $d !== null ? 'wrong_pin' : 'no_driver'], 'driver app: wrong phone or PIN');
            return null;
        }
        if ((string) ($d['pin_hash'] ?? '') !== '' && Security::needsRehash((string) $d['pin_hash'])) {   // an older cost-10 PIN hash moves to cost 12 now
            Database::update('drivers', ['pin_hash' => Security::hashPassword($pin)],
                'id = :id AND pin_hash = :old', ['id' => (int) $d['id'], 'old' => (string) $d['pin_hash']]);
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

    /**
     * 30 Oct 2026 — single sign-on from the member portal (/me/ → "Start bus
     * location"): a signed-in AgentPro driver / conductor gets a device token
     * for THEIR drivers row without typing the PIN. The caller has checked the
     * session, CSRF and that this drivers row is theirs. The phone's previous
     * token (same driver only) is revoked, so tapping twice leaves one device.
     *
     * @return array{token:string, driver:array<string,mixed>, deviceId:int}|null
     */
    public static function issue(int $driverId, string $label = '', string $prevToken = ''): ?array
    {
        $d = Database::fetch(
            'SELECT d.id, d.full_name, d.phone, d.role FROM drivers d
              WHERE d.id = :i AND d.is_active = 1 AND d.pin_hash IS NOT NULL' . self::crewGate(),
            ['i' => $driverId]
        );
        if ($d === null) {
            return null;
        }
        $token = bin2hex(random_bytes(24));
        $id = (int) Database::transaction(static function () use ($driverId, $label, $prevToken, $token): int {
            $oldId = 0;
            if (preg_match('/^[a-f0-9]{48}$/', $prevToken) === 1) {
                $oldId = (int) Database::scalar(
                    'SELECT id FROM driver_devices WHERE token_hash = :h AND driver_id = :d AND revoked_at IS NULL',
                    ['h' => hash('sha256', $prevToken), 'd' => $driverId], 0);
            }
            $newId = (int) Database::insert('driver_devices', [
                'driver_id'  => $driverId,
                'token_hash' => hash('sha256', $token),
                'label'      => mb_substr($label, 0, 160),
                'last_seen'  => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($oldId > 0) {
                // A trip already running on this phone moves to the new token, so the
                // driver app resumes it (and its queued GPS points) instead of a 409.
                Database::update('live_trips', ['device_id' => $newId],
                    "device_id = :old AND status IN ('running','paused')", ['old' => $oldId]);
                Database::update('driver_devices', ['revoked_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $oldId]);
            }
            return $newId;
        });
        Logger::audit('driver.app_sso', 'driver', (string) $driverId, null, ['device' => $id], 'driver app opened from /me/');
        return ['token' => $token, 'driver' => $d, 'deviceId' => $id];
    }

    /* ------------------------------------------------------------------
     *  19 Nov 2026 — connect a crew phone by link / QR (owner: "conductor ko
     *  mobile, link le scanner le connect matra garnu paros").
     *  The office makes a ONE-TIME code for one drivers row (24 h); the
     *  phone that opens /driver/?pair=CODE gets a device token for that row
     *  without typing a PIN. Only the code's SHA-256 is stored. Redeeming
     *  burns the code; a wrong / used / expired code gives null.
     * ------------------------------------------------------------------ */
    public const PAIR_TTL = 86400;

    /** @return string the code to put in the link (shown once) */
    public static function pairCode(int $driverId, int $byAdmin = 0): string
    {
        $d = Database::fetch('SELECT id FROM drivers WHERE id = :i AND is_active = 1', ['i' => $driverId]);
        if ($d === null) {
            throw new RuntimeException('Driver not found or inactive.');
        }
        $code = bin2hex(random_bytes(16));
        $val  = json_encode(['d' => $driverId, 'exp' => time() + self::PAIR_TTL, 'by' => $byAdmin]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'driver_pair' AND kvalue LIKE :p", ['p' => '{"d":' . $driverId . ',%']);
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('driver_pair', :k, :v, 'pair')",
            ['k' => hash('sha256', $code), 'v' => $val]);
        Logger::audit('driver.pair_code', 'driver', (string) $driverId, null, ['ttl' => self::PAIR_TTL], 'Crew phone connect link made');
        return $code;
    }

    /** @return array{token:string, driver:array<string,mixed>, deviceId:int}|null */
    public static function pairRedeem(string $code, string $label = ''): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $code) !== 1) {
            return null;
        }
        $key = hash('sha256', $code);
        $row = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'driver_pair' AND kkey = :k", ['k' => $key]);
        if ($row === null) {
            return null;
        }
        // burn first: two phones racing on one code - only one DELETE wins
        if (Database::run("DELETE FROM kv_store WHERE kscope = 'driver_pair' AND kkey = :k", ['k' => $key]) !== 1) {
            return null;
        }
        $j = json_decode((string) $row['kvalue'], true);
        $driverId = (int) ($j['d'] ?? 0);
        if ($driverId <= 0 || (int) ($j['exp'] ?? 0) < time()) {
            return null;
        }
        // issue() needs a PIN on the row: a crew member connected by link gets a random one
        // nobody knows (the office can set a real PIN later on Drivers).
        if (Database::scalar('SELECT pin_hash FROM drivers WHERE id = :i', ['i' => $driverId]) === null) {
            Database::update('drivers', ['pin_hash' => Security::hashPassword(bin2hex(random_bytes(8)))], 'id = :i', ['i' => $driverId]);
        }
        $res = self::issue($driverId, $label);
        if ($res !== null) {
            Logger::audit('driver.pair_redeem', 'driver', (string) $driverId, null, ['device' => $res['deviceId']], 'Crew phone connected by link');
        }
        return $res;
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
              WHERE dd.token_hash = :h AND dd.revoked_at IS NULL AND d.is_active = 1 AND d.pin_hash IS NOT NULL' . self::crewGate(),
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
