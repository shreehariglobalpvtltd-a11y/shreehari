<?php
/**
 * api/facebook-webhook.php — messages to our Facebook Page (1 Nov 2026).
 *
 * Callback URL for the Meta app's Messenger webhook (admin/facebook.php
 * shows it, with the verify token to paste next to it).
 *
 *   GET   Meta's set-up check: echo hub.challenge when hub.verify_token matches.
 *   POST  Page events, signed X-Hub-Signature-256 with the app secret. An
 *         unsigned or wrongly signed body is refused (403) and never read.
 *         Meta wants its 200 within seconds, and the assistant can take
 *         longer, so the 200 goes first (fastcgi_finish_request, the same
 *         as whatsapp/webhook.php) and the answers are sent after it.
 *
 * includes/fbmessenger.php does the work.
 */
declare(strict_types=1);

defined('SHG_APP') || define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fbmessenger.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $challenge = FbMessenger::verifyChallenge($_GET);
    if ($challenge === null) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $challenge;
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 512 * 1024 || !FbMessenger::signatureOk($raw, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    Logger::warning('Facebook webhook: bad or missing signature', ['bytes' => strlen($raw)], 'facebook');
    http_response_code(403);
    exit('Forbidden');
}

$payload = json_decode($raw, true);
$events  = is_array($payload) ? FbMessenger::events($payload) : [];

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
ignore_user_abort(true);
@set_time_limit(180);

foreach ($events as $ev) {
    FbMessenger::handle($ev);
}
