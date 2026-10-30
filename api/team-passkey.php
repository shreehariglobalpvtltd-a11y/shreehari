<?php
/**
 * /api/team-passkey.php — Face ID / fingerprint (WebAuthn passkeys) for the
 * team portal (23 Oct 2026, owner: digital identity upgrade).
 *
 *   GET  ?op=reg-options     signed in: the create() options (challenge, rpId, user, excludes)
 *   POST op=register         signed in: {cred, label} -> stored (public key only)
 *   GET  ?op=login-options   anyone: the get() options (challenge, rpId); discoverable credentials
 *   POST op=login            {cred} -> session opened like a password sign-in
 *   POST op=remove           signed in: {id} -> that device gone
 *
 * Every POST carries the CSRF token (X-CSRF-Token, the meta on /team/).
 * Only the public key is ever stored; the fingerprint / face stays on the phone.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/passkeys.php';

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $op = (string) ($method === 'GET' ? ($_GET['op'] ?? '') : Response::field('op', ''));
    $ip = Security::clientIp();
    Security::requireRateLimit('team_passkey', $ip, 60, 300);
    if (!Passkeys::ready() || !TeamPortal::ready()) {
        Response::error('Passkeys are not switched on yet · अहिले चलेको छैन।', 503);
    }
    $b64uUser = static fn(string $kind, int $id): string => Passkeys::b64u('shg-' . $kind . '-' . $id);

    if ($method === 'GET' && $op === 'login-options') {
        $challenge = Passkeys::challenge('login');
        // 30 Oct 2026 (one door): the same challenge for a fingerprint added at /me/ (ap_passkeys), so
        // /admin/login.php opens it too now that /me/ sends signed-out people to the one door.
        $_SESSION['ap_pk'] = ['c' => $challenge, 'at' => time(), 'for' => 'login'];
        Response::json(['ok' => true, 'publicKey' => [
            'challenge' => $challenge, 'rpId' => Passkeys::rpId(), 'timeout' => 90000,
            'userVerification' => 'required', 'allowCredentials' => [],
        ]]);
    }
    if ($method === 'GET' && $op === 'reg-options') {
        $me = TeamPortal::current();
        $own = $me !== null ? TeamPortal::passkeyOwner($me) : null;
        if ($own === null) {
            Response::error('Sign in first.', 401);
        }
        $exclude = array_map(static fn(string $id): array => ['type' => 'public-key', 'id' => $id], Passkeys::credentialIds($own[0], $own[1]));
        Response::json(['ok' => true, 'publicKey' => [
            'challenge' => Passkeys::challenge('reg'),
            'rp' => ['id' => Passkeys::rpId(), 'name' => Settings::getString('company_name', 'S Hari Global')],
            'user' => ['id' => $b64uUser($own[0], $own[1]), 'name' => $me['phone10'] !== '' ? $me['phone10'] : 'team', 'displayName' => (string) $me['name']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'required', 'userVerification' => 'required'],
            'attestation' => 'none', 'timeout' => 90000, 'excludeCredentials' => $exclude,
        ]]);
    }
    if ($method !== 'POST') {
        Response::error('Unknown request.', 400);
    }
    Security::requireCsrf();
    $cred = Response::field('cred', null);
    if ($op === 'login') {
        if (!Security::rateLimit('team_passkey_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
            Response::error('धेरै पटक गलत भयो — केही मिनेटपछि फेरि प्रयास गर्नुहोस् · Too many tries, wait a few minutes.', 429);
        }
        if (!is_array($cred)) {
            Response::error('No passkey was offered.', 422);
        }
        $credId = (string) ($cred['id'] ?? '');
        if ($credId !== '' && strlen($credId) <= 700 && Auth::oneDoorOn()
            && !Database::exists('SELECT 1 FROM team_passkeys WHERE credential_id = :c', ['c' => $credId])) {
            /* 30 Oct 2026 (one door): a fingerprint added in /me/ lives in ap_passkeys. Verified by
               ApPasskey (its own key store, the challenge mirrored above), then opened like every
               no-password door — Auth::openAdminWithoutPassword keeps the 2FA rule and the password
               stamp that ApPasskey::startSession skipped. */
            $apRow = null;
            try {
                $apRow = Database::fetch('SELECT 1 FROM ap_passkeys WHERE credential_id = :c', ['c' => rtrim($credId, '=')]);
            } catch (Throwable $e) {
                $apRow = null;
            }
            if ($apRow !== null) {
                require_once INCLUDE_PATH . '/agentpro-passkey.php';
                unset($_SESSION['team_passkey']['login']);
                try {
                    $proved = ApPasskey::loginVerify($cred);
                    $full = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $proved['id']]);
                    if ($full === null) {
                        throw new RuntimeException('This account is not active.');
                    }
                    Auth::openAdminWithoutPassword($full, 'passkey');
                } catch (RuntimeException $e) {
                    Response::error($e->getMessage(), 403);
                }
                Response::json(['ok' => true, 'kind' => 'admin', 'next' => Auth::homeUrl()]);
            }
        }
        unset($_SESSION['ap_pk']);
        try {
            $row = Passkeys::assert($cred);
            $r = TeamPortal::passkeyLogin($row);
        } catch (PasskeyError $e) {
            Response::error($e->getMessage(), 422);
        }
        if (!$r['ok']) {
            Response::error((string) $r['error'], 403);
        }
        // 30 Oct 2026: an office / member account lands on the one home (switch on); a staff-only one on /team/
        Response::json(['ok' => true, 'kind' => $r['kind'], 'next' => $r['kind'] === 'admin' && Auth::oneDoorOn() ? Auth::homeUrl() : '/team/']);
    }

    $me = TeamPortal::current();
    $own = $me !== null ? TeamPortal::passkeyOwner($me) : null;
    if ($own === null) {
        Response::error('Sign in first.', 401);
    }
    if ($op === 'register') {
        if (!is_array($cred)) {
            Response::error('No credential received.', 422);
        }
        try {
            $made = Passkeys::register($own[0], $own[1], (string) $me['phone10'], $cred, (string) Response::field('label', ''));
        } catch (PasskeyError $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::json(['ok' => true] + $made);
    }
    if ($op === 'remove') {
        $ok = Passkeys::remove($own[0], $own[1], (int) Response::field('id', 0), 'the person at /team/');
        Response::json(['ok' => $ok]);
    }
    Response::error('Unknown request.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
