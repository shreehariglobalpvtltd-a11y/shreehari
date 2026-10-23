<?php
/**
 * /api/customer-login.php — the passenger's sign-in doors that need no
 * WhatsApp credit (23 Oct 2026, owner: "customer login: email ma OTP, natra
 * hi lekhna laune WhatsApp, natra phone ko fingerprint / face").
 *
 *   POST (X-CSRF-Token / csrf from SHG_BOOT), application/json, action =
 *   wa-start   {phone, cc}                        -> {token, url, text, to, qr}
 *   wa-check   {token, name, address, ...extras}  -> {status: waiting|mismatch|expired|unknown}
 *                                                    | {status:'verified', signedIn:true, user:{…}}
 *   email-request {phone, cc}                     -> {sent:true, to:'r•••@gmail.com'} | {sent:false, reason:'no_email'|'wait'}
 *   email-verify  {phone, cc, code, name, …}      -> {signedIn:true, user:{…}}
 *   pk-login-options                              -> {publicKey}
 *   pk-login      {cred}                          -> {signedIn:true, user:{…}}
 *   pk-reg-options (signed in)                    -> {publicKey}
 *   pk-register   {cred, label} (signed in)       -> {ok, id}
 *   pk-list / pk-remove {id} (signed in)
 *
 * Every door ends exactly like api/otp.php "verify": Auth::loginUser +
 * CustomerProfile::markVerified, so the session may open the account's
 * tickets. The passkey stays on the phone; we keep only its public key
 * (team_passkeys, owner_kind 'user').
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/waverify.php';
require_once INCLUDE_PATH . '/passkeys.php';
require_once INCLUDE_PATH . '/customerprofile.php';

try {
    Security::requirePost();
    Security::requireCsrf();
    Response::$maxInputBytes = 60_000;
    $ip      = Security::clientIp();
    $action  = Security::clean(Response::field('action', ''), 24);
    $phone   = normalisePhone(Security::clean(Response::field('phone', ''), 20));
    $cc      = (string) Response::field('cc', '91') === '977' ? '977' : '91';
    $country = $cc === '977' ? 'NP' : 'IN';
    $countryOut = static fn(?string $c): string => (string) $c === '977' ? 'NP' : ((string) $c === '91' ? 'IN' : '');

    /** The same answer as api/otp.php "verify": the session is open and proven. */
    $signIn = static function (string $phone10, string $country, string $how) use ($countryOut): never {
        $name = Security::clean(Response::field('name', ''), 120);
        $user = Auth::loginUser($phone10, $name, $country);
        CustomerProfile::markVerified((int) $user['id'], Security::clean(Response::field('address', ''), 255), [
            'gender'   => (string) Response::field('gender', ''),
            'location' => Security::clean((string) Response::field('location', ''), 255),
            'email'    => (string) Response::field('email', ''),
            'idType'   => (string) Response::field('idType', ''),
            'idNumber' => Security::clean((string) Response::field('idNumber', ''), 60),
        ]);
        Logger::audit('customer.login.' . $how, 'user', (string) $user['id'], null, null, 'Passenger signed in: ' . $how);
        Response::success([
            'status' => 'verified', 'signedIn' => true, 'verified' => true, 'needsOtp' => false,
            'user' => CustomerProfile::forApp((int) $user['id']) + [
                'phone' => $user['phone'], 'name' => $user['full_name'], 'role' => $user['role'],
                'points' => (int) $user['loyalty_points'], 'tier' => $user['loyalty_tier'],
                'country' => $countryOut((string) ($user['country_code'] ?? '')), 'lang' => (string) ($user['preferred_lang'] ?? ''),
                'verified' => true,
            ],
        ], 'Signed in successfully.');
    };
    $needPhone = static function () use ($phone): void {
        if (!Security::isValidPhone($phone) || isPlaceholderPhone($phone)) {
            Response::invalid(['phone' => 'Enter a valid mobile number.']);
        }
    };

    /* ---------------- WhatsApp: send "hi VERIFY …" from the number itself ---------------- */
    if ($action === 'wa-start') {
        $needPhone();
        if (!WaVerify::ready()) {
            Response::error('WhatsApp check is not set up yet.', 503);
        }
        Security::requireRateLimit('cust_wa_ip', $ip, 8, 600);
        Security::requireRateLimit('cust_wa_phone', $phone, 4, 600);
        $s = WaVerify::start('customer', $phone, $country, $ip);
        try {
            require_once INCLUDE_PATH . '/qr.php';
            $s['qr'] = QrCode::dataUri('https://wa.me/' . $s['to'] . '?text=' . rawurlencode($s['text']), 5, 2);
        } catch (Throwable $e) {
            $s['qr'] = '';
        }
        Response::success($s);
    }
    if ($action === 'wa-check') {
        Security::requireRateLimit('cust_wa_check', $ip, 150, 600);
        $r = WaVerify::check((string) Response::field('token', ''));
        if ($r['status'] !== 'verified') {
            Response::success(['status' => $r['status']]);
        }
        $p = (string) $r['phone'];
        if (strlen($p) !== 10) {
            Response::error('That proof is not usable.', 403);
        }
        // the token was for the country the page sent: cc is what the page says, the number is the proven one
        $signIn($p, $country, 'whatsapp');
    }

    /* ---------------- e-mail code: only to the address already on the account ---------------- */
    if ($action === 'email-request') {
        $needPhone();
        Security::requireRateLimit('cust_email_ip', $ip, 10, 900);
        $u = Database::fetch('SELECT id, email, full_name FROM users WHERE phone = :p LIMIT 1', ['p' => $phone]);
        $email = $u !== null ? Security::email((string) ($u['email'] ?? '')) : '';
        if ($email === '') {
            // one answer for "no account" and "no e-mail": nothing about who is a customer
            Response::success(['sent' => false, 'reason' => 'no_email']);
        }
        $otp = Auth::issueOtp('email:' . $phone, 'login', 'email');
        if (!$otp['ok']) {
            Response::success(['sent' => false, 'reason' => 'wait', 'error' => (string) ($otp['error'] ?? '')]);
        }
        $company = Settings::getString('company_name', APP_NAME);
        $code = (string) $otp['code'];
        $html = '<p style="font-family:system-ui,sans-serif;font-size:16px">' . Security::e($company) . ' — login code · लगइन कोड</p>'
              . '<p style="font-family:system-ui,sans-serif;font-size:34px;letter-spacing:.3em;font-weight:900">' . Security::e($code) . '</p>'
              . '<p style="font-family:system-ui,sans-serif;font-size:14px;color:#555">' . OTP_EXPIRY_MINUTES . ' minutes · कसैलाई नबताउनुहोस् · never share it.</p>';
        $sent = Notify::email($email, $company . ' login code: ' . $code, $html, $company . " login code: $code (" . OTP_EXPIRY_MINUTES . " min). Never share it.");
        Logger::info('Customer e-mail code', ['sent' => $sent, 'phone' => maskPhone($phone)]);
        [$lp, $dom] = array_pad(explode('@', $email, 2), 2, '');
        $masked = mb_substr($lp, 0, 1) . '•••@' . $dom;
        Response::success(['sent' => $sent, 'to' => $masked, 'resendIn' => OTP_RESEND_SECONDS] + ($sent ? [] : ['reason' => 'mail']));
    }
    if ($action === 'email-verify') {
        $needPhone();
        $code  = Security::digits(Response::field('code', ''), OTP_LENGTH);
        $check = Auth::verifyOtp('email:' . $phone, $code, 'login');
        if (!$check['ok']) {
            Response::error($check['error'] ?? 'Incorrect code.', 401);
        }
        $signIn($phone, $country, 'email');
    }

    /* ---------------- the phone's own lock (WebAuthn passkey) ---------------- */
    if (str_starts_with($action, 'pk-')) {
        Security::requireRateLimit('cust_passkey', $ip, 60, 300);
        if (!Passkeys::ready()) {
            Response::error('Passkeys are not switched on yet.', 503);
        }
        if ($action === 'pk-login-options') {
            Response::success(['publicKey' => [
                'challenge' => Passkeys::challenge('login'), 'rpId' => Passkeys::rpId(), 'timeout' => 90000,
                'userVerification' => 'required', 'allowCredentials' => [],
            ]]);
        }
        if ($action === 'pk-login') {
            if (!Security::rateLimit('cust_passkey_login', $ip, MAX_LOGIN_ATTEMPTS, 300, LOGIN_LOCKOUT_MIN * 60)) {
                Response::error('Too many tries, wait a few minutes.', 429);
            }
            $cred = Response::field('cred', null);
            if (!is_array($cred)) {
                Response::error('No passkey was offered.', 422);
            }
            try {
                $row = Passkeys::assert($cred);
            } catch (PasskeyError $e) {
                Response::error($e->getMessage(), 422);
            }
            if ((string) $row['owner_kind'] !== 'user') {
                Response::error('That passkey belongs to a staff account - use the team portal.', 403);
            }
            $u = Database::fetch('SELECT id, phone, country_code FROM users WHERE id = :i LIMIT 1', ['i' => (int) $row['owner_id']]);
            if ($u === null || (string) $u['phone'] !== (string) $row['phone10']) {
                Response::error('That passkey no longer matches an account.', 403);
            }
            $signIn((string) $u['phone'], $countryOut((string) $u['country_code']) ?: $country, 'passkey');
        }
        // the rest needs a signed-in, PROVEN passenger (23 Oct 2026 review: a name + mobile "quick" session
        // is not proof - it must never attach a passkey to someone else's number and then open their history)
        $uid = CustomerProfile::verifiedUserId();
        if ($uid <= 0) {
            Response::error('Prove the number first (WhatsApp or e-mail code), then add Face ID / fingerprint.', 401);
        }
        $row = Database::fetch('SELECT id, phone, full_name FROM users WHERE id = :i', ['i' => $uid]);
        if ($row === null) {
            Response::error('Sign in first.', 401);
        }
        if ($action === 'pk-reg-options') {
            Response::success(['publicKey' => [
                'challenge' => Passkeys::challenge('reg'), 'rp' => ['id' => Passkeys::rpId(), 'name' => Settings::getString('company_name', APP_NAME)],
                'user' => ['id' => Passkeys::b64u('shg-user-' . $uid), 'name' => (string) $row['phone'], 'displayName' => (string) ($row['full_name'] ?: $row['phone'])],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
                'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'residentKey' => 'required', 'userVerification' => 'required'],
                'timeout' => 90000, 'attestation' => 'none',
                'excludeCredentials' => array_map(static fn(string $id): array => ['type' => 'public-key', 'id' => $id], Passkeys::credentialIds('user', $uid)),
            ]]);
        }
        if ($action === 'pk-register') {
            $cred = Response::field('cred', null);
            if (!is_array($cred)) {
                Response::error('No credential was sent.', 422);
            }
            try {
                $r = Passkeys::register('user', $uid, (string) $row['phone'], $cred, (string) Response::field('label', ''));
            } catch (PasskeyError $e) {
                Response::error($e->getMessage(), 422);
            }
            Logger::audit('customer.passkey.add', 'user', (string) $uid, null, null, 'Passenger added Face ID / fingerprint');
            Response::success(['id' => (int) ($r['id'] ?? 0)]);
        }
        if ($action === 'pk-list') {
            Response::success(['keys' => Passkeys::listFor('user', $uid)]);
        }
        if ($action === 'pk-remove') {
            Passkeys::remove('user', $uid, (int) Response::field('id', 0), 'user:' . $uid);
            Response::success(['removed' => true]);
        }
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
