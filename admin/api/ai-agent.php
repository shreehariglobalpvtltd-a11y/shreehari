<?php
/**
 * =====================================================================
 *  admin/api/ai-agent.php — the office assistant, asked from the panel
 *  (28 Sep 2026)
 *
 *  POST JSON {message}          → {ok, text, media, ms}
 *  POST JSON {action:'reset'}   → {ok, text} and the conversation is dropped
 *
 *  The same assistant the office already has on WhatsApp
 *  (includes/aiagent.php + includes/aitools.php), reached from a chair
 *  instead of a handset. Nothing about what it may DO changes here: the
 *  tool catalogue is filtered by role exactly as it is for a phone, and
 *  every press is still recorded in `ai_agent_calls` — with channel
 *  'panel', which admin/ai-activity.php already displays without a
 *  single change to that screen.
 *
 *  Gates, in order: signed-in staff (dashboard.view, via _guard.php),
 *  POST, CSRF, the office switch ai_panel_on, and a per-admin rate
 *  limit. One more gate lives inside AiTools::whoIsAdmin() rather than
 *  here, and deliberately so:
 *
 *      Auth::requireAdmin() lets an account that still holds a temporary
 *      password through on a /api/ URL — it answers JSON instead of
 *      redirecting to the password form. This file is a /api/ URL. So
 *      whoIsAdmin() re-checks must_change_pw itself, and a session that
 *      fails it comes back as 'customer', which handlePanel() refuses.
 *      Putting that check here instead would make it forgettable.
 *
 *  A failure is never a stack trace: the assistant is optional, so any
 *  Throwable becomes one flat sentence and a line in the log.
 * =====================================================================
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/aiagent.php';
$admin = admin_boot('dashboard.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function aiOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function aiErr(string $error): void
{
    aiOut(['ok' => false, 'error' => $error]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    aiErr('POST only.');
}
if (!Security::verifyCsrf()) {
    aiErr('Your session expired — reload the page and try again.');
}
if (!AiAgent::panelEnabled()) {
    // Deliberately specific: this is the office's own screen, and "it is
    // switched off in Settings" is the one thing that actually helps them.
    aiErr('The AI assistant is switched off (Settings → Ai → ai_panel_on), or no API key is configured.');
}

$adminId = (int) ($admin['id'] ?? 0);
if ($adminId <= 0) {
    aiErr('Could not identify your account — sign in again.');
}
if (!Security::rateLimit('ai_panel_req', 'admin:' . $adminId, 20, 60)) {
    aiErr('Too many questions in a minute — pause and try again.');
}

$in      = Response::input();
$action  = (string) ($in['action'] ?? 'ask');
$message = trim((string) ($in['message'] ?? ''));

if ($action === 'reset') {
    AiAgent::forget('panel:' . $adminId);
    aiOut(['ok' => true, 'text' => 'Conversation cleared.', 'media' => null, 'ms' => 0]);
}

if ($message === '') {
    aiErr('Type a question first.');
}
if (mb_strlen($message) > 1500) {
    $message = mb_substr($message, 0, 1500);
}

$t0 = microtime(true);
try {
    $answer = AiAgent::handlePanel($adminId, $message);
} catch (Throwable $e) {
    Logger::exception($e, 'whatsapp');
    aiErr('The assistant could not answer that — please try again.');
}

if ($answer === null || trim((string) $answer['text']) === '') {
    // Off, refused, rate-limited or the model gave nothing. The office does
    // not need to know which, and the log already does.
    aiErr('The assistant could not answer that right now — please try again.');
}

aiOut([
    'ok'    => true,
    'text'  => (string) $answer['text'],
    'media' => $answer['media'] ?? null,
    'ms'    => (int) round((microtime(true) - $t0) * 1000),
]);
