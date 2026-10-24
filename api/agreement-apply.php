<?php
/**
 * /api/agreement-apply.php — "apply for an agreement" from /agreements/.
 *
 * GET  -> {"ok":true,"csrf":"<token>"}   starts the session (sets the cookie)
 *         and hands the static agreement page its CSRF token.
 * POST -> Content-Type: application/json, X-CSRF-Token: <token>
 *         {"type":"org"|"ind","lang":"ne"|"hi"|"en","fields":{...},
 *          "photo":"data:image/jpeg;base64,..."|null,"public_ok":bool,"website":""}
 *      200 {"ok":true,"ref":"AGR-000123","message":"...","duplicate":bool,"photo_saved":bool}
 *      422 {"ok":false,"error":"...","code":422,"fields":{"p_phone":"..."}}
 *      419 bad / missing token, 413 too large, 429 too many, 405 other methods.
 *
 * Order of the gates (27 Sep 2026, after the security review):
 *   token
 *   -> the body: at most 1.5 MB, read with that bound whatever the
 *      Content-Length header says
 *   -> a loose per-IP budget on every POST (30 an hour): probing stays
 *      capped, and a person fixing the boxes marked in red is never locked out
 *   -> validation (422 — not charged to the budgets below)
 *   -> honeypot ("website" filled = a bot): the same kind of answer a real
 *      form gets (same keys, a ref just past the real ones, the same ref for
 *      the same form again) and nothing stored
 *   -> an EXACT resend of a form already stored = its ref, charged to
 *      nothing (the double tap, the retry on a slow network)
 *   -> the real budgets, charged only for a form that is about to be stored:
 *      10 an hour per IP (skipped for a signed-in staff member entering
 *      applications for people), 3 a day per phone, and for the whole site
 *      30 an hour and 150 a day — a flood can never fill the database
 *   -> store -> one WhatsApp line to the office, at most 10 an hour (after
 *      that only the log), so a flood can never become a WhatsApp bill.
 *
 * The IP is Security::clientIp(), which believes X-Forwarded-For and friends
 * only when the request really came through one of our own proxies — a
 * made-up header no longer buys a fresh budget. The answer never echoes the
 * stored data back — only the ref and a line in the applicant's language.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';

const AGR_BODY_MAX = 3_500_000;   // 18 Oct 2026: photo + document + signature (the page shrinks each first); 22 Oct 2026: + a partner's two company certificates

/**
 * The one answer shape for a stored form, an exact resend and the honeypot.
 *
 * @param array{photo: ?string, note: string} $photo cleanPhoto() of what was sent
 */
function agr_answer(string $ref, bool $duplicate, array $photo, string $lang): never
{
    $message = str_replace('{ref}', $ref, AgentApplications::message($duplicate ? 'duplicate' : 'received', $lang));
    if ($photo['note'] !== '') {
        $message .= ' ' . AgentApplications::message('photo', $lang);
    }
    Response::json([
        'ok'          => true,
        'ref'         => $ref,
        'message'     => $message,
        'duplicate'   => $duplicate,
        'photo_saved' => $photo['photo'] !== null && !$duplicate,
    ]);
}

/**
 * The honeypot's answer. The same form again in the same session gets the
 * same ref back as a "duplicate", exactly as a real resend would.
 *
 * @param array<string, mixed> $clean validate()['clean']
 */
function agr_honeypot(array $clean, string $lang): never
{
    $key  = AgentApplications::payloadHash($clean);
    $seen = is_array($_SESSION['agr_hp'] ?? null) ? $_SESSION['agr_hp'] : [];
    $dup  = isset($seen[$key]) && is_string($seen[$key]);
    $ref  = $dup ? (string) $seen[$key] : AgentApplications::fakeRef();
    if (!$dup) {
        $seen[$key] = $ref;
        $_SESSION['agr_hp'] = array_slice($seen, -20, null, true);
    }
    agr_answer($ref, $dup, ['photo' => $clean['photo'], 'note' => (string) $clean['photo_note']], $lang);
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        Security::requireRateLimit('agr_token', Security::clientIp(), 60, 300);
        // 22 Oct 2026 (owner: "fingerprint / face id"): ?challenge=1 = a WebAuthn challenge for the
        // optional phone lock (fingerprint / face unlock). Only the public credential is ever kept.
        if (!empty($_GET['challenge'])) {
            $ch = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $_SESSION['agr_webauthn'] = ['c' => $ch, 't' => time()];
            Response::json(['ok' => true, 'challenge' => $ch, 'rpId' => (string) parse_url(APP_URL, PHP_URL_HOST),
                            'rpName' => Settings::getString('company_name', 'S Hari Global')]);
        }
        Response::json(['ok' => true, 'csrf' => Security::csrfToken()]);
    }
    if ($method !== 'POST') {
        Response::error('This endpoint takes GET or POST.', 405);
    }

    Security::requireCsrf();

    // A 600 KB photo is ~800 KB of base64 (the simple form adds a document
    // photo and a signature, each shrunk on the phone); anything far above
    // that is not one of our pages talking. The header is the cheap first check; the
    // bound on Response::input() is the real one (a missing or lying
    // Content-Length cannot make PHP read and decode 12 MB).
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AGR_BODY_MAX) {
        Response::error('That is too large to send.', 413);
    }
    Response::$maxInputBytes = AGR_BODY_MAX;

    $lang = AgentApplications::normaliseLang(Response::field('lang', 'ne'));
    $ip   = Security::clientIp();
    $busy = str_replace('{phone}', Settings::officePhone(), AgentApplications::message('busy', $lang));

    if (!Security::rateLimit('agr_apply_ip', $ip, 30, 3600)) {
        Response::error($busy, 429);
    }

    if (!AgentApplications::available()) {
        Logger::error('Agreement apply: agent_applications table missing or old — run database/upgrade-2026-09-27-agent-applications.sql');
        Response::error('Applications are not open yet. Please call the office: ' . Settings::officePhone(), 503);
    }

    $check = AgentApplications::validate([
        'type'      => Response::field('type', ''),
        'lang'      => $lang,
        'fields'    => Response::field('fields', []),
        'photo'     => Response::field('photo', null),
        'doc'       => Response::field('doc', null),
        'signature' => Response::field('signature', null),
        'geo'       => Response::field('geo', null),
        'public_ok' => Response::field('public_ok', false),
        'logo'      => Response::field('logo', null),   // 22 Oct 2026: a partner company's logo
        'reg_cert'  => Response::field('reg_cert', null),   // 22 Oct 2026: company registration certificate
        'tax_cert'  => Response::field('tax_cert', null),   // 22 Oct 2026: PAN / VAT / GST certificate
        'thumb'     => Response::field('thumb', null),      // 22 Oct 2026: thumbprint by the phone camera
        'edu_cert'  => Response::field('edu_cert', null),   // 22 Oct 2026: marksheet / certificate (agents)
    ]);
    if (!$check['ok']) {
        Response::invalid($check['errors'], AgentApplications::message('invalid', $lang));
    }
    $clean = $check['clean'];
    $photo = ['photo' => $clean['photo'], 'note' => (string) $clean['photo_note']];
    require_once INCLUDE_PATH . '/applicationimport.php';
    $reviewed = filter_var(Response::field('import_reviewed', false), FILTER_VALIDATE_BOOLEAN);
    $uploaded = ApplicationImport::decode($clean['doc'] ?? null);
    $priorId = false;
    if (!empty(Response::field('edit', false)) && $uploaded === null) {
        require_once INCLUDE_PATH . '/applyedit.php';
        $editing = ApplyEdit::current('agent', (string)$clean['phone']);
        $prior = $editing !== null ? AgentApplications::find($editing['id']) : null;
        $priorId = is_array($prior) && !empty($prior['doc_photo']);
    }
    if ($priorId) {
        if (!$reviewed) { Response::invalid(['import_reviewed' => 'review'], 'मुख्य विवरण जाँचेर ✓ लगाउनुहोस् · Review your main details.'); }
    } else {
        ApplicationImport::requireId($uploaded['bytes'] ?? null, $reviewed);
    }

    // 22 Oct 2026: the optional phone lock, checked against this session's challenge (10 minutes)
    $chal = $_SESSION['agr_webauthn'] ?? null;
    if (is_array($chal) && (int) ($chal['t'] ?? 0) > time() - 3600) {   // a whole form's time (23 Oct 2026, review)
        require_once INCLUDE_PATH . '/staffapplications.php';
        $u = parse_url(APP_URL);
        $cred = StaffApps::webauthnCred(Response::field('webauthn', null), (string) $chal['c'],
            ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
        if ($cred !== null) {
            $clean['webauthn_cred'] = $cred;   // the challenge is spent below, once the form is stored (a 422 / 429 resend keeps it)
        }
    }

    // Honeypot: a person never sees this box, so anything in it is a bot.
    $hp = Response::field('website', '');
    if (is_string($hp) ? trim($hp) !== '' : !empty($hp)) {
        Logger::info('Agreement apply: honeypot filled', ['ip' => $ip]);
        agr_honeypot($clean, $lang);
    }

    // 18 Oct 2026 (owner): a form that carries an ID photo goes through only
    // when that photo shows the typed name AND the typed number. The page
    // checks first (/api/agreement-doccheck.php) and the pass waits in the
    // session; without it the card is read here once more.
    $rawDoc = Response::field('doc', null);
    if (is_string($rawDoc) && trim($rawDoc) !== '' && DocVerify::mode() === 'strict') {
        $idno = (string) ($clean['fields']['p_idno'] ?? '');
        if ($clean['doc'] === null) {
            Response::invalid(['docIn' => 'photo'], 'डकुमेन्टको फोटो खुलेन — फेरि खिच्नुहोस्।');
        }
        // 22 Oct 2026: a partner company's ID photo is its representative's, so it carries p_rep's name
        $docName = (string) $clean['type'] === 'org' && trim((string) ($clean['fields']['p_rep'] ?? '')) !== ''
            ? (string) $clean['fields']['p_rep'] : (string) $clean['name'];
        $vkey = DocVerify::key((string) $clean['doc'], $docName, $idno);
        $vr   = DocVerify::recall($vkey);
        if ($vr === null) {
            $vr = DocVerify::check((string) $clean['doc'], $docName, $idno, (string) ($clean['fields']['p_idtype'] ?? ''));
            if ($vr['status'] !== 'unavailable') {
                DocVerify::remember($vkey, $vr);
            }
        }
        if ($vr['status'] !== 'pass') {
            Logger::info('Agreement apply: document did not match', ['problems' => $vr['problems'], 'ip' => $ip]);
            $bad = [];
            if (in_array('name', $vr['problems'], true))   { $bad[$docName === (string) $clean['name'] ? 'p_name' : 'p_rep'] = 'name'; }
            if (in_array('number', $vr['problems'], true)) { $bad['p_idno'] = 'number'; }
            if ($bad === [])                               { $bad['docIn'] = 'doc'; }
            Response::invalid($bad, DocVerify::message($vr));
        }
        $clean['doc_verify'] = DocVerify::summary($vr);
    }

    /* 23 Oct 2026 (owner: "form apply, edit"): the applicant correcting the form they sent - the number was
       proven in this session (ApplyEdit, /api/my-application.php). The same row is saved over, no second one. */
    if (!empty(Response::field('edit', false))) {
        require_once INCLUDE_PATH . '/applyedit.php';
        $edit = ApplyEdit::current('agent', (string) $clean['phone']);
        if ($edit === null) {
            Response::error('सच्याउने समय सकियो — "पठाएको फारम सच्याउनुहोस्" फेरि खोल्नुहोस् · The edit has closed. Open your form again.', 409);
        }
        try {
            $done = AgentApplications::applicantEdit($edit['id'], $clean);
        } catch (RuntimeException $re) {
            Response::error($re->getMessage(), 409);
        }
        if (isset($clean['webauthn_cred'])) {
            unset($_SESSION['agr_webauthn']);
        }
        ApplyEdit::close();
        require_once INCLUDE_PATH . '/facecheck.php';
        FaceCheck::attach('agent_applications', (int) $done['id']);   // 23 Oct 2026: the live selfie line, when one was made
        $_SESSION['a4_mine'] = ['type' => 'agent', 'id' => (int) $done['id'], 't' => time()];
        Response::json(['ok' => true, 'ref' => $done['ref'], 'edited' => true, 'duplicate' => false, 'photo_saved' => $photo['photo'] !== null,
                        'message' => 'तपाईंको फारम ' . $done['ref'] . ' सच्याइयो। कार्यालयले नयाँ विवरण हेर्नेछ। · Your form ' . $done['ref'] . ' was updated.']);
    }

    // The same form again: its ref, and nothing is charged or stored.
    $retry = AgentApplications::findRetry($clean);
    if ($retry !== null) {
        agr_answer($retry['ref'], true, $photo, $lang);
    }

    // The budgets — only for a form that is about to be stored.
    if (!Auth::isAdmin() && !Security::rateLimit('agr_apply_ip_ok', $ip, 10, 3600)) {
        Response::error($busy, 429);
    }
    if (!Security::rateLimit('agr_apply_phone', (string) $clean['phone'], 3, 86400)) {
        Response::error($busy, 429);
    }
    if (!Security::rateLimit('agr_apply_all_h', 'global', 30, 3600)
        || !Security::rateLimit('agr_apply_all_d', 'global', 150, 86400)) {
        Logger::warning('Agreement apply: the site-wide budget is used up', ['ip' => $ip]);
        Response::error($busy, 429);
    }

    $made = AgentApplications::create($clean, $ip);
    if (!$made['duplicate']) {
        require_once INCLUDE_PATH . '/facecheck.php';
        FaceCheck::attach('agent_applications', (int) $made['id']);   // 23 Oct 2026: the live selfie line, when one was made
    }
    if (isset($clean['webauthn_cred'])) {
        unset($_SESSION['agr_webauthn']);
    }
    // 22 Oct 2026: this browser may open its own filled A4 form at /agreements/form.php?mine=1
    $_SESSION['a4_mine'] = ['type' => 'agent', 'id' => (int) $made['id'], 't' => time()];

    // 22 Oct 2026: the simple form's "hi VERIFY …" WhatsApp check proved this
    // number in this session -> the office sees "WhatsApp verified". Never a gate:
    // the long agreement pages post here too and have no such button.
    $waVerified = false;
    try {
        require_once INCLUDE_PATH . '/waverify.php';
        $waVerified = WaVerify::ready() && WaVerify::sessionVerified((string) $clean['phone']);
        if ($waVerified && !$made['duplicate']) {
            Database::update('agent_applications', ['phone_verified' => 1], 'id = :id', ['id' => (int) $made['id']]);
        }
    } catch (Throwable $e) {
        Logger::warning('Agreement apply: phone_verified not saved: ' . $e->getMessage());
    }

    if (!$made['duplicate']) {
        // One line to the office — at most 10 an hour, then only the log.
        // Name and area are one line already (cleanFields); cut short here
        // too so nothing typed can pose as extra lines of the note.
        $short = static fn(string $s): string => mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, 60, 'UTF-8');
        if (Security::rateLimit('agr_note', 'global', 10, 3600)) {
            try {
                Notify::adminNote('New agreement application', [
                    'Ref'       => $made['ref'],
                    'Type'      => AgentApplications::typeLabel((string) $clean['type']),
                    'Name'      => $short((string) $clean['name']),
                    'Phone'     => (string) $clean['phone'] . ($waVerified ? ' ✓ WhatsApp' : ''),
                    'Area'      => $short((string) $clean['area']),
                    'Repeat of' => (string) ($made['repeat_of'] ?? ''),
                ]);
            } catch (Throwable $e) {
                Logger::warning('Agreement apply: office note not sent: ' . $e->getMessage());
            }
        } else {
            Logger::info('Agreement apply: office note skipped (10 an hour reached)', ['ref' => $made['ref']]);
        }
    }

    agr_answer($made['ref'], $made['duplicate'], $photo, $lang);
} catch (Throwable $e) {
    Response::serverError($e);
}
