<?php
/**
 * =====================================================================
 *  includes/agentpro-passkey.php — sign in to /me/ with the phone's
 *  fingerprint or face unlock (owner: "face id ra fingerprint").
 *
 *  A browser never hands a website a fingerprint — by design, and it
 *  cannot be worked around. What it does offer is a PASSKEY (WebAuthn):
 *  the phone checks the finger itself and signs a one-time challenge with
 *  a key that never leaves the phone. We keep only the public half.
 *  Stronger than a password or an OTP, and immune to phishing: the key
 *  only ever signs for this website's own address.
 *
 *  Self-contained: a small CBOR reader, COSE → PEM, OpenSSL to verify.
 *  ES256 (-7) and RS256 (-257) — what Android, iPhone and Windows Hello use.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ApPasskey
{
    private const TTL = 300;

    /** The site's host — passkeys are bound to it. */
    public static function rpId(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST) ?? 'localhost');
        return strtolower((string) preg_replace('/:\d+$/', '', $host));
    }

    public static function origin(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? parse_url(APP_URL, PHP_URL_HOST) ?? 'localhost');
        return (Security::isHttps() ? 'https' : 'http') . '://' . strtolower($host);
    }

    /* ---------------- register (signed in) ---------------- */

    /** @return array<string, mixed> PublicKeyCredentialCreationOptions (binary as base64url) */
    public static function registerOptions(array $admin): array
    {
        $challenge = random_bytes(32);
        $_SESSION['ap_pk'] = ['c' => self::b64u($challenge), 'at' => time(), 'for' => 'reg', 'admin' => (int) $admin['id']];
        $exclude = [];
        foreach (Database::fetchAll('SELECT credential_id FROM ap_passkeys WHERE admin_id = :a', ['a' => (int) $admin['id']]) as $r) {
            $exclude[] = ['type' => 'public-key', 'id' => (string) $r['credential_id']];
        }
        return [
            'challenge' => self::b64u($challenge),
            'rp' => ['id' => self::rpId(), 'name' => Settings::getString('company_name', APP_NAME)],
            'user' => [
                'id' => self::b64u('shg-admin-' . (int) $admin['id']),
                'name' => (string) ($admin['username'] ?? ('member' . $admin['id'])),
                'displayName' => (string) ($admin['full_name'] ?? ''),
            ],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['userVerification' => 'required', 'residentKey' => 'preferred', 'requireResidentKey' => false],
            'attestation' => 'none',
            'timeout' => 60000,
            'excludeCredentials' => $exclude,
        ];
    }

    /**
     * @param array{id: string, clientDataJSON: string, attestationObject: string} $cred base64url fields
     */
    public static function registerVerify(array $admin, array $cred, string $label = ''): int
    {
        $st = self::takeChallenge('reg');
        if ($st === null || (int) $st['admin'] !== (int) $admin['id']) {
            throw new RuntimeException('This fingerprint request expired. Try again.');
        }
        $client = self::clientData((string) $cred['clientDataJSON'], 'webauthn.create', (string) $st['c']);
        $att = self::cbor(self::unb64u((string) $cred['attestationObject']));
        if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
            throw new RuntimeException('The phone sent an unreadable answer.');
        }
        $auth = self::authData($att['authData'], true);
        if (!hash_equals(hash('sha256', self::rpId(), true), $auth['rpIdHash'])) {
            throw new RuntimeException('That passkey belongs to another website.');
        }
        if (!($auth['flags'] & 0x01) || !($auth['flags'] & 0x04)) {
            throw new RuntimeException('The fingerprint / face check was not completed on the phone.');
        }
        [$pem, $alg] = self::coseToPem($auth['coseKey']);
        $credId = self::b64u($auth['credId']);
        if ($credId !== rtrim((string) $cred['id'], '=')) {
            throw new RuntimeException('The phone sent a mismatched passkey.');
        }
        unset($client);
        $id = Database::insert('ap_passkeys', [
            'admin_id' => (int) $admin['id'], 'credential_id' => $credId, 'public_key_pem' => $pem, 'alg' => $alg,
            'sign_count' => $auth['signCount'], 'label' => Security::clean($label !== '' ? $label : self::deviceLabel(), 80),
        ]);
        Logger::audit('agentpro.passkey_added', 'admin', (string) $admin['id'], null, ['label' => $label], 'Fingerprint / face sign-in added');
        return $id;
    }

    /* ---------------- sign in (not signed in) ---------------- */

    /** @return array<string, mixed> PublicKeyCredentialRequestOptions */
    public static function loginOptions(): array
    {
        $challenge = random_bytes(32);
        $_SESSION['ap_pk'] = ['c' => self::b64u($challenge), 'at' => time(), 'for' => 'login'];
        return [
            'challenge' => self::b64u($challenge),
            'rpId' => self::rpId(),
            'userVerification' => 'required',
            'timeout' => 60000,
            'allowCredentials' => [],            // discoverable: the phone offers the right key itself
        ];
    }

    /**
     * Verify a sign-in. Returns the admins row it proves.
     *
     * @param array{id: string, clientDataJSON: string, authenticatorData: string, signature: string} $cred
     * @return array<string, mixed>
     */
    public static function loginVerify(array $cred): array
    {
        $st = self::takeChallenge('login');
        if ($st === null) {
            throw new RuntimeException('This sign-in request expired. Try again.');
        }
        $credId = rtrim((string) ($cred['id'] ?? ''), '=');
        $row = Database::fetch('SELECT * FROM ap_passkeys WHERE credential_id = :c', ['c' => $credId]);
        if ($row === null) {
            throw new RuntimeException('This phone\'s fingerprint is not set up for any account yet. Sign in once with OTP and add it.');
        }
        $clientRaw = self::unb64u((string) $cred['clientDataJSON']);
        self::clientData((string) $cred['clientDataJSON'], 'webauthn.get', (string) $st['c']);
        $authRaw = self::unb64u((string) $cred['authenticatorData']);
        $auth = self::authData($authRaw, false);
        if (!hash_equals(hash('sha256', self::rpId(), true), $auth['rpIdHash'])) {
            throw new RuntimeException('That passkey belongs to another website.');
        }
        if (!($auth['flags'] & 0x01) || !($auth['flags'] & 0x04)) {
            throw new RuntimeException('The fingerprint / face check was not completed on the phone.');
        }
        $ok = openssl_verify($authRaw . hash('sha256', $clientRaw, true), self::unb64u((string) $cred['signature']),
            (string) $row['public_key_pem'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            Logger::warning('Passkey signature rejected', ['admin' => (int) $row['admin_id']], 'security');
            throw new RuntimeException('The fingerprint sign-in could not be verified.');
        }
        // A counter that goes backwards means a copied key: refuse.
        if ($auth['signCount'] > 0 || (int) $row['sign_count'] > 0) {
            if ($auth['signCount'] <= (int) $row['sign_count']) {
                Logger::warning('Passkey counter went backwards', ['admin' => (int) $row['admin_id']], 'security');
                throw new RuntimeException('This passkey looks copied. Sign in with OTP and add it again.');
            }
        }
        Database::update('ap_passkeys', ['sign_count' => $auth['signCount'], 'last_used_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $row['id']]);
        $admin = Database::fetch(
            'SELECT id, username, full_name, phone, role, permissions, is_active, locked_until, must_change_pw FROM admins WHERE id = :i',
            ['i' => (int) $row['admin_id']]
        );
        if ($admin === null || (int) $admin['is_active'] !== 1
            || (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time())) {
            throw new RuntimeException('This account is not active.');
        }
        return $admin;
    }

    /**
     * Open the staff session exactly the way every other door does —
     * Auth's own session bootstrap (counters, LoginLog, device check, audit),
     * reached by reflection because it is deliberately private.
     */
    public static function startSession(array $admin): void
    {
        $m = new ReflectionMethod(Auth::class, 'finishAdminSession');
        $m->setAccessible(true);
        $m->invoke(null, $admin, Security::clientIp(), true);
        Logger::audit('agentpro.passkey_login', 'admin', (string) $admin['id'], null, null, 'Signed in with fingerprint / face');
    }

    /* ---------------- pieces ---------------- */

    private static function takeChallenge(string $for): ?array
    {
        $st = $_SESSION['ap_pk'] ?? null;
        unset($_SESSION['ap_pk']);                       // single use
        if (!is_array($st) || ($st['for'] ?? '') !== $for || (int) ($st['at'] ?? 0) < time() - self::TTL) {
            return null;
        }
        return $st;
    }

    /** @return array<string, mixed> */
    private static function clientData(string $b64, string $type, string $challenge): array
    {
        $c = json_decode(self::unb64u($b64), true);
        if (!is_array($c) || ($c['type'] ?? '') !== $type) {
            throw new RuntimeException('The phone sent an unexpected answer.');
        }
        if (!hash_equals($challenge, rtrim((string) ($c['challenge'] ?? ''), '='))) {
            throw new RuntimeException('This request expired. Try again.');
        }
        if (rtrim((string) ($c['origin'] ?? ''), '/') !== self::origin()) {
            throw new RuntimeException('The passkey was used on another website address.');
        }
        return $c;
    }

    /** @return array{rpIdHash: string, flags: int, signCount: int, credId?: string, coseKey?: array} */
    private static function authData(string $d, bool $withCred): array
    {
        if (strlen($d) < 37) {
            throw new RuntimeException('The phone sent a short answer.');
        }
        $out = ['rpIdHash' => substr($d, 0, 32), 'flags' => ord($d[32]), 'signCount' => unpack('N', substr($d, 33, 4))[1]];
        if ($withCred) {
            if (!($out['flags'] & 0x40) || strlen($d) < 55) {
                throw new RuntimeException('The phone did not send a key.');
            }
            $len = unpack('n', substr($d, 53, 2))[1];
            $out['credId'] = substr($d, 55, $len);
            $pos = 55 + $len;
            $out['coseKey'] = self::cborAt($d, $pos);
            if (!is_array($out['coseKey'])) {
                throw new RuntimeException('The phone sent an unreadable key.');
            }
        }
        return $out;
    }

    /** @return array{0: string, 1: int} PEM + COSE alg */
    public static function coseToPem(array $k): array
    {
        $kty = (int) ($k[1] ?? 0);
        $alg = (int) ($k[3] ?? 0);
        if ($kty === 2) {                                  // EC2 P-256
            $x = (string) ($k[-2] ?? '');
            $y = (string) ($k[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new RuntimeException('Unsupported key.');
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return [self::pem($der), $alg ?: -7];
        }
        if ($kty === 3) {                                  // RSA
            $n = (string) ($k[-1] ?? '');
            $e = (string) ($k[-2] ?? '');
            $seq = self::derSeq(self::derInt($n) . self::derInt($e));
            $bit = "\x03" . self::derLen(strlen($seq) + 1) . "\x00" . $seq;
            $algId = hex2bin('300d06092a864886f70d0101010500');
            return [self::pem(self::derSeq($algId . $bit)), $alg ?: -257];
        }
        throw new RuntimeException('This phone uses a key type we do not support yet.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $inner): string
    {
        return "\x30" . self::derLen(strlen($inner)) . $inner;
    }

    /* ---------------- a small CBOR reader (RFC 8949, the parts WebAuthn uses) ---------------- */

    public static function cbor(string $data): mixed
    {
        $pos = 0;
        return self::cborAt($data, $pos);
    }

    public static function cborAt(string $d, int &$pos): mixed
    {
        if ($pos >= strlen($d)) {
            throw new RuntimeException('CBOR: unexpected end.');
        }
        $ib = ord($d[$pos++]);
        $major = $ib >> 5;
        $info = $ib & 0x1F;
        $val = match (true) {
            $info < 24  => $info,
            $info === 24 => ord($d[$pos++]),
            $info === 25 => unpack('n', substr($d, ($pos += 2) - 2, 2))[1],
            $info === 26 => unpack('N', substr($d, ($pos += 4) - 4, 4))[1],
            $info === 27 => (static function () use ($d, &$pos): int { $v = unpack('J', substr($d, $pos, 8))[1]; $pos += 8; return $v; })(),
            default      => throw new RuntimeException('CBOR: indefinite lengths are not used by WebAuthn.'),
        };
        switch ($major) {
            case 0: return $val;
            case 1: return -1 - $val;
            case 2:
            case 3:
                $s = substr($d, $pos, $val);
                $pos += $val;
                return $s;
            case 4:
                $a = [];
                for ($i = 0; $i < $val; $i++) { $a[] = self::cborAt($d, $pos); }
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = self::cborAt($d, $pos);
                    $m[is_int($k) || is_string($k) ? $k : (string) json_encode($k)] = self::cborAt($d, $pos);
                }
                return $m;
            case 6: return self::cborAt($d, $pos);       // a tag: the value matters, not the tag
            case 7:
                return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => $val };
        }
        throw new RuntimeException('CBOR: unknown type.');
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        $s = strtr(trim($s), '-_', '+/');
        $d = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($d === false) {
            throw new RuntimeException('Bad encoding from the phone.');
        }
        return $d;
    }

    private static function deviceLabel(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android phone',
            str_contains($ua, 'Windows') => 'Windows PC',
            str_contains($ua, 'Mac') => 'Mac',
            default => 'This device',
        };
    }
}
