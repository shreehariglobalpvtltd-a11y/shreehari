<?php
/**
 * =====================================================================
 *  POST /api/ai-chat.php — SHG Sahayak with hands, for the website and
 *  the app (24 Sep 2026).
 *
 *  api/ai-proxy.php (13 Sep) relays a conversation to Claude with the
 *  live briefing and nothing else: it can quote the fare board, it cannot
 *  open a booking, sell, correct a name, or say how many tickets went
 *  today. The WhatsApp assistant (includes/aiagent.php, 20 Sep) can. This
 *  endpoint puts the SAME agent behind the website chat and the admin
 *  panel, with one difference: WHO is asking is decided by the signed-in
 *  session (AiTools::whoIsWeb) instead of the phone number.
 *
 *    guest            -> customer tools, no booking of their own
 *    OTP customer     -> their own bookings, by their number
 *    counter agent    -> their own book, their own day
 *    office           -> the whole company: reports, graphs, live traffic
 *
 *  Guarantees, same as the proxy: the key never leaves the server, the
 *  system prompt is server-owned, and when nothing is configured the
 *  reply is a quiet 503 so the widget stays rule-based.
 *
 *  Body:  { message: string, lang?: 'ne'|'hi'|'en'|'gu', reset?: bool }
 *  Reply: { ok:true, data:{ text, media, charts[], actions[], role, name } }
 *         charts[] = { type, title, labels[], series[{name,data[],format}], format }
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    // Per-IP guard in front of the per-identity one inside the agent: an
    // office on one connection legitimately asks all day, a script does not.
    Security::requireRateLimit('ai_chat_web', Security::clientIp(), 30, 300);
    Security::requireRateLimit('ai_chat_web_day', Security::clientIp(), 600, 86400);

    /* 9 Oct 2026 - the local brain first (includes/walocal.php): timetable,
       seats left, bus position, bus & crew, how to pay, refund rule, Nepal
       office, and the office's own summaries - read from the tables, no AI
       key needed. Only what it recognises; the assistant takes the rest. */
    /* 22 Oct 2026 - the SHARI NAV guide (assets/nav/nav-guide.js) sends
       src=nav for travel questions ("temples near me", "Janakpur kasari
       pugne"). The local brain reads "map", "direction", "kasari pugne" as
       the office address, so those go straight to the assistant. Its bus
       questions come without src and still get the local answers. */
    $localMsg = trim((string) Response::field('message', ''));
    if ($localMsg !== '' && !(bool) Response::field('reset', false) && (string) Response::field('src', '') !== 'nav') {
        try {
            require_once INCLUDE_PATH . '/walocal.php';
            $ctxL  = AiTools::whoIsWeb();
            $langL = (string) Response::field('lang', '');
            $loc   = WaLocal::answerWeb($ctxL, mb_substr($localMsg, 0, 1500), in_array($langL, ['ne', 'hi', 'en', 'gu'], true) ? $langL : '');
            if ($loc !== null) {
                Response::success([
                    'text'    => $loc['text'],
                    'media'   => $loc['media'],
                    'charts'  => [],
                    'actions' => [],
                    'role'    => $ctxL['role'],
                    'name'    => (string) ($ctxL['name'] ?? ''),
                    'local'   => $loc['intent'],
                ]);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'ai');
        }
    }

    if (!AiAgent::webEnabled()) {
        // Not configured or switched off — the widget keeps its rule engine
        // (and, when only the plain relay is wanted, api/ai-proxy.php).
        Response::error('AI assistant is not configured.', 503);
    }

    $ctx  = AiTools::whoIsWeb();
    $lang = (string) Response::field('lang', '');
    $lang = in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : '';

    if ((bool) Response::field('reset', false)) {
        AiAgent::forget((string) ($ctx['stageKey'] ?? ''));
        Response::success(['text' => '', 'media' => null, 'charts' => [], 'actions' => [], 'reset' => true,
            'role' => $ctx['role'], 'name' => $ctx['name']]);
    }

    $message = trim((string) Response::field('message', ''));
    if ($message === '') {
        Response::invalid(['message' => 'Nothing to answer.']);
    }
    $message = mb_substr($message, 0, 1500);

    $answer = AiAgent::handleWeb($ctx, $message, $lang);
    if ($answer === null) {
        // Rate-limited, both brains down, or an empty reply: the widget
        // falls back to its own answer + WhatsApp handoff, as it always did.
        Response::error('The assistant is busy — please try again.', 502);
    }

    Response::success([
        'text'    => $answer['text'],
        'media'   => $answer['media'],
        'charts'  => $answer['charts'],
        'actions' => $answer['actions'],
        'role'    => $answer['role'],
        'name'    => (string) ($ctx['name'] ?? ''),
    ]);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
