<?php
/**
 * /api/team-face-login.php — the team portal's door without a password
 * (23 Oct 2026, owner: "face id ki finger print nai password; date of birth,
 * document number last ma; camera selfie recognise system back ma").
 *
 *   POST (X-CSRF-Token = the meta on /team/), application/json:
 *   {"action":"face","phone":"98…","cc":"91"|"977","frames":[3 x data:image/jpeg]}
 *     -> {ok:true, kind:"admin"|"staff"}   the session is open, reload /team/
 *     -> {ok:false, error:"…"}
 *   {"action":"facts","phone":"98…","cc":"91","dob":"1990-05-14","idno":"52-01-75-12345"}
 *     -> same
 *
 * The order on the page is: passkey (Face ID / fingerprint, api/team-passkey.php)
 * first, then this camera face check against the photo on the person's own
 * record, then birth date + ID number, and the password last. Budgets: 6 face
 * tries per 10 minutes per IP, 5 "facts" tries per number per 15 minutes.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/facecheck.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        Response::error('This endpoint takes POST.', 405);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1_200_000) {
        Response::error('That is too large to send.', 413);
    }
    Security::requireCsrf();
    Response::$maxInputBytes = 1_200_000;
    if (!TeamPortal::ready()) {
        Response::error('The portal is being set up.', 503);
    }
    $ip     = Security::clientIp();
    $action = (string) Response::field('action', '');
    $phone  = TeamPortal::phone10((string) Response::field('phone', ''));
    $cc     = TeamPortal::cc(Response::field('cc', '91'));
    if ($phone === '') {
        Response::invalid(['phone' => 'number'], '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter the 10-digit mobile number.');
    }

    if ($action === 'face') {
        Security::requireRateLimit('team_face_ip', $ip, 6, 600);
        Security::requireRateLimit('team_face_phone', $phone, 6, 900);
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
        $r = TeamPortal::faceLogin($phone, $cc, $frames);
        Response::json($r['ok'] ? ['ok' => true, 'kind' => $r['kind']] : ['ok' => false, 'error' => $r['error']]);
    }

    if ($action === 'facts') {
        Security::requireRateLimit('team_facts_ip', $ip, 12, 900);
        Security::requireRateLimit('team_facts_phone', $phone, 5, 900);
        $r = TeamPortal::factsLogin($phone, $cc, (string) Response::field('dob', ''), (string) Response::field('idno', ''));
        Response::json($r['ok'] ? ['ok' => true, 'kind' => $r['kind']] : ['ok' => false, 'error' => $r['error']]);
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
