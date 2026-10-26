<?php
/**
 * /api/my-application.php — open the form you already sent, to correct it
 * (23 Oct 2026, owner: "only digital form apply, edit").
 *
 *   POST {action:"open", phone, cc}   (X-CSRF-Token from GET /api/agreement-apply.php)
 *        the number must be proven in THIS browser first with the form's
 *        WhatsApp "hi VERIFY" (/api/wa-verify.php). Answers the undecided
 *        application of that number, ready to fill the form:
 *        {ok, kind: staff|ind|org, ref, values{inputId: text}, have[...], public_ok}
 *   POST {action:"close"}             forget the open edit
 *
 * Decided applications are not opened here: an approved person manages
 * their details, ID card and password at /team/.
 */
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/applyedit.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    Security::requireCsrf();
    Security::requireRateLimit('my_app_ip', Security::clientIp(), 20, 600);
    Response::$maxInputBytes = 4000;

    $action = (string) Response::field('action', '');
    if ($action === 'close') {
        ApplyEdit::close();
        Response::json(['ok' => true]);
    }
    if ($action !== 'open') {
        Response::error('Unknown action.', 400);
    }

    $cc    = (string) Response::field('cc', '91') === '977' ? '977' : '91';
    $phone = normalisePhone((string) Response::field('phone', ''));
    if (strlen($phone) !== 10) {
        Response::invalid(['phone' => 'number'], '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter the 10-digit mobile you applied with.');
    }
    if (!WaVerify::sessionVerified($phone)) {
        Response::error('पहिले WhatsApp मा "hi" पठाएर नम्बर प्रमाणित गर्नुहोस् · First verify the number on WhatsApp.', 403, ['need' => 'verify']);
    }
    $app = ApplyEdit::findOpen($phone, $cc);
    if ($app === null) {
        Response::error('यो नम्बरबाट सच्याउन मिल्ने फारम भेटिएन। स्वीकृत भइसकेको भए "मेरो फारम, ID र पासवर्ड" बाट खोल्नुहोस्। · No open form from this number. If you are already approved, use "My form, ID & password".', 404, ['need' => 'none']);
    }
    ApplyEdit::open($app, $phone);
    Response::json(['ok' => true] + ApplyEdit::prefill($app));
} catch (Throwable $e) {
    Response::serverError($e);
}
