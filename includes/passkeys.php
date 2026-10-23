<?php
/**
 * includes/passkeys.php — Face ID / fingerprint sign-in for the team portal
 * (23 Oct 2026, owner: "optional device-based Face ID/fingerprint login via
 * WebAuthn/passkeys. Never collect raw fingerprint or face biometric templates").
 *
 * WebAuthn without a library: the phone's own lock signs a challenge, and we
 * keep only the PUBLIC key (team_passkeys, upgrade-2026-10-23-passkeys.sql).
 *
 *   registration   navigator.credentials.create() -> attestationObject (CBOR)
 *                  -> authData -> credential id + COSE public key -> PEM
 *   sign-in        navigator.credentials.get() -> authData + clientDataJSON +
 *                  signature -> openssl_verify(authData || sha256(clientData))
 *
 * ES256 (P-256) and RS256 only; "none" attestation (we trust the device the
 * signed-in person added, like a password they set). The challenge lives in
 * the session for 10 minutes and is spent on first use. The rpId is the site
 * host, the origin must be APP_URL's origin, and the authenticator's
 * sign counter must only go up (a lower one means a cloned key).
 */

declare(strict_types=1);

final class PasskeyError extends RuntimeException {}

final class Passkeys
{
    public const CHALLENGE_TTL = 600;
    private static ?bool $ready = null;

    private function __construct() {}

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'team_passkeys'", [], 0) === 1
                    && function_exists('openssl_verify');
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /* ---------------- relying party ---------------- */

    /**
     * The host the browser is on when it is NOT the APP_URL site (the .network staff door, a bare-domain
     * visit): a passkey is bound to the page's own domain, so APP_URL's host would make every ceremony fail
     * there (23 Oct 2026 review). On the main site APP_URL stays the id, so keys made before today keep working.
     */
    private static function requestHost(): string
    {
        $h = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $h = preg_replace('/:\d+$/', '', $h) ?? '';
        if ($h === '' || preg_match('/^[a-z0-9.-]{1,120}$/', $h) !== 1) {
            return '';
        }
        $app = strtolower((string) (parse_url(APP_URL, PHP_URL_HOST) ?: ''));
        if ($h === $app || $app === '') {
            return '';
        }
        // the same registrable domain as APP_URL (www. vs bare): keep APP_URL's id, it is a valid suffix of both
        $reg = static fn (string $x): string => implode('.', array_slice(explode('.', $x), -2));
        if ($reg($h) === $reg($app)) {
            return '';
        }
        return $h;
    }

    public static function rpId(): string
    {
        $other = self::requestHost();
        if ($other !== '') {
            return $other;
        }
        return (string) (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost');
    }

    public static function origin(): string
    {
        $other = self::requestHost();
        if ($other !== '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $port  = (int) ($_SERVER['SERVER_PORT'] ?? 0);
            return ($https ? 'https' : 'http') . '://' . $other . (in_array($port, [0, 80, 443], true) ? '' : ':' . $port);
        }
        $u = parse_url(APP_URL);
        return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
    }

    /** A fresh challenge for $purpose ('reg' | 'login'), kept in the session. */
    public static function challenge(string $purpose): string
    {
        $c = self::b64u(random_bytes(32));
        $_SESSION['team_passkey'][$purpose] = ['c' => $c, 't' => time()];
        return $c;
    }

    private static function takeChallenge(string $purpose): string
    {
        $s = $_SESSION['team_passkey'][$purpose] ?? null;
        unset($_SESSION['team_passkey'][$purpose]);
        if (!is_array($s) || (int) ($s['t'] ?? 0) < time() - self::CHALLENGE_TTL || (string) ($s['c'] ?? '') === '') {
            throw new PasskeyError('यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · The request expired, please try again.');
        }
        return (string) $s['c'];
    }

    /* ---------------- the rows ---------------- */

    /** @return list<array<string, mixed>> the passkeys of one owner (never the key itself) */
    public static function listFor(string $kind, int $id): array
    {
        if (!self::ready()) {
            return [];
        }
        return Database::fetchAll(
            'SELECT id, label, transports, created_at, last_used_at FROM team_passkeys WHERE owner_kind = :k AND owner_id = :i ORDER BY id DESC',
            ['k' => $kind, 'i' => $id]);
    }

    public static function remove(string $kind, int $id, int $rowId, string $who): bool
    {
        if (!self::ready()) {
            return false;
        }
        $n = Database::delete('team_passkeys', 'id = :r AND owner_kind = :k AND owner_id = :i', ['r' => $rowId, 'k' => $kind, 'i' => $id]);
        if ($n > 0) {
            Logger::audit('team.passkey.remove', $kind, (string) $id, null, ['row' => $rowId], 'Passkey removed by ' . $who);
        }
        return $n > 0;
    }

    /** The ids the browser may offer for this owner (so a second device is not registered twice). */
    public static function credentialIds(string $kind, int $id): array
    {
        if (!self::ready()) {
            return [];
        }
        return array_map(static fn(array $r): string => (string) $r['credential_id'],
            Database::fetchAll('SELECT credential_id FROM team_passkeys WHERE owner_kind = :k AND owner_id = :i', ['k' => $kind, 'i' => $id]));
    }

    /* ---------------- registration ---------------- */

    /**
     * Store the credential a signed-in person just created.
     *
     * @param array<string, mixed> $cred {id, rawId?, clientDataJSON, attestationObject, transports?}
     */
    public static function register(string $kind, int $ownerId, string $phone10, array $cred, string $label): array
    {
        if (!self::ready()) {
            throw new PasskeyError('Passkeys are not switched on yet.');
        }
        $challenge = self::takeChallenge('reg');
        $client = self::clientData((string) ($cred['clientDataJSON'] ?? ''), 'webauthn.create', $challenge);
        $att = self::cbor(self::fromB64u((string) ($cred['attestationObject'] ?? '')));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new PasskeyError('The device sent no credential.');
        }
        $auth = self::authData($att['authData'], true);
        if (($auth['flags'] & 0x01) === 0) {
            throw new PasskeyError('The device did not confirm your presence.');
        }
        $pem = self::cosePem($auth['cose']);
        $credId = self::b64u($auth['credId']);
        if ((string) ($cred['id'] ?? '') !== '' && $cred['id'] !== $credId) {
            throw new PasskeyError('The credential id does not match.');
        }
        $label = StaffApps::oneLine(Security::clean(strip_tags($label), 60), 60);
        $transports = implode(',', array_slice(array_values(array_filter((array) ($cred['transports'] ?? []), 'is_string')), 0, 5));
        if (count(self::listFor($kind, $ownerId)) >= 5) {
            throw new PasskeyError('५ भन्दा बढी डिभाइस राख्न मिल्दैन — पुरानो हटाउनुहोस् · At most 5 devices; remove an old one first.');
        }
        $id = Database::insertIgnore('team_passkeys', [
            'owner_kind' => $kind, 'owner_id' => $ownerId, 'phone10' => $phone10,
            'credential_id' => $credId, 'public_key' => $pem, 'alg' => (int) ($auth['cose'][3] ?? -7),
            'sign_count' => $auth['count'], 'transports' => substr($transports, 0, 120),
            'label' => $label !== '' ? $label : 'This device', 'created_ip' => Security::clientIp(),
        ]);
        if ($id <= 0) {
            throw new PasskeyError('यो डिभाइस पहिले नै थपिएको छ · This device is already added.');
        }
        Logger::audit('team.passkey.add', $kind, (string) $ownerId, null, ['row' => $id, 'label' => $label], 'Passkey added at /team/');
        return ['id' => $id, 'label' => $label];
    }

    /* ---------------- sign-in ---------------- */

    /**
     * Check an assertion. Returns the row (owner_kind, owner_id, …) when the
     * signature is the registered device's; throws otherwise.
     *
     * @param array<string, mixed> $a {id, clientDataJSON, authenticatorData, signature}
     */
    public static function assert(array $a): array
    {
        if (!self::ready()) {
            throw new PasskeyError('Passkeys are not switched on yet.');
        }
        $challenge = self::takeChallenge('login');
        $credId = (string) ($a['id'] ?? '');
        if ($credId === '' || strlen($credId) > 700) {
            throw new PasskeyError('No passkey was offered.');
        }
        $row = Database::fetch('SELECT * FROM team_passkeys WHERE credential_id = :c LIMIT 1', ['c' => $credId]);
        if ($row === null) {
            throw new PasskeyError('यो डिभाइस दर्ता छैन — पासवर्डले भित्र गएर Face ID थप्नुहोस् · This device is not registered. Sign in with your password and add it.');
        }
        $clientRaw = self::fromB64u((string) ($a['clientDataJSON'] ?? ''));
        self::clientData((string) ($a['clientDataJSON'] ?? ''), 'webauthn.get', $challenge);
        $authRaw = self::fromB64u((string) ($a['authenticatorData'] ?? ''));
        $auth = self::authData($authRaw, false);
        if (($auth['flags'] & 0x01) === 0) {
            throw new PasskeyError('The device did not confirm your presence.');
        }
        $sig = self::fromB64u((string) ($a['signature'] ?? ''));
        $key = openssl_pkey_get_public((string) $row['public_key']);
        if ($key === false) {
            throw new PasskeyError('The stored key is unreadable — add this device again.');
        }
        $ok = openssl_verify($authRaw . hash('sha256', $clientRaw, true), $sig, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            Logger::warning('Passkey: bad signature', ['row' => (int) $row['id'], 'ip' => Security::clientIp()]);
            throw new PasskeyError('सही मिलेन — फेरि प्रयास गर्नुहोस् · The device signature did not verify.');
        }
        if ($auth['count'] > 0 && $auth['count'] <= (int) $row['sign_count']) {
            Logger::warning('Passkey: sign counter went backwards (cloned key?)', ['row' => (int) $row['id']]);
            throw new PasskeyError('यो डिभाइसको कुञ्जी शंकास्पद छ — कार्यालयमा खबर गर्नुहोस् · This device key looks cloned. Please tell the office.');
        }
        Database::update('team_passkeys', ['sign_count' => $auth['count'], 'last_used_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $row['id']]);
        return $row;
    }

    /* ---------------- pieces ---------------- */

    private static function clientData(string $b64u, string $type, string $challenge): array
    {
        $raw = self::fromB64u($b64u);
        $c = json_decode($raw, true);
        if (!is_array($c) || ($c['type'] ?? '') !== $type) {
            throw new PasskeyError('The device answer is not a ' . ($type === 'webauthn.get' ? 'sign-in' : 'registration') . '.');
        }
        if (!hash_equals($challenge, (string) ($c['challenge'] ?? ''))) {
            throw new PasskeyError('The challenge does not match — please try again.');
        }
        if ((string) ($c['origin'] ?? '') !== self::origin()) {
            throw new PasskeyError('This page is not ' . self::origin() . '.');
        }
        return $c;
    }

    /**
     * @return array{rpIdHash: string, flags: int, count: int, credId: string, cose: array}
     */
    private static function authData(string $d, bool $withCred): array
    {
        if (strlen($d) < 37) {
            throw new PasskeyError('The device data is too short.');
        }
        $rpHash = substr($d, 0, 32);
        if (!hash_equals(hash('sha256', self::rpId(), true), $rpHash)) {
            throw new PasskeyError('This passkey belongs to another site.');
        }
        $flags = ord($d[32]);
        $count = (int) unpack('N', substr($d, 33, 4))[1];
        $out = ['rpIdHash' => $rpHash, 'flags' => $flags, 'count' => $count, 'credId' => '', 'cose' => []];
        if ($withCred) {
            if (($flags & 0x40) === 0 || strlen($d) < 55) {
                throw new PasskeyError('The device sent no credential data.');
            }
            $len = (int) unpack('n', substr($d, 53, 2))[1];
            if ($len < 16 || $len > 1023 || strlen($d) < 55 + $len) {
                throw new PasskeyError('The credential id is malformed.');
            }
            $out['credId'] = substr($d, 55, $len);
            $pos = 55 + $len;
            $cose = self::cbor(substr($d, $pos), $pos2);
            if (!is_array($cose)) {
                throw new PasskeyError('The public key is unreadable.');
            }
            $out['cose'] = $cose;
        }
        return $out;
    }

    /** COSE_Key (EC2 P-256 or RSA) -> PEM SubjectPublicKeyInfo. */
    private static function cosePem(array $k): string
    {
        $kty = (int) ($k[1] ?? 0);
        $alg = (int) ($k[3] ?? 0);
        if ($kty === 2 && $alg === -7 && (int) ($k[-1] ?? 0) === 1 && is_string($k[-2] ?? null) && is_string($k[-3] ?? null)
            && strlen($k[-2]) === 32 && strlen($k[-3]) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
        } elseif ($kty === 3 && $alg === -257 && is_string($k[-1] ?? null) && is_string($k[-2] ?? null)) {
            $int = static function (string $b): string {
                $b = ltrim($b, "\x00");
                if ($b === '' || (ord($b[0]) & 0x80) !== 0) {
                    $b = "\x00" . $b;
                }
                return "\x02" . self::derLen(strlen($b)) . $b;
            };
            $rsa = $int($k[-1]) . $int($k[-2]);
            $rsa = "\x30" . self::derLen(strlen($rsa)) . $rsa;
            $bit = "\x00" . $rsa;
            $bit = "\x03" . self::derLen(strlen($bit)) . $bit;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = "\x30" . self::derLen(strlen($algId . $bit)) . $algId . $bit;
        } else {
            throw new PasskeyError('यो डिभाइसको कुञ्जी प्रकार चल्दैन (ES256 / RS256 मात्र) · Unsupported key type.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            throw new PasskeyError('The public key could not be read.');
        }
        return $pem;
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    /** A small CBOR decoder: ints, byte / text strings, arrays, maps (int / text keys), simple values. */
    public static function cbor(string $d, ?int &$end = null): mixed
    {
        $p = 0;
        $v = self::cborItem($d, $p, 0);
        $end = $p;
        return $v;
    }

    private static function cborItem(string $d, int &$p, int $depth): mixed
    {
        if ($depth > 8 || $p >= strlen($d)) {
            throw new PasskeyError('Bad CBOR.');
        }
        $b = ord($d[$p++]);
        $major = $b >> 5;
        $info = $b & 0x1f;
        if ($info < 24) {
            $n = $info;
        } elseif ($info === 24) {
            $n = ord($d[$p++]);
        } elseif ($info === 25) {
            $n = (int) unpack('n', substr($d, $p, 2))[1]; $p += 2;
        } elseif ($info === 26) {
            $n = (int) unpack('N', substr($d, $p, 4))[1]; $p += 4;
        } elseif ($info === 27) {
            $n = (int) unpack('J', substr($d, $p, 8))[1]; $p += 8;
        } else {
            throw new PasskeyError('Bad CBOR (indefinite length).');
        }
        switch ($major) {
            case 0: return $n;
            case 1: return -1 - $n;
            case 2:
            case 3:
                if ($n > 65536 || $p + $n > strlen($d)) {
                    throw new PasskeyError('Bad CBOR (string).');
                }
                $s = substr($d, $p, $n); $p += $n;
                return $s;
            case 4:
                $a = [];
                for ($i = 0; $i < $n && $i < 256; $i++) {
                    $a[] = self::cborItem($d, $p, $depth + 1);
                }
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $n && $i < 256; $i++) {
                    $k = self::cborItem($d, $p, $depth + 1);
                    $v = self::cborItem($d, $p, $depth + 1);
                    if (is_int($k) || is_string($k)) {
                        $m[$k] = $v;
                    }
                }
                return $m;
            case 6: return self::cborItem($d, $p, $depth + 1);   // tag: the item itself
            default:
                return match ($info) { 20 => false, 21 => true, 22 => null, default => null };
        }
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function fromB64u(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $b = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $b === false ? '' : $b;
    }
}
