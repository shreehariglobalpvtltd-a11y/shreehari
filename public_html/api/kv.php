<?php
/**
 * =====================================================================
 *  GET|POST /api/kv.php — server backing for the front-end window.storage.
 *
 *  The single-file UI persists its working data through window.storage.
 *  index.php injects a bridge that points that seam here so operational
 *  data (routes, settings, notices) is shared across every visitor and
 *  editable from the admin panel — instead of living in one browser.
 *
 *  Security model:
 *    • Reads of PUBLIC keys        → anyone (the site must render for guests)
 *    • Reads of everything else    → admin session only (else "not found",
 *                                      and the bridge falls back to localStorage)
 *    • Writes of any key           → admin session only (else 403; the bridge
 *                                      keeps a local copy so nothing is lost)
 *
 *  This means a guest can browse and even draft a booking (kept in their
 *  own browser), but can never overwrite the shared routes, fares or
 *  settings that the whole site depends on.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

/* Keys the browser is allowed to touch at all (prefix 'shg:' is stripped).
   Anything outside this list is rejected so the table cannot be spammed. */
const KV_ALLOWED = [
    'routes', 'bookings', 'settings', 'messages', 'locks', 'waitlist',
    'users', 'commissions', 'payoutRequests', 'auditLog', 'commissionRules',
    'delays', 'tripExpenses', 'notifications', 'paymentSubmissions',
    'verificationLogs', 'livetrips', 'livebus', 'pendingRef',
];

/* Keys any visitor may READ — shared operational data with no personal
   information in it. Everything else needs an admin session to read. */
const KV_PUBLIC_READ = ['routes', 'settings', 'commissionRules', 'delays', 'livetrips', 'livebus'];

const KV_MAX_VALUE_BYTES = 4 * 1024 * 1024; // 4 MB per key — generous, but bounded.

/**
 * Normalise an incoming key: 'shg:routes' -> 'routes', validated against
 * the allow-list. Returns '' if the key is not permitted.
 */
function kv_key(string $raw): string
{
    $key = $raw;
    if (str_starts_with($key, 'shg:')) {
        $key = substr($key, 4);
    }
    $key = preg_replace('/[^A-Za-z0-9_]/', '', $key) ?? '';
    return in_array($key, KV_ALLOWED, true) ? $key : '';
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    /* ----------------------------------------------------------------
     *  READ  —  GET /api/kv.php?action=get&key=shg:routes
     * ---------------------------------------------------------------- */
    if ($method === 'GET' || Response::field('action', '') === 'get') {
        $rawKey = (string) ($_GET['key'] ?? Response::field('key', ''));
        $key    = kv_key($rawKey);

        if ($key === '') {
            Response::success(['found' => false, 'value' => null]);
        }

        $isPublic = in_array($key, KV_PUBLIC_READ, true);
        if (!$isPublic && !Auth::isAdmin()) {
            // Not readable by this visitor — report a miss so the bridge
            // transparently falls back to their local browser copy.
            Response::success(['found' => false, 'value' => null]);
        }

        Security::requireRateLimit('kv_get', Security::clientIp(), 240, 60);

        $row = Database::fetch(
            'SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
            ['s' => 'global', 'k' => $key]
        );

        Response::success([
            'found' => $row !== null,
            'value' => $row['kvalue'] ?? null,
        ]);
    }

    /* ----------------------------------------------------------------
     *  WRITE  —  POST { action:'set', key, value }
     * ---------------------------------------------------------------- */
    Security::requirePost();
    Security::requireCsrf();

    $action = Security::clean(Response::field('action', 'set'), 20);

    if ($action !== 'set') {
        Response::error('Unknown action.', 400);
    }

    // Only an authenticated admin may change shared server state.
    if (!Auth::isAdmin()) {
        Response::forbidden('Sign in as an administrator to save changes to the server.');
    }

    $rawKey = (string) Response::field('key', '');
    $key    = kv_key($rawKey);

    if ($key === '') {
        Response::invalid(['key' => 'Unknown storage key.']);
    }

    // The value arrives already JSON-encoded by the client store.
    $value = Response::field('value', '');
    if (!is_string($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (strlen((string) $value) > KV_MAX_VALUE_BYTES) {
        Response::error('That value is too large to store.', 413);
    }

    $admin = Auth::admin();
    $actor = $admin['username'] ?? ('admin#' . (int) ($admin['id'] ?? 0));

    Database::query(
        'INSERT INTO kv_store (kscope, kkey, kvalue, updated_by)
              VALUES (:s, :k, :v, :by)
         ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)',
        ['s' => 'global', 'k' => $key, 'v' => (string) $value, 'by' => $actor]
    );

    Logger::audit('kv.set', 'kv_store', $key, null, ['bytes' => strlen((string) $value)]);

    Response::success(['saved' => true, 'key' => $key]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
