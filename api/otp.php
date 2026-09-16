<?php
/**
 * POST /api/otp.php — request or verify a one-time password.
 * Body: { action:'request'|'verify', phone, code?, name?, purpose? }
 *
 * In production the code is delivered by SMS/WhatsApp/email. Until an SMS
 * provider is configured the code is written to the log and, in debug
 * builds only, returned in the response for testing.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $action  = Security::clean(Response::field('action', 'request'), 20);
    $rawPhone = Security::clean(Response::field('phone', ''), 20);
    $phone   = normalisePhone($rawPhone);
    $purpose = Security::clean(Response::field('purpose', 'login'), 20);
    $purpose = in_array($purpose, ['login', 'register', 'reset', 'verify', 'cancel'], true) ? $purpose : 'login';

    // Country of the number ('IN'/'NP'). Prefer the explicit picker value;
    // otherwise infer from a country-code prefix on the raw input. Nepal and
    // India share 10-digit mobiles, so this cannot be derived from $phone.
    $country = strtoupper(Security::clean(Response::field('country', ''), 4));
    if ($country !== 'IN' && $country !== 'NP') {
        $rd = preg_replace('/\D/', '', $rawPhone) ?? '';
        if (strlen($rd) >= 13 && str_starts_with($rd, '977'))      { $country = 'NP'; }
        elseif (strlen($rd) >= 12 && str_starts_with($rd, '91'))   { $country = 'IN'; }
        else                                                        { $country = ''; }
    }
    $hint = $country === 'NP' ? 'NP' : ($country === 'IN' ? 'IN' : null);

    /* Sign OUT (6 Sep 2026, one-click Quick Ticket "Not you?"): the app
       used to only forget the passenger locally, so on a shared family
       phone the next person's ticket was still bound to the previous
       number's session. This ends the server session too. No number needed. */
    if ($action === 'logout') {
        Auth::logoutUser();
        Response::success(['signedOut' => true], 'Signed out.');
    }

    if (!Security::isValidPhone($phone)) {
        Response::invalid(['phone' => 'Enter a valid mobile number.']);
    }

    // ------------------------------------------------------------------
    //  Sign-in = NAME + MOBILE, nothing else (owner's rule, 4 Sep 2026).
    //  The same two details go straight onto the ticket, and one account
    //  books as many seats / tickets as it likes. A brand-new number is
    //  registered on the spot; a returning traveller is welcomed back.
    //  Guardrails: a suspended account is refused; a name is required the
    //  first time (afterwards the name on file stands in); and the whole
    //  action is rate-limited per IP so nobody can enumerate numbers.
    //  The OTP path below still exists for cancellations and as a fallback.
    // ------------------------------------------------------------------
    if ($action === 'quick') {
        Security::requireRateLimit('login_quick', Security::clientIp(), 30, 300);
        $name = Security::clean(Response::field('name', ''), 120);
        if ($name !== '' && mb_strlen($name) < 2) {
            Response::invalid(['name' => 'Please enter your name.']);
        }

        $existing = Database::fetch(
            'SELECT id, full_name, is_blocked, preferred_lang FROM users WHERE phone = :p LIMIT 1',
            ['p' => $phone]
        );
        if ($existing !== null && (int) ($existing['is_blocked'] ?? 0) === 1) {
            Response::error('This account has been suspended. Please contact the office.', 403);
        }
        if ($name === '' && ($existing === null || trim((string) ($existing['full_name'] ?? '')) === '')) {
            Response::invalid(['name' => 'Please enter your name.']);
        }

        $user = Auth::loginUser($phone, $name, $country);
        Response::success([
            'needsOtp' => false,
            'verified' => true,
            'user' => [
                'phone' => $user['phone'],
                'name'  => $user['full_name'],
                'role'  => $user['role'],
                'points'=> (int) $user['loyalty_points'],
                'tier'  => $user['loyalty_tier'],
                // The country on file ('NP'/'IN'), so the checkout can pre-select
                // the number-country picker instead of defaulting to +91.
                'country'=> ((string) ($user['country_code'] ?? '') === '977' ? 'NP' : 'IN'),
                /* users.preferred_lang has existed since the first schema
                   with ZERO code reading or writing it, so the language a
                   traveller picked never followed them to a second device.
                   Handed back here and applied on sign-in. */
                'lang'  => (string) ($user['preferred_lang'] ?? ''),
            ],
        ], $existing === null ? 'Welcome to S Hari Global.' : 'Welcome back.');
    }

    if ($action === 'request') {
        // Per-IP cap on paid SMS/WhatsApp sends. The per-phone limits inside
        // issueOtp() never stopped one IP rotating through many numbers.
        Security::requireRateLimit('otp_request_ip', Security::clientIp(), 10, 300);
        $result = Auth::issueOtp($phone, $purpose, 'sms');

        if (!$result['ok']) {
            Response::error($result['error'] ?? 'Could not send a code.', 429);
        }

        $code = (string) $result['code'];

        // Deliver via WhatsApp click-to-chat is not automatic; log for now
        // and let a configured SMS/WhatsApp provider take over in future.
        Logger::info('OTP issued', ['phone' => maskPhone($phone), 'purpose' => $purpose], 'otp');

        // Deliver the code. SMS first (when the SMS channel is on), then a
        // WhatsApp attempt (silent no-op if no WhatsApp driver is configured).
        $company = Settings::getString('company_name', APP_NAME);
        $otpText = $company . ' verification code: ' . $code . ' (valid ' . OTP_EXPIRY_MINUTES . ' min). Do not share this code.';
        if (Settings::getBool('sms_send_otp', true)) {
            Notify::sms($phone, $otpText, $hint);
        }
        Notify::whatsapp($phone, $otpText, null, $hint);

        $payload = [
            'sent'    => true,
            'expires' => $result['expires'],
            'resendIn'=> OTP_RESEND_SECONDS,
        ];

        // Never leak the code in production.
        if (APP_DEBUG) {
            $payload['debugCode'] = $code;
        }

        Response::success($payload, 'A verification code has been sent.');
    }

    if ($action === 'verify') {
        $code = Security::digits(Response::field('code', ''), OTP_LENGTH);
        $name = Security::clean(Response::field('name', ''), 120);

        $check = Auth::verifyOtp($phone, $code, $purpose);
        if (!$check['ok']) {
            Response::error($check['error'] ?? 'Incorrect code.', 401);
        }

        // Login purposes sign the customer in.
        if (in_array($purpose, ['login', 'register', 'verify'], true)) {
            $user = Auth::loginUser($phone, $name, $country);
            Response::success([
                'verified' => true,
                'user'     => [
                    'phone' => $user['phone'],
                    'name'  => $user['full_name'],
                    'role'  => $user['role'],
                    'points'=> (int) $user['loyalty_points'],
                    'tier'  => $user['loyalty_tier'],
                    'country'=> ((string) ($user['country_code'] ?? '') === '977' ? 'NP' : 'IN'),
                ],
            ], 'Signed in successfully.');
        }

        Response::success(['verified' => true], 'Verified.');
    }

    Response::error('Unknown action.', 400);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
