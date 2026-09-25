<?php
/**
 * api/ai-feedback.php — 👍 / 👎 / a correction on an assistant answer.
 *
 *   POST { verdict: up|down|correction, userText, replyText, note? }
 *
 * Logged for the office (Admin → AI Manager → Learn). A 👎 or a correction
 * opens an example candidate; a manager's approval is what teaches the
 * assistant. Same CSRF and rate rules as every other JSON endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/ailearn.php';

try {
    Security::requirePost();
    Security::requireCsrf();
    Security::requireRateLimit('ai_feedback', Security::clientIp(), 30, 3600);
    $verdict = (string) Response::field('verdict', 'down');
    if (!in_array($verdict, ['up', 'down', 'correction'], true)) {
        Response::invalid(['verdict' => 'up, down or correction']);
    }
    $userText  = mb_substr(trim((string) Response::field('userText', '')), 0, 2000);
    $replyText = mb_substr(trim((string) Response::field('replyText', '')), 0, 4000);
    $note      = mb_substr(trim((string) Response::field('note', '')), 0, 500);
    if ($replyText === '') {
        Response::invalid(['replyText' => 'Which answer?']);
    }
    $user = Auth::user();
    $who  = (string) ($user['phone'] ?? '');
    $id = AiLearn::feedback($who !== '' ? $who : Security::clientIp(), 'web', $verdict, $userText, $replyText, $note);
    Response::success(['id' => $id], $verdict === 'up' ? 'Thanks!' : 'Noted — the office will look at this answer.');
} catch (Throwable $e) {
    Response::serverError($e);
}
