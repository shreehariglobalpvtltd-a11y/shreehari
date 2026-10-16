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
    $phone   = normalisePhone(Security::clean(Response::field('phone', ''), 20));
    $purpose = Security::clean(Response::field('purpose', 'login'), 20);
    $purpose = in_array($purpose, ['login', 'register', 'reset', 'verify', 'cancel'], true) ? $purpose : 'login';

    if (!Security::isValidPhone($phone)) {
        Response::invalid(['phone' => 'Enter a valid mobile number.']);
    }

    if ($action === 'request') {
        $result = Auth::issueOtp($phone, $purpose, 'sms');

        if (!$result['ok']) {
            Response::error($result['error'] ?? 'Could not send a code.', 429);
        }

        $code = (string) $result['code'];

        // Deliver via WhatsApp click-to-chat is not automatic; log for now
        // and let a configured SMS/WhatsApp provider take over in future.
        Logger::info('OTP issued', ['phone' => maskPhone($phone), 'purpose' => $purpose], 'otp');

        // Try WhatsApp Cloud API if configured (silent no-op otherwise).
        $company = Settings::getString('company_name', APP_NAME);
        Notify::whatsapp($phone, $company . ' verification code: ' . $code . ' (valid ' . OTP_EXPIRY_MINUTES . ' min)');

        $payload = [
            'sent'    => true,
            'expires' => $result['expires'],
            'resendIn'=> OTP_RESEND_SECONDS,
        ];

        // Only leak the code in local/CLI dev — never on a real server, even if APP_DEBUG is on.
        $isLocalCli = PHP_SAPI === 'cli' || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (APP_DEBUG && $isLocalCli) {
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
            $user = Auth::loginUser($phone, $name);
            Response::success([
                'verified' => true,
                'user'     => [
                    'phone' => $user['phone'],
                    'name'  => $user['full_name'],
                    'role'  => $user['role'],
                    'points'=> (int) $user['loyalty_points'],
                    'tier'  => $user['loyalty_tier'],
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
