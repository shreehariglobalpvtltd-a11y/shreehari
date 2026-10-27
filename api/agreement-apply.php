<?php
/**
 * /api/agreement-apply.php — "apply for an agreement" from /agreements/.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/docverify.php';

const AGR_BODY_MAX = 3_500_000;

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
        Logger::error('Agreement apply: agent_applications table missing');
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
        'logo'      => Response::field('logo', null),
        'reg_cert'  => Response::field('reg_cert', null),
        'tax_cert'  => Response::field('tax_cert', null),
        'thumb'     => Response::field('thumb', null),
        'edu_cert'  => Response::field('edu_cert', null),
    ]);
    if (!$check['ok']) {
        Response::invalid($check['errors'], AgentApplications::message('invalid', $lang));
    }
    $clean = $check['clean'];
    $photo = ['photo' => $clean['photo'], 'note' => (string) $clean['photo_note']];

    $chal = $_SESSION['agr_webauthn'] ?? null;
    if (is_array($chal) && (int) ($chal['t'] ?? 0) > time() - 3600) {
        require_once INCLUDE_PATH . '/staffapplications.php';
        $u = parse_url(APP_URL);
        $cred = StaffApps::webauthnCred(Response::field('webauthn', null), (string) $chal['c'],
            ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
        if ($cred !== null) {
            $clean['webauthn_cred'] = $cred;
        }
    }

    $hp = Response::field('website', '');
    if (is_string($hp) ? trim($hp) !== '' : !empty($hp)) {
        Logger::info('Agreement apply: honeypot filled', ['ip' => $ip]);
        agr_honeypot($clean, $lang);
    }

    $rawDoc = Response::field('doc', null);
    if (is_string($rawDoc) && trim($rawDoc) !== '' && DocVerify::mode() === 'strict') {
        $idno = (string) ($clean['fields']['p_idno'] ?? '');
        if ($clean['doc'] === null) {
            Response::invalid(['docIn' => 'photo'], 'डकुमेन्टको फोटो खुलेन — फेरि खिच्नुहोस्।');
        }
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

    $retry = AgentApplications::findRetry($clean);
    if ($retry !== null) {
        agr_answer($retry['ref'], true, $photo, $lang);
    }

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
        FaceCheck::attach('agent_applications', (int) $made['id']);

        // Multi-role category & parent agent linkage (4-tier hierarchy)
        $roleCat = (string) Response::field('role_category', Response::field('role', 'agent'));
        if (!in_array($roleCat, ['staff', 'agent', 'subagent', 'partner'], true)) {
            $roleCat = 'agent';
        }
        $parentCode = trim((string) Response::field('parent_agent_code', Response::field('parent_code', '')));
        $parentId = null;
        if ($parentCode !== '') {
            require_once INCLUDE_PATH . '/agentwallet.php';
            $parentId = AgentWallet::resolveAgentCodeFromString($parentCode);
        }
        Database::update('agent_applications', [
            'role_category'   => $roleCat,
            'parent_agent_id' => $parentId,
        ], 'id = :id', ['id' => (int) $made['id']]);
    }
    if (isset($clean['webauthn_cred'])) {
        unset($_SESSION['agr_webauthn']);
    }
    $_SESSION['a4_mine'] = ['type' => 'agent', 'id' => (int) $made['id'], 't' => time()];

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
        }
    }

    agr_answer($made['ref'], $made['duplicate'], $photo, $lang);
} catch (Throwable $e) {
    Response::serverError($e);
}
