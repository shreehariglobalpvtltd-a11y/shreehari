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
    // The account's stored dialing code as the app's picker value; unknown stays ''.
    $countryOut = static fn(?string $cc): string => (string) $cc === '977' ? 'NP' : ((string) $cc === '91' ? 'IN' : '');

    /* Sign OUT (6 Sep 2026, one-click Quick Ticket "Not you?"): the app
       used to only forget the passenger locally, so on a shared family
       phone the next person's ticket was still bound to the previous
       number's session. This ends the server session too. No number needed. */
    if ($action === 'logout') {
        unset($_SESSION['shg_cust_otp']);   // CustomerProfile's "code proven" mark
        Auth::forgetOwnPnrs();              // "Not you?" — this browser's own tickets go too (8 Oct 2026)
        Auth::logoutUser();
        Response::success(['signedOut' => true], 'Signed out.');
    }

    if (!Security::isValidPhone($phone)) {
        Response::invalid(['phone' => 'Enter a valid mobile number.']);
    }

    // ------------------------------------------------------------------
    //  'quick' = name + mobile WITHOUT the code ("continue without code").
    //  8 Oct 2026 (owner, overrides the 4 Sep "name + mobile is the sign-in"
    //  rule): this session may BOOK, but name + phone is never proof, so it
    //  opens nothing private — no booking history beyond the tickets this
    //  browser made, no keyless ticket, no cancel, no AI private tools.
    //  It never marks the number verified, never renames or re-countries an
    //  existing account and never answers with the name on file (that made
    //  every mobile number a lookup of its owner's name). The WhatsApp code
    //  (request + verify below) is what proves the number.
    //  Admin -> Settings 'customer_otp_skip' OFF closes this door entirely.
    // ------------------------------------------------------------------
    if ($action === 'quick') {
        Security::requireRateLimit('login_quick', Security::clientIp(), 30, 300);
        if (!Settings::getBool('customer_otp_skip', true)) {
            Response::error('WhatsApp कोडले प्रमाणित गर्नुहोस् · Please verify with the WhatsApp code.', 403, ['code' => 'needs_otp']);
        }
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

        $user = Auth::loginUser($phone, $name, $country, false);
        require_once INCLUDE_PATH . '/customerprofile.php';
        // address / photo / points only when THIS session already proved the number
        $app    = CustomerProfile::forApp((int) $user['id']);
        $proven = (bool) $app['otpVerified'];
        Response::success([
            // signed in for booking; private access still needs the code
            'signedIn' => true,
            'verified' => $proven,
            'needsOtp' => !$proven,
            'user' => $app + [
                'phone' => $user['phone'],
                // Never the name on file to an unproven session: the typed
                // name, or '' (the app keeps what the person typed).
                'name'  => $proven ? (string) ($user['full_name'] ?? '') : (mb_strlen($name) >= 2 ? $name : ''),
                'role'  => $user['role'],
                'points'=> $proven ? (int) $user['loyalty_points'] : 0,
                'tier'  => $proven ? (string) $user['loyalty_tier'] : '',
                // The country on file ('NP'/'IN', '' when unknown), so the
                // checkout can pre-select the number-country picker.
                'country'=> $countryOut((string) ($user['country_code'] ?? '')),
                /* users.preferred_lang: the language a traveller picked follows
                   them to a second device — once the number is proven. */
                'lang'  => $proven ? (string) ($user['preferred_lang'] ?? '') : '',
                'verified' => $proven,
            ],
        ], 'Signed in.');
    }

    if ($action === 'request') {
        // Per-IP cap on paid SMS/WhatsApp sends. The per-phone limits inside
        // issueOtp() never stopped one IP rotating through many numbers.
        Security::requireRateLimit('otp_request_ip', Security::clientIp(), 10, 300);

        /* 8 Oct 2026 (D8-M1): a GLOBAL ceiling on paid code sends. Every
           bucket above is per IP or per number, so many IPs rotating random
           numbers could drain the AiSensy wallet — and an empty wallet stops
           every ticket and bus update too. Past the ceiling nothing is sent:
           the app hears sent:false (its "continue without code" path, when
           the office allows it), the log says why and the office bell rings
           once an hour. */
        $capHour = max(1, Settings::getInt('otp_global_hourly_cap', 150));
        $capDay  = max(1, Settings::getInt('otp_global_daily_cap', 800));
        if (!Security::rateLimit('otp_send_global_h', 'global', $capHour, 3600)
            || !Security::rateLimit('otp_send_global_d', 'global', $capDay, 86400)) {
            Logger::warning('Login code NOT sent: global OTP ceiling reached', [
                'phone' => maskPhone($phone), 'hourCap' => $capHour, 'dayCap' => $capDay,
            ], 'otp');
            try {
                require_once INCLUDE_PATH . '/adminalerts.php';
                AdminAlerts::push(
                    'otp_cap',
                    'WhatsApp login codes paused — too many requests',
                    'More than ' . $capHour . ' codes in an hour (or ' . $capDay . ' in a day) were asked for, so no more are being sent for now. Customers can still book; check for abuse.',
                    '/admin/notifications.php',
                    '⚠️',
                    'otp_cap:' . date('YmdH')
                );
            } catch (Throwable $ignored) {
                // the alert is a courtesy; the refusal above already happened
            }
            Response::success([
                'sent'     => false,
                'capped'   => true,
                'channel'  => 'whatsapp',
                'expires'  => null,
                'resendIn' => OTP_RESEND_SECONDS,
                'canSkip'  => Settings::getBool('customer_otp_skip', true),
            ], 'WhatsApp could not deliver the code right now.');
        }

        $result = Auth::issueOtp($phone, $purpose, 'sms');

        if (!$result['ok']) {
            Response::error($result['error'] ?? 'Could not send a code.', 429);
        }

        $code = (string) $result['code'];

        // Deliver via WhatsApp click-to-chat is not automatic; log for now
        // and let a configured SMS/WhatsApp provider take over in future.
        Logger::info('OTP issued', ['phone' => maskPhone($phone), 'purpose' => $purpose], 'otp');

        /* 7 Oct 2026 (owner): the customer login code goes out on WhatsApp
           through AiSensy - the only WhatsApp sender that works. When it is
           refused right away (empty wallet, not a WhatsApp number) the app
           is told, so it can offer "continue without code" instead of a
           code that is never coming. Other purposes keep the old path. */
        $sent    = null;
        $sendErr = '';
        $company = Settings::getString('company_name', APP_NAME);
        $otpText = $company . ' को कोड: ' . $code . ' (' . OTP_EXPIRY_MINUTES . ' मिनेट सम्म मान्य)। यो कोड कसैलाई नबताउनुहोस्।';
        if (in_array($purpose, ['login', 'register', 'verify'], true) && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
            try {
                if (!class_exists('AiSensyAPI')) {
                    require_once ROOT_PATH . '/whatsapp/aisensy.php';
                }
                if (AiSensyAPI::enabled()) {
                    $intl    = ($country === 'NP' ? '977' : '91') . $phone;
                    /* D4-M3: the greeting carries the name ON FILE for this
                       number (or none) — never text the requester typed, or
                       anyone could make our verified WhatsApp say anything
                       to any number. */
                    $onFile  = (string) Database::scalar('SELECT full_name FROM users WHERE phone = :p LIMIT 1', ['p' => $phone], '');
                    $r       = AiSensyAPI::sendLoginOtp($intl, $code, mb_substr(trim($onFile), 0, 60));
                    $sent    = (bool) $r['success'];
                    $sendErr = $sent ? '' : (string) $r['error'];
                }
            } catch (Throwable $e) {
                $sent    = false;
                $sendErr = $e->getMessage();
            }
        }
        if ($sent === null) {
            if (Settings::getBool('sms_send_otp', true)) {
                Notify::sms($phone, $otpText, $hint);
            }
            // === true: a wa.me click-to-chat link (no sender) is NOT a delivery - the passenger must see the skip box
            $sent = Notify::whatsapp($phone, $otpText, null, $hint) === true;
        }
        if (!$sent) {
            Logger::warning('Login code not delivered', ['phone' => maskPhone($phone), 'error' => mb_substr($sendErr, 0, 160)], 'otp');
        }

        $payload = [
            'sent'     => $sent,
            'channel'  => 'whatsapp',
            'expires'  => $result['expires'],
            'resendIn' => OTP_RESEND_SECONDS,
            // "Continue without code" = the plain name + mobile sign-in of
            // before 7 Oct; offered at once when WhatsApp refused, else after a wait.
            'canSkip'  => Settings::getBool('customer_otp_skip', true),
        ];

        // Never leak the code in production.
        if (APP_DEBUG) {
            $payload['debugCode'] = $code;
        }

        Response::success($payload, $sent ? 'A verification code has been sent on WhatsApp.' : 'WhatsApp could not deliver the code right now.');
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
            /* 7 Oct 2026: the code proved the number, so this session may
               read and change the saved profile (address, photo). */
            require_once INCLUDE_PATH . '/customerprofile.php';
            CustomerProfile::markVerified((int) $user['id'], Security::clean(Response::field('address', ''), 255), [
                'gender'   => (string) Response::field('gender', ''),
                'location' => Security::clean((string) Response::field('location', ''), 255),
                'email'    => (string) Response::field('email', ''),
                'idType'   => (string) Response::field('idType', ''),
                'idNumber' => Security::clean((string) Response::field('idNumber', ''), 60),
            ]);
            Response::success([
                'signedIn' => true,
                'verified' => true,
                'needsOtp' => false,
                'user'     => CustomerProfile::forApp((int) $user['id']) + [
                    'phone' => $user['phone'],
                    'name'  => $user['full_name'],
                    'role'  => $user['role'],
                    'points'=> (int) $user['loyalty_points'],
                    'tier'  => $user['loyalty_tier'],
                    'country'=> $countryOut((string) ($user['country_code'] ?? '')),
                    'lang'  => (string) ($user['preferred_lang'] ?? ''),
                    // the code proved the number: this session may open the
                    // account's bookings, tickets and cancel (Auth::customerMayOpen)
                    'verified' => true,
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
