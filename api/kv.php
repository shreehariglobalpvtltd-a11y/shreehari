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
/* 4 Sep 2026: commissionRules (agent pay structure) is commercial data with no
   public purpose — the customer app falls back to its packaged defaults. */
const KV_PUBLIC_READ = ['routes', 'settings', 'delays', 'livetrips', 'livebus'];

const KV_MAX_VALUE_BYTES = 4 * 1024 * 1024; // 4 MB per key — generous, but bounded.

/**
 * May the signed-in staff member touch the shared company store?
 *
 * Deliberately NOT Auth::isAdmin(). This store holds company-wide
 * operational and financial data — routes, settings, commission rules,
 * payout requests, the audit log — and merely holding a staff session is
 * not authority over any of it. A counter agent has a staff session and is
 * restricted, by design, to the bookings they sold themselves
 * (Auth::bookingScopeAdminId); letting one read the payout register or
 * overwrite the shared fare table through this seam would route around
 * every scope check the admin pages apply.
 *
 * dashboard.view is the permission this codebase already uses to mean
 * "sees the whole company" — see the $isSupervisor checks in
 * admin/agent.php and admin/agent-sales.php. Agents, scanners and
 * office officials are not granted it.
 */
function kv_may_manage(): bool
{
    return Auth::can('dashboard.view');
}

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
     *  BATCH READ  —  GET /api/kv.php?action=mget&keys=routes,settings,…
     *
     *  Boot used to ask for sixteen keys with sixteen separate requests.
     *  Even fired in parallel that is sixteen round-trips of latency and
     *  sixteen PHP boots; on a phone in Nepalgunj it was the single
     *  biggest thing standing between tapping the icon and seeing the
     *  app. One request answers them all, with exactly the same
     *  per-key permission rules as the single read below — a key the
     *  visitor may not read simply comes back as a miss, never an error
     *  that would leak which keys exist.
     * ---------------------------------------------------------------- */
    if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'mget') {
        Security::requireRateLimit('kv_get', Security::clientIp(), 240, 60);

        $requested = array_slice(array_filter(array_map(
            'trim',
            explode(',', (string) ($_GET['keys'] ?? ''))
        )), 0, count(KV_ALLOWED));

        $isAdmin = kv_may_manage();
        $wanted  = [];
        $values  = [];

        foreach ($requested as $raw) {
            $key = kv_key($raw);
            if ($key === '') {
                continue;
            }
            // Report a miss for anything this visitor may not read, so the
            // bridge falls back to their own browser copy exactly as it
            // does for the single-key read.
            $values[$key] = null;
            if ($isAdmin || in_array($key, KV_PUBLIC_READ, true)) {
                $wanted[] = $key;
            }
        }

        if ($wanted !== []) {
            $ph = implode(',', array_fill(0, count($wanted), '?'));
            foreach (Database::fetchAll(
                "SELECT kkey, kvalue FROM kv_store WHERE kscope = 'global' AND kkey IN ($ph)",
                $wanted
            ) as $row) {
                $values[(string) $row['kkey']] = $row['kvalue'];
            }
        }

        Response::success(['values' => $values]);
    }

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
        if (!$isPublic && !kv_may_manage()) {
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

    Security::requireRateLimit('kv_set', Security::clientIp(), 30, 60);

    $rawKey = (string) Response::field('key', '');
    $key    = kv_key($rawKey);

    if ($key === '') {
        Response::invalid(['key' => 'Unknown storage key.']);
    }

    // Only staff who see the whole company may change shared server state.
    // One exception (4 Sep 2026): the live bus position may be published by
    // ANY signed-in staff member — the driver's phone on the navigator page
    // is usually an agent or scanner account, and the key holds nothing but
    // the coach's coordinates, speed and nearest stop.
    if ($key === 'livebus' ? Auth::admin() === null : !kv_may_manage()) {
        Response::forbidden('Your role does not allow saving changes to the server.');
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

    // The bus position is telemetry (a fix every ~10 s for a 30-hour run);
    // it is not audited, everything else still is.
    if ($key !== 'livebus') {
        Logger::audit('kv.set', 'kv_store', $key, null, ['bytes' => strlen((string) $value)]);
    }

    /* ------------------------------------------------------------------
     *  Mirror the operator's fare edit into the server settings table.
     *
     *  The app's admin panel keeps its settings in this kv_store blob, but
     *  Fare::dirFares() -- the code that decides what a passenger is
     *  actually charged -- reads the `settings` table. Without this bridge
     *  the two drift apart the first time anyone changes a price: the site
     *  advertises the new fare while the server keeps billing the old one.
     *  Writing it here means the same click updates both.
     *
     *  Only reached by someone who already passed kv_may_manage() above.
     * ---------------------------------------------------------------- */
    if ($key === 'settings') {
        $decoded = json_decode((string) $value, true);

        if (is_array($decoded) && isset($decoded['mainFares']) && is_array($decoded['mainFares'])) {
            $clean = [];

            foreach (['toNepal', 'toIndia'] as $leg) {
                $amount = $decoded['mainFares'][$leg] ?? null;
                // Ignore anything that is not a sane fare rather than letting
                // a bad client blank out the price the server bills on.
                if (is_numeric($amount) && (float) $amount > 0 && (float) $amount < 1000000) {
                    $clean[$leg] = round((float) $amount);
                }
            }

            if (count($clean) === 2) {
                Settings::set('main_fares', $clean, 'json', 'pricing', false);
                Logger::audit('settings.main_fares', 'settings', 'main_fares', null, $clean);
            }
        }
    }

    Response::success(['saved' => true, 'key' => $key]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
