<?php
/**
 * =====================================================================
 *  Response — uniform JSON envelope for every API endpoint.
 *
 *  Shape:
 *    { "ok": true,  "data": ..., "message": "..." }
 *    { "ok": false, "error": "human readable", "code": 422, "fields": {...} }
 *
 *  The front end only ever has to check `ok`.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Response
{
    /**
     * Emit JSON and stop.
     *
     * @param array<string, mixed> $payload
     */
    /** Opt-in per endpoint (public, identical-for-everyone data only). 0 = no-store. */
    public static int $publicCacheSeconds = 0;

    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            if (self::$publicCacheSeconds > 0 && $status === 200) {
                header('Cache-Control: public, max-age=' . self::$publicCacheSeconds);
            } else {
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('Pragma: no-cache');
            }
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    /**
     * Successful result.
     */
    public static function success(mixed $data = null, string $message = ''): never
    {
        $payload = ['ok' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($message !== '') {
            $payload['message'] = $message;
        }

        self::json($payload, 200);
    }

    /**
     * Failure. `fields` lets the UI highlight individual inputs.
     *
     * @param array<string, string> $fields
     */
    public static function error(string $message, int $status = 400, array $fields = []): never
    {
        $payload = [
            'ok'    => false,
            'error' => $message,
            'code'  => $status,
        ];

        if ($fields !== []) {
            $payload['fields'] = $fields;
        }

        self::json($payload, $status);
    }

    /**
     * Validation failure with per-field messages.
     *
     * @param array<string, string> $fields
     */
    public static function invalid(array $fields, string $message = 'Please check the highlighted fields.'): never
    {
        self::error($message, 422, $fields);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Please sign in to continue.'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): never
    {
        self::error($message, 403);
    }

    /**
     * Unexpected server-side failure. The visitor sees a generic line;
     * the detail goes to the log.
     */
    public static function serverError(Throwable $e, string $message = 'Something went wrong at our end. Please try again.'): never
    {
        Logger::exception($e);

        if (APP_DEBUG) {
            self::json([
                'ok'    => false,
                'error' => $e->getMessage(),
                'code'  => 500,
                'debug' => [
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ],
            ], 500);
        }

        self::error($message, 500);
    }

    /**
     * Read and decode a JSON request body.
     *
     * Falls back to $_POST so the same endpoints work from a plain form
     * submit as well as from fetch().
     *
     * @return array<string, mixed>
     */
    public static function input(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $cached = $decoded;
                    return $cached;
                }
            }
            $cached = [];
            return $cached;
        }

        $cached = $_POST;
        return $cached;
    }

    /**
     * Pull one value from the decoded request body.
     */
    public static function field(string $key, mixed $default = null): mixed
    {
        $input = self::input();
        return $input[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * Send a file to the browser as a download and stop.
     */
    public static function download(string $absolutePath, string $downloadName, string $mime = 'application/octet-stream'): never
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            self::notFound('That file is no longer available.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        readfile($absolutePath);
        exit;
    }

    /**
     * Render a file inline (PDF preview, QR image).
     */
    public static function inline(string $absolutePath, string $mime): never
    {
        if (!is_file($absolutePath)) {
            self::notFound();
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('Cache-Control: private, no-cache, must-revalidate');
            header('X-Content-Type-Options: nosniff');
        }

        readfile($absolutePath);
        exit;
    }

    /**
     * Redirect and stop.
     *
     * Internal paths become ROOT-RELATIVE, not APP_URL-absolute: the panel
     * is served on more than one hostname (www.shreehariglobal.in and the
     * staff door shreehariglobal.network), and an absolute Location would
     * throw a .network admin onto .in mid-login — a different session
     * cookie, so it reads as a failed sign-in. Browsers resolve a relative
     * Location against the request host (RFC 7231), which is exactly what
     * every internal redirect wants. Full URLs still pass through as-is.
     */
    public static function redirect(string $path, int $status = 302): never
    {
        $url = str_starts_with($path, 'http') ? $path : '/' . ltrim($path, '/');

        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        exit;
    }
}
