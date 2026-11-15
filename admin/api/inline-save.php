<?php
/**
 * =====================================================================
 *  admin/api/inline-save.php — click a value, type, Enter (16 Nov 2026, v2.0 P3-6).
 *
 *  The save end of assets/js/admin-inline-edit.js. A page marks a value:
 *     <span data-ie-entity="route_stop" data-ie-field="fare" data-ie-id="406"
 *           data-ie-value="2200" data-ie-type="money" data-ie-confirm="1">₹2,200</span>
 *  and the script POSTs JSON {entity, field, id, value} with the staff
 *  CSRF token (X-CSRF-Token, meta shg-admin-csrf).
 *
 *  Whitelist v1 — route_stop.fare / stop_time / landmark, with the SAME
 *  rules as Admin → Routes (save_stop_fares / edit_stop): routes.edit,
 *  fare blank = the direction default or ₹1–₹100000, time HH:MM, landmark
 *  up to 191 characters; Boarding::flush(); audited route_stop.fares /
 *  route_stop.edit with the old and new value. 60 saves / minute per admin.
 *
 *  Response: {ok, old, new, message}; {ok:false, message} on refusal.
 * =====================================================================
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('routes.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @param array<string,mixed> $payload */
$out = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

/* entity => [permission, table, fields] — nothing outside this list can be written */
const IE_WHITELIST = [
    'route_stop' => ['perm' => 'routes.edit', 'fields' => ['fare', 'stop_time', 'landmark']],
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $out(['ok' => false, 'message' => 'POST only.'], 405);
    }
    $raw = (string) file_get_contents('php://input');
    $in  = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($in)) {
        $in = $_POST;
    }
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $in[CSRF_TOKEN_NAME] ?? $in['csrf'] ?? '');
    if (!Security::verifyCsrf($token)) {
        $out(['ok' => false, 'message' => 'पेज धेरै बेर खुला रह्यो · Session expired — reload the page and try again.'], 419);
    }
    $entity = (string) ($in['entity'] ?? '');
    $field  = (string) ($in['field'] ?? '');
    $id     = (int) ($in['id'] ?? 0);
    $value  = $in['value'] ?? '';
    if (!isset(IE_WHITELIST[$entity]) || !in_array($field, IE_WHITELIST[$entity]['fields'], true) || $id <= 0 || (!is_scalar($value) && $value !== null)) {
        $out(['ok' => false, 'message' => 'यो यहाँबाट बदलिँदैन · This value cannot be edited here.'], 400);
    }
    if (!Auth::can(IE_WHITELIST[$entity]['perm'])) {
        $out(['ok' => false, 'message' => '⛔ अनुमति छैन · Your login may not change this.'], 403);
    }
    $adminId = (int) ($admin['id'] ?? 0);
    if (!Security::rateLimit('inline_save', 'admin:' . $adminId, 60, 60)) {
        $out(['ok' => false, 'message' => 'एकछिन पर्खनुहोस् · Too many saves — wait a moment.'], 429);
    }
    $value = trim((string) ($value ?? ''));

    /* ---- route_stop: the same rules as admin/routes.php ---- */
    $stop = Database::fetch('SELECT * FROM route_stops WHERE id = :id', ['id' => $id]);
    if ($stop === null) {
        $out(['ok' => false, 'message' => 'Stop भेटिएन · Stop not found.'], 404);
    }
    $name = (string) $stop['stop_name'];
    try {
        if ($field === 'fare') {
            if (!Boarding::hasStopControls()) {
                throw new InvalidArgumentException('Run database/upgrade-2026-10-01-stop-fares.sql first - the fare column does not exist yet.');
            }
            $v = str_replace([',', ' ', '₹'], '', $value);   // "2,200" is 2200 (a (float) cast would make it 2)
            if ($v === '') {
                $new = null;
            } elseif (!is_numeric($v) || (float) $v < 1 || (float) $v > 100000) {
                throw new InvalidArgumentException('भाडा ₹1–₹100000 वा खाली (default) · Enter the fare as a rupee amount between 1 and 100000, or leave it blank for the default.');
            } else {
                $new = round((float) $v, 2);
            }
            $old = $stop['fare'] !== null ? round((float) $stop['fare'], 2) : null;
        } elseif ($field === 'stop_time') {
            if ($value === '') {
                $new = null;
            } elseif (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
                throw new InvalidArgumentException('समय HH:MM मा · Enter stop time as HH:MM.');
            } else {
                $new = strlen($value) === 5 ? $value . ':00' : $value;
            }
            $old = $stop['stop_time'] !== null ? (string) $stop['stop_time'] : null;
        } else {
            $v   = Security::clean($value, 191);
            $new = $v !== '' ? $v : null;
            $old = $stop['landmark'] !== null && $stop['landmark'] !== '' ? (string) $stop['landmark'] : null;
        }
    } catch (InvalidArgumentException $e) {
        $out(['ok' => false, 'message' => $e->getMessage()], 422);
    }

    $show = static function (string $f, $v): string {
        if ($v === null) {
            return $f === 'fare' ? 'default' : '—';
        }
        return $f === 'fare' ? inr((float) $v) : ($f === 'stop_time' ? substr((string) $v, 0, 5) : (string) $v);
    };
    $json = static fn(string $f, $v) => $v === null ? null : ($f === 'fare' ? (float) $v : ($f === 'stop_time' ? substr((string) $v, 0, 5) : (string) $v));

    if ($new === $old) {
        $out(['ok' => true, 'old' => $json($field, $old), 'new' => $json($field, $new), 'message' => 'केही बदलिएन · No change.']);
    }

    Database::update('route_stops', [$field => $new], 'id = :id', ['id' => $id]);
    if (class_exists('Boarding') && method_exists('Boarding', 'flush')) {
        Boarding::flush();
    }
    if ($field === 'fare') {
        // the same audit row Admin → Routes "💾 Save pickup fares" writes
        Logger::audit('route_stop.fares', 'routes', (string) $stop['route_id'], [$name => $old], [$name => $new],
            'Pickup fare changed on route ' . $stop['route_id'] . ' (1 stop) · inline edit');
    } else {
        $k = $field === 'stop_time' ? 'time' : 'landmark';
        Logger::audit('route_stop.edit', 'routes', (string) $stop['route_id'], ['stop' => $name, $k => $old], ['stop' => $name, $k => $new],
            'Edited stop: ' . $name . ' (' . $k . ', inline edit)');
    }
    $label = ['fare' => 'भाडा · fare', 'stop_time' => 'समय · time', 'landmark' => 'landmark'][$field];
    $out(['ok' => true, 'old' => $json($field, $old), 'new' => $json($field, $new),
          'message' => '✅ ' . $name . ' ' . $label . ': ' . $show($field, $old) . ' → ' . $show($field, $new)]);
} catch (Throwable $e) {
    Logger::error('inline-save api', ['err' => $e->getMessage(), 'line' => $e->getLine()], 'admin');
    $out(['ok' => false, 'message' => 'केही मिलेन · Something went wrong — nothing was changed.'], 500);
}
