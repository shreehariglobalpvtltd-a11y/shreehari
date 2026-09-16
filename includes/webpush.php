<?php
/**
 * =====================================================================
 *  includes/webpush.php — Web Push for the installed app (13 Sep 2026).
 *
 *  WHY IT EXISTS
 *  A delayed bus used to reach the passenger only over WhatsApp/SMS — and
 *  WhatsApp has been failing with 63112 (Meta disabled the WABA) since
 *  8 Sep. The phone's own notification channel needs no third party: the
 *  browser hands us an endpoint at one of the push services (Google,
 *  Mozilla, Apple), we encrypt a short message to the phone's key and POST
 *  it there. Free, instant, and it works with the app closed.
 *
 *  WHAT IS IN HERE (pure PHP, no composer — like includes/qr.php + pdf.php)
 *    · VAPID (RFC 8292): an EC P-256 key pair, generated ONCE on first use
 *      and kept in the settings table (is_public = 0), signs a short JWT
 *      per push so the push service knows the sender.
 *    · Message encryption (RFC 8291 + RFC 8188, "aes128gcm"): ECDH with the
 *      browser's key, HKDF, AES-128-GCM. Verified against the RFC 8291
 *      Appendix A vectors in tests/webpush-test.php.
 *    · The subscription store (push_subscriptions) keyed by endpoint hash,
 *      tied to a phone and the booking it was taken on, plus push_log so a
 *      dead endpoint or a rejected send is visible.
 *
 *  WHAT IT NEVER DOES
 *    · Touch money or seats — it only informs. Every caller already decided
 *      what happened (TripNotify / Notify); this just delivers.
 *    · Throw into a sale: every public method returns a result array and
 *      logs; it never propagates an exception to the caller.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WebPush
{
    /** Seconds a message may wait at the push service for a phone that is off. */
    public const TTL_DEFAULT = 86400;

    /** Record size for the one-record aes128gcm body (RFC 8188 default). */
    private const RECORD_SIZE = 4096;

    /* ---- DER scaffolding for P-256 keys -------------------------- */
    private const DER_SPKI_P256 = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";
    private const DER_OID_P256  = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

    /* =================================================================
     *  Encoding helpers
     * ================================================================= */

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s   = strtr(trim($s), '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        return $out === false ? '' : $out;
    }

    /* =================================================================
     *  Availability + keys
     * ================================================================= */

    /** Are the crypto primitives present on this PHP build? */
    public static function available(): bool
    {
        return function_exists('openssl_pkey_derive')
            && function_exists('hash_hkdf')
            && function_exists('openssl_encrypt')
            && function_exists('openssl_sign')
            && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    /** Master switch + keys present (keys are generated on first call). */
    public static function enabled(): bool
    {
        if (!self::available() || !Settings::getBool('push_enabled', true)) {
            return false;
        }
        return self::ensureKeys();
    }

    /** The VAPID public key the browser needs (base64url, 65 raw bytes). */
    public static function publicKey(): string
    {
        return self::ensureKeys() ? Settings::getString('push_vapid_public', '') : '';
    }

    /**
     * Generate the VAPID key pair once and store it. Returns false when the
     * host cannot generate EC keys (then push simply stays off).
     */
    public static function ensureKeys(): bool
    {
        $pub  = Settings::getString('push_vapid_public', '');
        $priv = Settings::getString('push_vapid_private', '');
        if ($pub !== '' && $priv !== '' && strlen(self::b64uDecode($pub)) === 65 && strlen(self::b64uDecode($priv)) === 32) {
            return true;
        }
        if (!self::available()) {
            return false;
        }
        $pair = self::generateKeyPair();
        if ($pair === null) {
            Logger::warning('WebPush: could not generate VAPID keys (openssl EC keygen unavailable)');
            return false;
        }
        try {
            Settings::set('push_vapid_public', self::b64u($pair['pub']), 'string', 'notify', false);
            Settings::set('push_vapid_private', self::b64u($pair['priv']), 'string', 'notify', false);
            Settings::flush();
        } catch (Throwable $e) {
            Logger::warning('WebPush: could not store VAPID keys', ['err' => $e->getMessage()]);
            return false;
        }
        Logger::info('WebPush: VAPID key pair generated');
        return true;
    }

    /**
     * A fresh P-256 key pair as raw bytes. Windows PHP builds need an
     * explicit openssl.cnf for key GENERATION (derive/sign/encrypt do not),
     * so a few candidate paths are tried after the plain attempt.
     *
     * @return array{pub: string, priv: string}|null  pub = 65-byte uncompressed point, priv = 32-byte scalar
     */
    public static function generateKeyPair(): ?array
    {
        $base = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $attempts = [$base];
        $cands = [];
        $env = (string) (getenv('OPENSSL_CONF') ?: '');
        if ($env !== '') { $cands[] = $env; }
        $cands[] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        $cands[] = PHP_BINDIR . '/extras/ssl/openssl.cnf';
        $cands[] = '/etc/ssl/openssl.cnf';
        foreach ($cands as $c) {
            if (is_file($c)) { $attempts[] = $base + ['config' => $c]; }
        }
        foreach ($attempts as $opts) {
            $key = @openssl_pkey_new($opts);
            if ($key === false) {
                while (openssl_error_string() !== false) { /* drain */ }
                continue;
            }
            $d = openssl_pkey_get_details($key);
            if (!is_array($d) || empty($d['ec']['x']) || empty($d['ec']['y']) || empty($d['ec']['d'])) {
                continue;
            }
            $x = str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $s = str_pad((string) $d['ec']['d'], 32, "\0", STR_PAD_LEFT);
            return ['pub' => "\x04" . $x . $y, 'priv' => $s];
        }
        return null;
    }

    /** PEM for a raw 65-byte uncompressed P-256 public point. */
    public static function publicPem(string $pubRaw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(self::DER_SPKI_P256 . $pubRaw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /** PEM (SEC1 ECPrivateKey) for a raw 32-byte scalar + its public point. */
    public static function privatePem(string $privRaw, string $pubRaw): string
    {
        $der = "\x30\x77"
            . "\x02\x01\x01"
            . "\x04\x20" . $privRaw
            . "\xa0\x0a" . self::DER_OID_P256
            . "\xa1\x44\x03\x42\x00" . $pubRaw;
        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * The public point that belongs to a raw private scalar (via openssl).
     * The SEC1 publicKey field is OPTIONAL: when it is absent OpenSSL computes
     * the point from the scalar on load (verified against the RFC 8291 key).
     */
    public static function derivePublic(string $privRaw): ?string
    {
        if (strlen($privRaw) !== 32) {
            return null;
        }
        $der = "\x30\x31\x02\x01\x01\x04\x20" . $privRaw . "\xa0\x0a" . self::DER_OID_P256;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
        $key = @openssl_pkey_get_private($pem);
        if ($key === false) {
            return null;
        }
        $d = openssl_pkey_get_details($key);
        if (!is_array($d) || empty($d['ec']['x']) || empty($d['ec']['y'])) {
            return null;
        }
        return "\x04" . str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                      . str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    /* =================================================================
     *  RFC 8291 encryption (aes128gcm content coding)
     * ================================================================= */

    /**
     * Encrypt a payload for one browser subscription.
     *
     * @param string      $plaintext  the JSON the service worker will read
     * @param string      $p256dhB64u browser public key (subscription.keys.p256dh)
     * @param string      $authB64u   browser auth secret (subscription.keys.auth)
     * @param string|null $asPrivRaw  test hook: fixed sender private key (else fresh)
     * @param string|null $salt       test hook: fixed 16-byte salt (else random)
     * @return string the complete aes128gcm body (header + ciphertext)
     * @throws RuntimeException when a key is malformed or a primitive fails
     */
    public static function encrypt(string $plaintext, string $p256dhB64u, string $authB64u, ?string $asPrivRaw = null, ?string $salt = null): string
    {
        $uaPub = self::b64uDecode($p256dhB64u);
        $auth  = self::b64uDecode($authB64u);
        if (strlen($uaPub) !== 65 || $uaPub[0] !== "\x04") {
            throw new RuntimeException('Subscription p256dh key is not a 65-byte uncompressed P-256 point.');
        }
        if (strlen($auth) !== 16) {
            throw new RuntimeException('Subscription auth secret is not 16 bytes.');
        }
        if (strlen($plaintext) > 3800) {
            throw new RuntimeException('Push payload too large (max ~3.8 KB).');
        }

        // Sender ("application server") key — ephemeral per message unless a
        // test pins it to the RFC vector.
        if ($asPrivRaw !== null) {
            $asPub = self::derivePublic($asPrivRaw);
            if ($asPub === null) {
                throw new RuntimeException('Could not derive the sender public key.');
            }
        } else {
            $pair = self::generateKeyPair();
            if ($pair === null) {
                throw new RuntimeException('EC key generation unavailable.');
            }
            $asPrivRaw = $pair['priv'];
            $asPub     = $pair['pub'];
        }
        $salt = $salt ?? random_bytes(16);
        if (strlen($salt) !== 16) {
            throw new RuntimeException('Salt must be 16 bytes.');
        }

        $priv = openssl_pkey_get_private(self::privatePem($asPrivRaw, $asPub));
        $peer = openssl_pkey_get_public(self::publicPem($uaPub));
        if ($priv === false || $peer === false) {
            throw new RuntimeException('Could not load EC keys for ECDH.');
        }
        $ecdh = openssl_pkey_derive($peer, $priv, 32);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) {
            throw new RuntimeException('ECDH failed.');
        }

        // RFC 8291 §3.3–3.4: IKM from the auth secret, then CEK + nonce from the salt.
        $keyInfo = "WebPush: info\x00" . $uaPub . $asPub;
        $ikm     = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $auth);
        $cek     = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce   = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // One record: plaintext || 0x02 (last-record delimiter), no extra padding.
        $tag    = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('AES-GCM encryption failed.');
        }

        // RFC 8188 header: salt(16) | rs(4) | idlen(1) | keyid(as_public 65)
        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $asPub . $cipher . $tag;
    }

    /**
     * Decrypt an aes128gcm body with the BROWSER's private key. Only tests
     * use this (the phone does it for real) — it proves the round trip.
     */
    public static function decrypt(string $body, string $uaPrivRaw, string $authRaw): string
    {
        if (strlen($body) < 16 + 4 + 1 + 65 + 17) {
            throw new RuntimeException('Body too short.');
        }
        $salt  = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPub = substr($body, 21, $idlen);
        $ct    = substr($body, 21 + $idlen);
        $uaPub = self::derivePublic($uaPrivRaw);
        if ($uaPub === null) {
            throw new RuntimeException('Bad UA private key.');
        }
        $priv = openssl_pkey_get_private(self::privatePem($uaPrivRaw, $uaPub));
        $peer = openssl_pkey_get_public(self::publicPem($asPub));
        if ($priv === false || $peer === false) {
            throw new RuntimeException('Could not load keys.');
        }
        $ecdh  = openssl_pkey_derive($peer, $priv, 32);
        $ikm   = hash_hkdf('sha256', (string) $ecdh, 32, "WebPush: info\x00" . $uaPub . $asPub, $authRaw);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $tag   = substr($ct, -16);
        $plain = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decrypt failed.');
        }
        // strip the delimiter (0x02 for the last record) and any zero padding
        $pos = strrpos($plain, "\x02");
        return $pos === false ? $plain : substr($plain, 0, $pos);
    }

    /* =================================================================
     *  VAPID (RFC 8292)
     * ================================================================= */

    /**
     * The Authorization header value for one push service origin.
     * Signed with the stored VAPID key; a fresh JWT per call (12 h expiry).
     */
    public static function vapidAuthorization(string $endpoint, ?string $privB64u = null, ?string $pubB64u = null): string
    {
        $privRaw = self::b64uDecode($privB64u ?? Settings::getString('push_vapid_private', ''));
        $pubRaw  = self::b64uDecode($pubB64u ?? Settings::getString('push_vapid_public', ''));
        if (strlen($privRaw) !== 32 || strlen($pubRaw) !== 65) {
            throw new RuntimeException('VAPID keys missing.');
        }
        $u = parse_url($endpoint);
        if (!is_array($u) || empty($u['scheme']) || empty($u['host'])) {
            throw new RuntimeException('Bad push endpoint.');
        }
        $aud     = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $subject = Settings::getString('push_vapid_subject', 'mailto:' . Settings::getString('company_email', 'shreehariglobalpvtltd@gmail.com'));
        if ($subject === '' || (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https://'))) {
            $subject = 'mailto:shreehariglobalpvtltd@gmail.com';
        }
        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        // Unescaped slashes: aud is https://host, the canonical claim form the push services document.
        $claims = self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $input  = $header . '.' . $claims;

        $key = openssl_pkey_get_private(self::privatePem($privRaw, $pubRaw));
        if ($key === false) {
            throw new RuntimeException('VAPID private key unreadable.');
        }
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }
        $jwt = $input . '.' . self::b64u(self::derSigToRaw($der));
        return 'vapid t=' . $jwt . ', k=' . self::b64u($pubRaw);
    }

    /** DER ECDSA-Sig-Value {r INTEGER, s INTEGER} → raw 64-byte r||s. */
    public static function derSigToRaw(string $der): string
    {
        $pos = 0;
        if (ord($der[$pos++]) !== 0x30) {
            throw new RuntimeException('Bad signature DER.');
        }
        $len = ord($der[$pos++]);
        if ($len & 0x80) { $pos += ($len & 0x7f); }
        $ints = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$pos++]) !== 0x02) {
                throw new RuntimeException('Bad signature DER.');
            }
            $l = ord($der[$pos++]);
            $v = substr($der, $pos, $l);
            $pos += $l;
            $v = ltrim($v, "\0");
            $ints[] = str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return $ints[0] . $ints[1];
    }

    /* =================================================================
     *  Subscription store
     * ================================================================= */

    /**
     * Validate the shape the browser posts: {endpoint, keys:{p256dh, auth}}.
     *
     * @return array{endpoint:string,p256dh:string,auth:string}|null
     */
    public static function normaliseSubscription(mixed $sub): ?array
    {
        if (!is_array($sub)) {
            return null;
        }
        $endpoint = trim((string) ($sub['endpoint'] ?? ''));
        $keys     = is_array($sub['keys'] ?? null) ? $sub['keys'] : [];
        $p256dh   = trim((string) ($keys['p256dh'] ?? ''));
        $auth     = trim((string) ($keys['auth'] ?? ''));
        if ($endpoint === '' || strlen($endpoint) > 1000 || !str_starts_with($endpoint, 'https://')) {
            return null;
        }
        if (strlen(self::b64uDecode($p256dh)) !== 65 || strlen(self::b64uDecode($auth)) !== 16) {
            return null;
        }
        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    /**
     * Insert or refresh one subscription. Returns the row id.
     *
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @param array{phone?:string,user_id?:int|null,booking_id?:int|null,lang?:string,ua?:string} $ctx
     */
    public static function subscribe(array $sub, array $ctx = []): int
    {
        $hash  = sha1($sub['endpoint']);
        $phone = normalisePhone((string) ($ctx['phone'] ?? ''));
        $row   = Database::fetch('SELECT id, phone, booking_id FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => $hash]);
        $data  = [
            'p256dh'     => $sub['p256dh'],
            'auth'       => $sub['auth'],
            'lang'       => substr((string) ($ctx['lang'] ?? 'ne'), 0, 5),
            'ua'         => mb_substr((string) ($ctx['ua'] ?? ''), 0, 160),
            'is_active'  => 1,
            'fail_count' => 0,
        ];
        if ($phone !== '') { $data['phone'] = $phone; }
        if (!empty($ctx['user_id'])) { $data['user_id'] = (int) $ctx['user_id']; }
        if (!empty($ctx['booking_id'])) { $data['booking_id'] = (int) $ctx['booking_id']; }

        if ($row !== null) {
            Database::update('push_subscriptions', $data, 'id = :i', ['i' => (int) $row['id']]);
            return (int) $row['id'];
        }
        $data['endpoint']      = $sub['endpoint'];
        $data['endpoint_hash'] = $hash;
        $data['phone']         = $phone;
        return Database::insert('push_subscriptions', $data);
    }

    /** Forget an endpoint (the browser unsubscribed, or the user said no). */
    public static function unsubscribe(string $endpoint): bool
    {
        $n = Database::update('push_subscriptions', ['is_active' => 0], 'endpoint_hash = :h', ['h' => sha1(trim($endpoint))]);
        return $n > 0;
    }

    /**
     * pushsubscriptionchange: the push service rotated the endpoint. The old
     * one must exist — that is the proof this request is genuine.
     */
    public static function resubscribe(string $oldEndpoint, array $sub): ?int
    {
        $old = Database::fetch('SELECT * FROM push_subscriptions WHERE endpoint_hash = :h', ['h' => sha1(trim($oldEndpoint))]);
        if ($old === null) {
            return null;
        }
        $id = self::subscribe($sub, [
            'phone'      => (string) $old['phone'],
            'user_id'    => $old['user_id'] !== null ? (int) $old['user_id'] : null,
            'booking_id' => $old['booking_id'] !== null ? (int) $old['booking_id'] : null,
            'lang'       => (string) $old['lang'],
            'ua'         => (string) $old['ua'],
        ]);
        if ($id !== (int) $old['id']) {
            Database::update('push_subscriptions', ['is_active' => 0], 'id = :i', ['i' => (int) $old['id']]);
        }
        return $id;
    }

    /** Active subscriptions that belong to a booking (by id, or by its phone). */
    public static function forBooking(int $bookingId): array
    {
        $b = Database::fetch('SELECT id, contact_phone FROM bookings WHERE id = :b', ['b' => $bookingId]);
        if ($b === null) {
            return [];
        }
        $phone = normalisePhone((string) ($b['contact_phone'] ?? ''));
        if ($phone !== '' && $phone !== '0000000000') {
            return Database::fetchAll(
                'SELECT * FROM push_subscriptions WHERE is_active = 1 AND (booking_id = :b OR phone = :p) ORDER BY id DESC LIMIT 10',
                ['b' => $bookingId, 'p' => $phone]
            );
        }
        return Database::fetchAll(
            'SELECT * FROM push_subscriptions WHERE is_active = 1 AND booking_id = :b ORDER BY id DESC LIMIT 10',
            ['b' => $bookingId]
        );
    }

    public static function forPhone(string $phone): array
    {
        $phone = normalisePhone($phone);
        if ($phone === '' || $phone === '0000000000') {
            return [];
        }
        return Database::fetchAll(
            'SELECT * FROM push_subscriptions WHERE is_active = 1 AND phone = :p ORDER BY id DESC LIMIT 10',
            ['p' => $phone]
        );
    }

    /* =================================================================
     *  Sending
     * ================================================================= */

    /**
     * Encrypt + POST one message to one subscription row.
     *
     * @param array<string,mixed> $sub     a push_subscriptions row
     * @param array<string,mixed> $payload {title, body, url, tag, ...} — JSON for sw.js
     * @return array{ok:bool, http:int, error:string}
     */
    public static function send(array $sub, array $payload, int $ttl = self::TTL_DEFAULT, string $urgency = 'high'): array
    {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        $res = ['ok' => false, 'http' => 0, 'error' => ''];
        try {
            if (!self::available() || !self::ensureKeys()) {
                $res['error'] = 'push not available';
                return $res;
            }
            if (!function_exists('curl_init')) {
                $res['error'] = 'no curl';
                return $res;
            }
            $payload += ['icon' => '/assets/img/icon-192.png', 'badge' => '/assets/img/icon-192.png', 'ts' => time()];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $res['error'] = 'payload not encodable';
                return $res;
            }
            $body = self::encrypt($json, (string) $sub['p256dh'], (string) $sub['auth']);
            $auth = self::vapidAuthorization($endpoint);

            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'Content-Length: ' . strlen($body),
                    'TTL: ' . max(0, $ttl),
                    'Urgency: ' . (in_array($urgency, ['very-low', 'low', 'normal', 'high'], true) ? $urgency : 'normal'),
                    'Authorization: ' . $auth,
                ],
            ]);
            $out  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            $res['http'] = $code;
            $res['ok']   = $code >= 200 && $code < 300;
            if (!$res['ok']) {
                $res['error'] = $err !== '' ? $err : ('HTTP ' . $code . ' ' . mb_substr(trim((string) $out), 0, 160));
            }
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
        }
        self::afterSend($sub, $res, $payload);
        return $res;
    }

    /** Bookkeeping after one send: dead endpoints go inactive, everything is logged. */
    private static function afterSend(array $sub, array $res, array $payload): void
    {
        $id = (int) ($sub['id'] ?? 0);
        try {
            if ($id > 0) {
                $upd = ['last_status' => $res['http'] > 0 ? $res['http'] : null, 'last_sent_at' => date('Y-m-d H:i:s')];
                if ($res['ok']) {
                    $upd['fail_count'] = 0;
                } else {
                    // 404 / 410 = the browser unsubscribed or the endpoint expired: gone for good.
                    $gone = in_array($res['http'], [404, 410], true);
                    $fails = (int) ($sub['fail_count'] ?? 0) + 1;
                    $upd['fail_count'] = min(250, $fails);
                    if ($gone || $fails >= 8) {
                        $upd['is_active'] = 0;
                    }
                }
                Database::update('push_subscriptions', $upd, 'id = :i', ['i' => $id]);
            }
            Database::insert('push_log', [
                'subscription_id' => $id > 0 ? $id : null,
                'booking_id'      => isset($payload['bookingId']) ? (int) $payload['bookingId'] : null,
                'event'           => mb_substr((string) ($payload['event'] ?? ''), 0, 40),
                'title'           => mb_substr((string) ($payload['title'] ?? ''), 0, 191),
                'http_status'     => $res['http'] > 0 ? $res['http'] : null,
                'ok'              => $res['ok'] ? 1 : 0,
                'error'           => $res['error'] !== '' ? mb_substr($res['error'], 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // push_log missing (migration not applied) must not break the caller
        }
    }

    /**
     * Push one message to every phone that subscribed on this booking (or
     * with its mobile number). Never throws.
     *
     * @return array{sent:int, failed:int, skipped:int}
     */
    public static function sendToBooking(int $bookingId, array $payload, string $event = ''): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        try {
            if (!self::enabled()) {
                $out['skipped']++;
                return $out;
            }
            $subs = self::forBooking($bookingId);
        } catch (Throwable $e) {
            $out['skipped']++;
            return $out;
        }
        $payload['bookingId'] = $bookingId;
        $payload['event']     = $event;
        foreach ($subs as $sub) {
            $r = self::send($sub, $payload);
            $r['ok'] ? $out['sent']++ : $out['failed']++;
        }
        return $out;
    }

    /** @return array{sent:int, failed:int, skipped:int} */
    public static function sendToPhone(string $phone, array $payload, string $event = ''): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        try {
            if (!self::enabled()) {
                $out['skipped']++;
                return $out;
            }
            $subs = self::forPhone($phone);
        } catch (Throwable $e) {
            $out['skipped']++;
            return $out;
        }
        $payload['event'] = $event;
        foreach ($subs as $sub) {
            $r = self::send($sub, $payload);
            $r['ok'] ? $out['sent']++ : $out['failed']++;
        }
        return $out;
    }

    /**
     * The message for a trip lifecycle event — short, bilingual, one tap
     * opens the ticket. Mirrors the WhatsApp wording in Notify::tripEventBody
     * but fits a lock-screen banner.
     *
     * @param array{route?:string,date?:string,depTime?:string,seats?:string,delayMinutes?:int|string,delayNote?:string} $f
     * @return array{title:string, body:string, url:string, tag:string, lang:string}
     */
    public static function tripEventPayload(string $event, string $pnr, array $f, string $company = ''): array
    {
        $company = $company !== '' ? $company : Settings::getString('company_name', APP_NAME);
        $route   = trim((string) ($f['route'] ?? ''));
        $when    = trim((string) ($f['date'] ?? '') . ((string) ($f['depTime'] ?? '') !== '' ? ' · ' . $f['depTime'] : ''));
        $seats   = trim((string) ($f['seats'] ?? ''));
        $mins    = (int) ($f['delayMinutes'] ?? 0);
        $note    = trim((string) ($f['delayNote'] ?? ''));
        [$title, $body] = match ($event) {
            'delayed' => [
                '⏳ Bus delayed' . ($mins > 0 ? ' ~' . $mins . ' min' : '') . ' · बस ढिलो',
                ($route !== '' ? $route . "\n" : '') . ($when !== '' ? 'Was: ' . $when . "\n" : '')
                . ($note !== '' ? $note . "\n" : '') . 'We apologise — the bus leaves at the updated time. Kripaya dhairya rakhnuhos.',
            ],
            'reminder_12h' => [
                '🚌 Yatra bholi · Trip tomorrow — ' . $pnr,
                ($route !== '' ? $route . "\n" : '') . ($when !== '' ? 'Departure: ' . $when . "\n" : '')
                . ($seats !== '' ? 'Seat ' . $seats . "\n" : '') . 'Photo ID sathai lyaunuhos — border ma chahincha.',
            ],
            'reminder_2h' => [
                '⏰ Bus in ~2 hours · २ घण्टामा बस — ' . $pnr,
                ($route !== '' ? $route . "\n" : '') . ($seats !== '' ? 'Seat ' . $seats . "\n" : '') . 'Please reach the boarding point on time · समयमै आउनुहोस्।',
            ],
            'departed' => [
                '🚌 Bus hindyo · Bus has departed — ' . $pnr,
                ($route !== '' ? $route . "\n" : '') . 'Safe travels 🙏',
            ],
            'border' => [
                '🛂 Border pugyo · At the border — ' . $pnr,
                'Keep your photo ID ready for the crossing · आफ्नो फोटो ID तयार राख्नुहोस्।',
            ],
            'arrived' => [
                '🏁 Arrived · पुग्यो — ' . $pnr,
                'Thank you for travelling with ' . $company . ' 🙏',
            ],
            'ticket_ready' => [
                '🎫 Ticket ready · टिकट तयार — ' . $pnr,
                ($route !== '' ? $route . "\n" : '') . ($when !== '' ? $when . "\n" : '') . 'Tap to open your e-ticket.',
            ],
            default => [$company . ' — ' . $pnr, $route],
        };
        return [
            'title' => mb_substr($title, 0, 120),
            'body'  => mb_substr(trim($body), 0, 400),
            'url'   => rtrim(APP_URL, '/') . '/#/ticket/' . rawurlencode($pnr),
            'tag'   => 'shg-' . $event . '-' . strtolower($pnr),
            'lang'  => 'ne',
        ];
    }
}
