<?php
/**
 * GET /api/config.php — public runtime configuration for the front end.
 *
 * Only settings flagged is_public are exposed. This is what the browser
 * needs to render prices, payment targets and branding without any
 * secret ever leaving the server.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $public = Settings::publicSettings();

    // A CSRF token so the SPA can post to write endpoints.
    $public['csrfToken'] = Security::csrfToken();
    $public['appUrl']    = APP_URL;

    // Signed-in identity, if any.
    $user = Auth::user();
    $public['user'] = $user !== null ? [
        'phone' => $user['phone'],
        'name'  => $user['name'],
        'role'  => $user['role'],
    ] : null;

    Response::success($public);
} catch (Throwable $e) {
    Response::serverError($e);
}
