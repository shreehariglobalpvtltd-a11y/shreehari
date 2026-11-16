<?php
/**
 * =====================================================================
 *  admin/api/ai-suggestion.php — "Later" / "Ignore" on an AI Watch card
 *  (16 Nov 2026, Master v2 P3-3b).
 *
 *  The office's Home (admin/home.php) shows AiWatch::render(forOffice()).
 *  Each card keeps its own button, which only OPENS the page that does the
 *  job (Accept never acts). Under it this endpoint offers:
 *
 *     do=4h        hide the card for 4 hours
 *     do=tomorrow  hide it until tomorrow 08:00 IST
 *     do=ignore    hide it while it says the same thing (at most 7 days);
 *                  a new count / another bus shows again
 *     do=reset     every hidden card comes back ("↺ show")
 *
 *  POST only, CSRF (X-CSRF-Token header or the form field), the same gate
 *  as the panel (dashboard.view), 60 / minute per admin. The choice is one
 *  shared list for the whole office in the setting 'ai_watch_state' (no
 *  table, no migration); every change is audited (ai_watch.later /
 *  ai_watch.ignore / ai_watch.reset). Nothing is sent to anyone.
 *
 *  fetch (Accept: application/json) → {ok, key, status, until, message};
 *  a plain form post (no JavaScript) → 303 back to Home.
 * =====================================================================
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/aiwatch.php';

$raw  = (string) file_get_contents('php://input');
$json = $raw !== '' && str_starts_with(ltrim($raw), '{') ? json_decode($raw, true) : null;
$in   = is_array($json) ? $json : $_POST;
$wantsJson = is_array($json)
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'json')
    || (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';

/** @param array<string,mixed> $extra */
$reply = static function (bool $ok, string $message, int $code = 200, array $extra = []) use ($wantsJson): never {
    if (!$wantsJson) {
        header('Location: /admin/home.php', true, 303);
        exit;
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $reply(false, 'POST only.', 405);
    }
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $in[CSRF_TOKEN_NAME] ?? '');
    if (!Security::verifyCsrf($token)) {
        $reply(false, 'पेज धेरै बेर खुला रह्यो · Session expired — reload the page.', 419);
    }
    $adminId = (int) ($admin['id'] ?? 0);
    if (!Security::rateLimit('ai_suggestion', 'admin:' . $adminId, 60, 60)) {
        $reply(false, 'एकछिन पर्खनुहोस् · Too many taps — wait a moment.', 429);
    }

    $do = (string) ($in['do'] ?? '');
    if ($do === 'reset') {
        $n = AiWatch::resetState($adminId);
        $reply(true, '↺ ' . $n . ' card फेरि देखिन्छ · shown again.', 200, ['reload' => true, 'count' => $n]);
    }
    if (!in_array($do, ['4h', 'tomorrow', 'ignore'], true)) {
        $reply(false, 'के गर्ने? · Unknown choice.', 422);
    }
    $key = (string) ($in['key'] ?? '');
    $sig = (string) ($in['sig'] ?? '');
    try {
        $entry = AiWatch::snooze($key, $do, $sig, $adminId);
    } catch (InvalidArgumentException $e) {
        $reply(false, 'यो card चिनिएन · Unknown card — reload the page.', 422);
    }
    $until = (int) $entry['until'];
    $msg = match ($do) {
        '4h'       => '⏰ ' . date('H:i', $until) . ' सम्म लुकाइयो · Hidden until ' . date('H:i', $until) . '.',
        'tomorrow' => '🌅 भोलि ०८:०० सम्म लुकाइयो · Hidden until tomorrow 08:00.',
        default    => '✖ बेवास्ता गरियो — कुरा बदलिए फेरि देखिन्छ · Ignored; it comes back when it changes.',
    };
    $reply(true, $msg, 200, ['key' => $key, 'status' => (string) $entry['status'], 'until' => date('c', $until)]);
} catch (Throwable $e) {
    Logger::error('ai-suggestion api', ['err' => $e->getMessage(), 'line' => $e->getLine()], 'admin');
    $reply(false, 'केही मिलेन · Something went wrong — nothing changed.', 500);
}
