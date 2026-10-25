<?php
/**
 * /api/face-check.php — the form's live selfie check (23 Oct 2026).
 *
 *   POST {frames: [3 x data:image/jpeg], doc?: data:image/jpeg}
 *        (X-CSRF-Token from GET /api/agreement-apply.php)
 *   -> {ok, status: pass|review|unavailable, live, match, summary}
 *
 * A score for the office, never a gate: the result waits in this session
 * and is written onto the application this browser sends next
 * (FaceCheck::attach in api/agreement-apply.php / api/staff-apply.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/facecheck.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1_500_000) {
        Response::error('That is too large to send.', 413);
    }
    Security::requireCsrf();
    $ip = Security::clientIp();
    Security::requireRateLimit('face_check_ip', $ip, 6, 600);
    if (!Security::rateLimit('face_check_day', 'global', 400, 86400)) {
        Response::json(['ok' => true, 'status' => 'unavailable', 'live' => null, 'match' => 'unclear',
                        'summary' => 'अहिले जाँच हुन सकेन — फारम पठाउनुहोस्, कार्यालयले हेर्छ · Not checked now; send the form.']);
    }
    Response::$maxInputBytes = 1_500_000;

    if (Response::field('consent',false) !== true) { Response::error('Consent is required before selfie review.',422); }
    $frames = [];
    foreach ((array) Response::field('frames', []) as $f) {
        $d = FaceCheck::decode($f, 300_000);
        if ($d !== null) {
            $frames[] = $d;
        }
    }
    if (count($frames) !== 3) {
        Response::invalid(['frames' => 'count'], 'क्यामेराको तीन फोटो आएनन् — फेरि गर्नुहोस् · The camera frames did not arrive, please try again.');
    }
    $res = FaceCheck::check($frames, FaceCheck::decode(Response::field('doc', null), 700_000));
    FaceCheck::remember($res);
    Logger::info('Face check: ' . $res['status'], ['live' => $res['live'], 'match' => $res['match']], 'kyc');
    Response::json(['ok' => true] + $res);
} catch (Throwable $e) {
    Response::serverError($e);
}
