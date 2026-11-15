<?php
/**
 * =====================================================================
 *  admin/api/ai-settings.php — S Hari AI from any staff page (16 Nov 2026, v2.0 P3-4a).
 *
 *  The JSON door to the SAME command path as admin/easy-settings.php
 *  (AiOps::propose → preview → "Yes" → AiOps::confirm), used by the
 *  floating bubble (assets/js/admin-ai-bubble.js). Nothing is written on
 *  the first call; the change is only made on an explicit confirm.
 *
 *  POST (JSON body or form fields), CSRF in the X-CSRF-Token header
 *  (meta shg-admin-csrf) or the usual form field:
 *     {cmd}                    → {ok, proposal_id, changes:[{what,old,new}], message}
 *     {setting_key, new_value} → same, for one plain setting by its key
 *     {confirm: proposal_id}   → {ok, success, changes, message}
 *     {cancel:  proposal_id}   → {ok}
 *
 *  - Who: the easy-settings gate — settings managers, or routes / schedules
 *    managers for the actions they may do (AiOps::allowed per action, and
 *    the one-tap switches for settings managers only).
 *  - The proposal lives in THIS session, bound to the admin who asked
 *    (changed_by is always the session admin, never taken from the body),
 *    and expires after 10 minutes.
 *  - 30 calls / minute per admin. Audited: settings.ai_proposed,
 *    settings.ai_command (+ each action's own row), settings.ai_declined.
 * =====================================================================
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/aiops.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/** @param array<string,mixed> $payload */
$out = static function (array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

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

    $canEdit = Auth::canManageSettings();
    $canCmd  = $canEdit || Auth::can('routes.edit') || Auth::can('schedules.manage');
    if (!$canCmd) {
        $out(['ok' => false, 'message' => '⛔ अनुमति छैन · Your login may not change settings.'], 403);
    }
    $adminId = (int) ($admin['id'] ?? 0);
    if (!Security::rateLimit('ai_settings', 'admin:' . $adminId, 30, 60)) {
        $out(['ok' => false, 'message' => 'एकछिन पर्खनुहोस् · Too many requests — wait a moment.'], 429);
    }

    // this session's open proposals: drop the expired, keep the last five
    $props = is_array($_SESSION['ai_props'] ?? null) ? $_SESSION['ai_props'] : [];
    $props = array_filter($props, static fn($p): bool => is_array($p) && (int) ($p['t'] ?? 0) >= time() - 600);
    $take  = static function (string $id) use (&$props, $adminId): ?array {
        $p = $props[$id] ?? null;
        unset($props[$id]);
        return (is_array($p) && (int) ($p['admin'] ?? 0) === $adminId) ? $p : null;
    };
    $save = static function () use (&$props): void {
        $_SESSION['ai_props'] = array_slice($props, -5, null, true);
    };
    $pid = static fn($v): string => preg_match('/^[a-f0-9]{16}$/', (string) $v) === 1 ? (string) $v : '';

    /* ---- Yes ---- */
    if (isset($in['confirm'])) {
        $p = ($id = $pid($in['confirm'])) !== '' ? $take($id) : null;
        $save();
        if ($p === null) {
            $out(['ok' => false, 'success' => false, 'changes' => [], 'message' => '⌛ समय सकियो · This change has expired — say it again.'], 410);
        }
        $done = AiOps::confirm($p, $admin, $canEdit);
        $bad  = array_filter($done, static fn(string $l): bool => str_starts_with($l, '⛔') || str_starts_with($l, '⚠️'));
        $out(['ok' => true, 'success' => $done !== [] && $bad === [], 'changes' => (array) ($p['rows'] ?? []),
              'message' => '✅ ' . ($done !== [] ? implode(' · ', $done) : 'केही बदलिएन · Nothing changed.')]);
    }

    /* ---- No ---- */
    if (isset($in['cancel'])) {
        $p = ($id = $pid($in['cancel'])) !== '' ? $take($id) : null;
        $save();
        if ($p !== null) {
            AiOps::auditDeclined($p);
        }
        $out(['ok' => true, 'message' => '✖ केही बदलिएन · Nothing changed.']);
    }

    /* ---- a command, or one setting by its key ---- */
    if (isset($in['setting_key'])) {
        AiOps::registerEasyKeys();
        $key = (string) $in['setting_key'];
        $a   = AiOps::clean(['type' => 'setting', 'key' => $key, 'value' => (string) ($in['new_value'] ?? '')]);
        if ($a === null) {
            $out(['ok' => false, 'proposal_id' => null, 'changes' => [], 'message' => '🤔 यो सेटिङ यहाँबाट बदलिँदैन · Unknown or protected setting, or a wrong value.'], 422);
        }
        $cmd = $key . ' = ' . mb_substr((string) ($in['new_value'] ?? ''), 0, 120);
        $ok  = AiOps::allowed($a);
        $pr  = ['cmd' => $cmd, 'switches' => [], 'ops' => $ok ? [$a] : [], 'via' => 'key', 'denied' => $ok ? [] : ['setting'], 'note' => ''];
    } else {
        $cmd = Security::clean((string) ($in['cmd'] ?? ''), 400);
        if ($cmd === '') {
            $out(['ok' => false, 'proposal_id' => null, 'changes' => [], 'message' => 'के बदल्ने? · Type what to change.'], 422);
        }
        $pr = AiOps::propose($cmd, $canEdit);
    }
    $rows = AiOps::changes($pr);
    if ($rows === []) {
        $out(['ok' => true, 'proposal_id' => null, 'changes' => [], 'message' => AiOps::emptyMessage($pr)]);
    }
    $id = bin2hex(random_bytes(8));
    $props[$id] = $pr + ['t' => time(), 'admin' => $adminId, 'rows' => $rows];
    $save();
    AiOps::auditProposed($pr);
    $out(['ok' => true, 'proposal_id' => $id, 'changes' => $rows, 'via' => (string) $pr['via'],
          'message' => 'यो बदल्ने हो? · Change this?']);
} catch (Throwable $e) {
    Logger::error('ai-settings api', ['err' => $e->getMessage(), 'line' => $e->getLine()], 'admin');
    $out(['ok' => false, 'message' => 'केही मिलेन · Something went wrong — nothing was changed.'], 500);
}
