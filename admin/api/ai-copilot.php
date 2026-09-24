<?php
/**
 * admin/api/ai-copilot.php — the office talks to the assistant on the web.
 *
 *   POST { text }  →  { ok, data: { text } }
 *
 * The same brain, tools and role rules as WhatsApp (AiTools::whoIs matches
 * the signed-in admin's mobile against admins), through the office door
 * that AiAgent opens for this endpoint alone — so the office can use and
 * teach the assistant before wa_agent_on is switched on for customers.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('dashboard.view');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = static function (int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Security::verifyCsrf()) {
    $out(419, ['ok' => false, 'error' => 'Session expired — reload the page.']);
}
$raw  = json_decode((string) file_get_contents('php://input'), true);
$text = mb_substr(trim((string) ($raw['text'] ?? $_POST['text'] ?? '')), 0, 1500);
if ($text === '') {
    $out(422, ['ok' => false, 'error' => 'Say something.']);
}
$phone = (string) Database::scalar('SELECT phone FROM admins WHERE id = :id', ['id' => (int) $admin['id']], '');
if (normalisePhone($phone) === '') {
    $out(422, ['ok' => false, 'error' => 'Your staff account has no mobile number — add it in Staff & Approvals so the assistant knows your role.']);
}

require_once INCLUDE_PATH . '/aiagent.php';
AiAgent::$officeDoor = true;
if (!AiAgent::enabled()) {
    $out(503, ['ok' => false, 'error' => 'No AI key yet — add anthropic_api_key (or gemini_api_key) in Settings → Ai.']);
}
try {
    $reply = AiAgent::handle($phone, $text, 'web');
} catch (Throwable $e) {
    Logger::error('copilot failed', ['e' => $e->getMessage()]);
    $reply = null;
}
if ($reply === null) {
    $out(502, ['ok' => false, 'error' => 'The assistant did not answer — try again in a moment.']);
}
$out(200, ['ok' => true, 'data' => ['text' => (string) $reply['text'], 'media' => $reply['media'] ?? null]]);
