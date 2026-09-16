<?php
/**
 * =====================================================================
 *  Security layer
 *
 *  CSRF tokens, output escaping, input validation, rate limiting,
 *  upload inspection, HMAC signing and secure response headers.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Security
{
    /* =================================================================
     *  Response headers
     * ================================================================= */

    /**
     * Send hardening headers. Called once from bootstrap.
     */
    public static function sendHeaders(bool $isApi = false): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 0'); // modern browsers: CSP is the real defence
        header('Permissions-Policy: geolocation=(self), microphone=(self), camera=(self)');
        header_remove('X-Powered-By');

        if (FORCE_HTTPS && self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        if (!$isApi) {
            // The booking UI loads map tiles and routing from open providers,
            // so those hosts are allowed explicitly rather than via a wildcard.
            $csp = "default-src 'self'; "
                 . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com; "
                 . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; "
                 . "font-src 'self' data: https://fonts.gstatic.com; "
                 . "img-src 'self' data: blob: https:; "
                 // 4 Sep 2026: photon (primary geocoder), overpass (nearby
                 // temples / POI chips), Esri imagery and OpenTopoMap (the
                 // Satellite / Terrain map styles) were missing, so those four
                 // features failed silently in production. All keyless.
                 . "connect-src 'self' https://tiles.openfreemap.org https://valhalla1.openstreetmap.de "
                 . "https://nominatim.openstreetmap.org https://photon.komoot.io https://overpass-api.de "
                 . "https://server.arcgisonline.com https://tile.opentopomap.org "
                 // 5 Sep 2026: Terrarium elevation tiles (3D terrain) — keyless.
                 . "https://s3.amazonaws.com "
                 . "https://api.open-meteo.com https://router.project-osrm.org "
                 // 13 Sep 2026: live INR→NPR check on the Border Crossing Prep card — keyless.
                 . "https://open.er-api.com; "
                 . "worker-src 'self' blob:; "
                 . "frame-ancestors 'self'; "
                 . "base-uri 'self'; "
                 . "form-action 'self'";
            header('Content-Security-Policy: ' . $csp);
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // Hostinger sits behind a proxy that sets this.
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    /**
     * Redirect http -> https. No-op when already secure or on CLI.
     */
    public static function enforceHttps(): void
    {
        if (!FORCE_HTTPS || PHP_SAPI === 'cli' || self::isHttps()) {
            return;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host === '') {
            return;
        }
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }


    /* =================================================================
     *  CSRF
     * ================================================================= */

    public static function csrfToken(): string
    {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[CSRF_TOKEN_NAME];
    }

    /**
     * Hidden input for classic form posts.
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . CSRF_TOKEN_NAME
             . '" value="' . self::e(self::csrfToken()) . '">';
    }

    /**
     * Constant-time comparison. Accepts the token from POST body or from
     * the X-CSRF-Token header (used by the JSON API).
     */
    public static function verifyCsrf(?string $token = null): bool
    {
        if ($token === null) {
            $token = $_POST[CSRF_TOKEN_NAME]
                  ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? '';
        }
        $expected = $_SESSION[CSRF_TOKEN_NAME] ?? '';

        if (!is_string($token) || $token === '' || $expected === '') {
            return false;
        }
        return hash_equals((string) $expected, $token);
    }

    /**
     * Verify or abort. Use at the top of every state-changing endpoint.
     */
    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf()) {
            Logger::warning('CSRF rejected', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            Response::error('Security token expired. Please refresh the page and try again.', 419);
        }
    }


    /* =================================================================
     *  Output escaping
     * ================================================================= */

    /**
     * HTML-escape. Use on every echo of dynamic data.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Safe JSON for embedding inside a <script> block.
     */
    public static function jsonForHtml(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';
    }


    /* =================================================================
     *  Input handling
     * ================================================================= */

    /**
     * Trim and strip control characters. Does NOT html-escape — escaping
     * belongs at output time, not input time.
     */
    public static function clean(mixed $value, int $maxLength = 0): string
    {
        $s = trim((string) ($value ?? ''));
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        if ($maxLength > 0 && mb_strlen($s, 'UTF-8') > $maxLength) {
            $s = mb_substr($s, 0, $maxLength, 'UTF-8');
        }
        return $s;
    }

    /** Digits only — for phone numbers, UTR and OTP. */
    public static function digits(mixed $value, int $maxLength = 0): string
    {
        $s = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($maxLength > 0 && strlen($s) > $maxLength) {
            $s = substr($s, 0, $maxLength);
        }
        return $s;
    }

    public static function email(mixed $value): string
    {
        $s = self::clean($value, 191);
        return filter_var($s, FILTER_VALIDATE_EMAIL) === false ? '' : strtolower($s);
    }

    /**
     * Indian (10 digit) or Nepali (10 digit) mobile, stored without the
     * country code prefix.
     */
    public static function isValidPhone(string $digits, bool $staff = false): bool
    {
        $len = strlen($digits);
        /* A DESK may record 8-15 digits. The counter UI has promised exactly
           that since 5 Sep ("Blank OK — or 8-15 digits (Indian / Nepali)") and
           the browser validator accepts it, but the server never had the
           widened branch: a staff-typed 8- or 9-digit number passed the form
           and was then refused with the generic "valid mobile number", which is
           the shape of bug that makes a desk retype a correct number until it
           gives up. Customers online are unchanged at 10-13. */
        if ($staff) {
            return $len >= 8 && $len <= 15;
        }
        return $len >= 10 && $len <= 13;
    }

    public static function isValidDate(string $iso): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $iso));
        return checkdate($m, $d, $y);
    }

    /** Seat identifiers are like 4C, L12, U7 — nothing else is accepted. */
    public static function isValidSeat(string $seat): bool
    {
        return preg_match('/^[A-Z0-9]{1,10}$/', $seat) === 1;
    }

    public static function isValidPnr(string $pnr): bool
    {
        return preg_match('/^SHG-[A-Z0-9\-]{4,36}$/i', $pnr) === 1;
    }


    /* =================================================================
     *  Rate limiting  (DB backed so it survives across PHP workers)
     * ================================================================= */

    /**
     * Returns true when the caller is allowed to proceed.
     *
     * @param string $bucket     action name, e.g. 'otp_request'
     * @param string $identifier IP address or phone number
     */
    public static function rateLimit(
        string $bucket,
        string $identifier,
        int $maxHits = 10,
        int $windowSeconds = 60,
        int $lockoutSeconds = 0
    ): bool {
        $now       = time();
        $nowSql    = date('Y-m-d H:i:s', $now);
        $windowSql = date('Y-m-d H:i:s', $now - $windowSeconds);

        /* 4 Sep 2026: one atomic upsert instead of SELECT-then-UPDATE. Two
           requests arriving together used to read the same count and both
           write count+1, so every budget was a soft ceiling under a burst.
           The counter now moves inside the database: a fresh row starts at 1,
           a live window increments, an expired window restarts at 1, and a
           row still serving a lockout is left exactly as it is. (Every
           placeholder is named once — this PDO does not emulate prepares.) */
        Database::query(
            'INSERT INTO rate_limits (bucket, identifier, hits, window_start, blocked_until)
                  VALUES (:b, :i, 1, :w0, NULL)
             ON DUPLICATE KEY UPDATE
                  hits = IF(blocked_until IS NOT NULL AND blocked_until > :n1, hits,
                            IF(window_start < :ws1, 1, hits + 1)),
                  window_start = IF(blocked_until IS NOT NULL AND blocked_until > :n2, window_start,
                                    IF(window_start < :ws2, :w1, window_start)),
                  blocked_until = IF(blocked_until IS NOT NULL AND blocked_until > :n3, blocked_until, NULL)',
            [
                'b' => $bucket, 'i' => $identifier, 'w0' => $nowSql,
                'n1' => $nowSql, 'ws1' => $windowSql,
                'n2' => $nowSql, 'ws2' => $windowSql, 'w1' => $nowSql,
                'n3' => $nowSql,
            ]
        );

        $row = Database::fetch(
            'SELECT id, hits, window_start, blocked_until
               FROM rate_limits
              WHERE bucket = :b AND identifier = :i
              LIMIT 1',
            ['b' => $bucket, 'i' => $identifier]
        );
        if ($row === null) {
            return true;   // table missing or write failed: never lock a customer out for it
        }

        // Still serving a lockout?
        if (!empty($row['blocked_until']) && strtotime((string) $row['blocked_until']) > $now) {
            return false;
        }

        $hits = (int) $row['hits'];

        if ($hits > $maxHits) {
            Database::update('rate_limits', [
                'hits'          => $hits,
                'blocked_until' => $lockoutSeconds > 0
                    ? date('Y-m-d H:i:s', $now + $lockoutSeconds)
                    : date('Y-m-d H:i:s', $now + $windowSeconds),
            ], 'id = :id', ['id' => $row['id']]);

            Logger::warning('Rate limit hit', ['bucket' => $bucket, 'id' => $identifier]);
            return false;
        }

        return true;   // the upsert above already counted this hit
    }

    /**
     * Rate limit or abort with 429.
     */
    public static function requireRateLimit(
        string $bucket,
        string $identifier,
        int $maxHits = 10,
        int $windowSeconds = 60
    ): void {
        if (!self::rateLimit($bucket, $identifier, $maxHits, $windowSeconds)) {
            Response::error('Too many attempts. Please wait a minute and try again.', 429);
        }
    }


    /* =================================================================
     *  File uploads
     * ================================================================= */

    /**
     * Validate an uploaded payment screenshot.
     *
     * Checks the real MIME type with finfo rather than trusting the
     * browser-supplied type, and re-checks the extension.
     *
     * @param array<string, mixed> $file entry from $_FILES
     * @return array{ok: bool, error?: string, ext?: string, mime?: string, size?: int}
     */
    public static function validateUpload(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'error' => 'No file was selected.'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'error' => 'That file is too large.'];
            default:
                return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'The file appears to be empty.'];
        }
        if ($size > MAX_UPLOAD_BYTES) {
            return [
                'ok'    => false,
                'error' => 'Maximum file size is ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB.',
            ];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Upload could not be verified.'];
        }

        // Trust the file contents, not the client-declared type.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        $allowedMime = unserialize(ALLOWED_UPLOAD_MIME, ['allowed_classes' => false]);
        $allowedExt  = unserialize(ALLOWED_UPLOAD_EXT, ['allowed_classes' => false]);

        if (!in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP or PDF files are accepted.'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return ['ok' => false, 'error' => 'That file extension is not allowed.'];
        }

        // An image must actually decode as an image.
        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                return ['ok' => false, 'error' => 'That image could not be read.'];
            }
        }

        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'size' => $size];
    }

    /**
     * Random, collision-resistant, extension-safe stored filename.
     */
    public static function safeFilename(string $ext): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
    }


    /* =================================================================
     *  Tokens, hashing, signatures
     * ================================================================= */

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Numeric OTP as a string, preserving leading zeros.
     */
    public static function numericCode(int $length = 6): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Signature embedded in ticket QR codes. A scanned ticket whose
     * signature does not recompute is a forgery.
     */
    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, APP_KEY);
    }

    public static function verifySignature(string $payload, string $signature): bool
    {
        return hash_equals(self::sign($payload), $signature);
    }


    /* =================================================================
     *  Request context
     * ================================================================= */

    public static function clientIp(): string
    {
        // Hostinger proxies real visitor IPs in these headers.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $candidate = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public static function userAgent(int $maxLength = 255): string
    {
        return self::clean($_SERVER['HTTP_USER_AGENT'] ?? '', $maxLength);
    }

    public static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    /**
     * Reject anything that is not a POST — used by write endpoints.
     */
    public static function requirePost(): void
    {
        if (!self::isPost()) {
            Response::error('This endpoint requires a POST request.', 405);
        }
    }
}
