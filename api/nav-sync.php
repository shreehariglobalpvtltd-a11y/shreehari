<?php
/**
 * /api/nav-sync.php - SHARI NAV device sync (Phase 2 D, 22 Oct 2026).
 *
 * Owner: "saved places, home / work, trips, parking - same on the phone and
 * the computer after the OTP login". One JSON document per person in
 * nav_sync; every item is {t, v} or a tombstone {t, del:true}, and the newer
 * t wins per item (t = ms on the device that changed it).
 *
 *   GET                                      -> {signedIn, name, phone, items, t}
 *   POST {items:{key:{t, v | del}}}  + X-CSRF-Token
 *                                            -> {items, t}  (the merged document)
 *
 * Who: a customer whose number THIS session proved with the WhatsApp code
 * (CustomerProfile::verifiedUserId - name + mobile alone is never proof), or
 * a signed-in staff member. Anyone else gets {signedIn:false}.
 * Limits: 400 items, 256 KB, keys like home, park, place:<lat>,<lng>.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/customerprofile.php';

header('Cache-Control: no-store');

const NAV_SYNC_MAX_ITEMS = 400;
const NAV_SYNC_MAX_BYTES = 262144;
const NAV_SYNC_TOMB_DAYS = 90;

/** @return array{key:string, name:string, phone:string}|null */
function nav_sync_who(): ?array
{
    $admin = Auth::admin();
    if (is_array($admin) && (int) ($admin['id'] ?? 0) > 0 && (int) ($admin['is_active'] ?? 1) === 1) {
        return ['key' => 'a' . (int) $admin['id'], 'name' => (string) ($admin['full_name'] ?? ($admin['username'] ?? '')),
                'phone' => (string) ($admin['phone'] ?? '')];
    }
    $user = Auth::user();
    if (is_array($user) && (int) ($user['id'] ?? 0) > 0 && CustomerProfile::verifiedUserId() === (int) $user['id']) {
        return ['key' => 'u' . (int) $user['id'], 'name' => (string) ($user['full_name'] ?? ''), 'phone' => (string) ($user['phone'] ?? '')];
    }
    return null;
}

/** @return array<string, array<string, mixed>> */
function nav_sync_items(?array $row): array
{
    $doc = $row !== null ? json_decode((string) $row['data'], true) : null;
    return is_array($doc) && is_array($doc['items'] ?? null) ? $doc['items'] : [];
}

try {
    Security::requireRateLimit('nav_sync', Security::clientIp(), 60, 60);
    $who = nav_sync_who();
    if ($who === null) {
        Response::success(['signedIn' => false]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $row = Database::fetch('SELECT data, updated_at FROM nav_sync WHERE user_key = :k', ['k' => $who['key']]);
        Response::success([
            'signedIn' => true, 'name' => $who['name'], 'phone' => $who['phone'] !== '' ? maskPhone($who['phone']) : '',
            'items' => (object) nav_sync_items($row), 't' => $row !== null ? (int) strtotime((string) $row['updated_at']) * 1000 : null,
        ]);
    }

    Security::requirePost();
    Security::requireCsrf();
    Security::requireRateLimit('nav_sync_w', $who['key'], 30, 60);
    $in = Response::field('items', []);
    if (!is_array($in) || count($in) > NAV_SYNC_MAX_ITEMS) {
        Response::invalid(['items' => 'Too many items.']);
    }
    $nowMs = (int) round(microtime(true) * 1000);
    $clean = [];
    foreach ($in as $k => $it) {
        $k = (string) $k;
        if (preg_match('/^[a-z]{2,8}(:[A-Za-z0-9.,:_-]{1,80})?$/', $k) !== 1 || !is_array($it) || !is_numeric($it['t'] ?? null)) {
            continue;
        }
        $t = (float) $it['t'];
        if ($t < $nowMs - 5 * 365 * 86400000.0 || $t > $nowMs + 86400000.0) {
            continue;                                      /* a phone with a wild clock */
        }
        $clean[$k] = !empty($it['del']) ? ['t' => $t, 'del' => true] : ['t' => $t, 'v' => $it['v'] ?? null];
    }

    $items = Database::transaction(static function () use ($who, $clean, $nowMs): array {
        $rows = Database::fetchForUpdate('SELECT data FROM nav_sync WHERE user_key = :k', ['k' => $who['key']]);
        $items = nav_sync_items($rows[0] ?? null);
        foreach ($clean as $k => $it) {
            if (!isset($items[$k]) || $it['t'] > (float) ($items[$k]['t'] ?? 0)) {
                $items[$k] = $it;
            }
        }
        $old = $nowMs - NAV_SYNC_TOMB_DAYS * 86400000.0;
        foreach ($items as $k => $it) {
            if (!empty($it['del']) && (float) $it['t'] < $old) {
                unset($items[$k]);
            }
        }
        $json = json_encode(['items' => (object) $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > NAV_SYNC_MAX_BYTES || count($items) > NAV_SYNC_MAX_ITEMS) {
            throw new RuntimeException('Too much to keep - remove some saved places or trips.');
        }
        $now = date('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO nav_sync (user_key, data, updated_at) VALUES (:k, :d, :u)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)',
            ['k' => $who['key'], 'd' => $json, 'u' => $now]
        );
        return $items;
    });
    Response::success(['items' => (object) $items, 't' => $nowMs]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
