<?php
/**
 * join-form.php?t=<applicant token>[&auto=pdf] — the applicant's own copy of
 * the form they sent from /join.php (A4, 2 pages, Print / PDF / PNG).
 *
 * The token (ApForm::applicantToken: "<id>-<32 hex>", an HMAC of the row id
 * and its draft key) is the only credential; only the public "submit" answer
 * hands it out (ApForm::applicantUrl). The raw draft key does NOT open this
 * page: office pages print draft keys. It opens the form only once it was SENT
 * (status submitted or approved) and only for 30 days after sending, so a
 * key found later on a shared phone opens nothing. No cache, no referrer,
 * rate-limited per IP. The applicant sees their own numbers in full.
 * Renderer: includes/agentpro-form.php (ApForm).
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro-form.php';

Security::sendHeaders();
ApForm::noStore();

$gone = static function (int $code, string $ne, string $en): never {
    http_response_code($code);
    $office = Security::e(Settings::officePhone());
    echo '<!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>फारम · Form</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
        . 'padding:16px;background:#eef3fc;color:#0d1b3e;font:16px/1.6 "Noto Sans","Noto Sans Devanagari",Arial,sans-serif;text-align:center}'
        . 'a{display:inline-flex;align-items:center;min-height:48px;margin:6px;padding:0 16px;border-radius:12px;background:#13224F;color:#fff;font-weight:700;text-decoration:none}</style></head><body><div>'
        . '<p>' . Security::e($ne) . '<br>' . Security::e($en) . '</p><p>कार्यालय · Office: ' . $office . '</p>'
        . '<a href="/me/">मेरो ID · My ID</a><a href="/join.php">आवेदन · Apply</a></div></body></html>';
    exit;
};

// rateLimit (not requireRateLimit, which answers JSON and exits) so the page below is what a busy applicant sees
if (!Security::rateLimit('apform_ip', Security::clientIp(), 60, 300)) {
    $gone(429, 'धेरै पटक खोलियो — एक मिनेट पछि फेरि प्रयास गर्नुहोस्।', 'Too many requests — try again in a minute.');
}
if (!AgentPro::available()) {
    $gone(503, 'अहिले उपलब्ध छैन।', 'Not available right now.');
}
// ?t= only. A raw draft key (?k=) is refused: office pages print draft keys in their
// "Finish / edit" links, and this page shows every number unmasked.
$app = ApForm::byApplicantToken((string) ($_GET['t'] ?? ''));
if ($app === null) {
    $gone(404, 'यो फारम भेटिएन।', 'This form was not found.');
}
if (!ApForm::applicantMayOpen($app)) {
    $gone(410,
        (string) $app['status'] === 'draft'
            ? 'फारम अझै पठाइएको छैन — पहिले आवेदन पूरा गरेर पठाउनुहोस्।'
            : 'यो लिंकको म्याद सकियो (पठाएको ' . ApForm::APPLICANT_DAYS . ' दिनसम्म मात्र)। स्वीकृत भएपछि /me/ मा आफ्नो फारम सधैं पाउनुहुन्छ।',
        (string) $app['status'] === 'draft'
            ? 'This form has not been sent yet.'
            : 'This link has expired (it works for ' . ApForm::APPLICANT_DAYS . ' days after sending). Once approved, your form is always in /me/.'
    );
}
$m = (string) $app['status'] === 'approved' && (int) ($app['admin_id'] ?? 0) > 0 ? AgentPro::member((int) $app['admin_id'], false) : null;
$auto = in_array($_GET['auto'] ?? '', ['pdf', 'print'], true) ? (string) $_GET['auto'] : '';

echo ApForm::render($app, $m, [
    'full' => true,
    'mode' => 'applicant',
    'auto' => $auto,
    'back' => (string) $app['status'] === 'approved' ? '/me/' : '',
    'backLabel' => '← मेरो ID · My ID',
]);
