<?php
/**
 * /api/profile.php — the signed-in traveller's saved profile (7 Oct 2026).
 *
 *   GET                       -> { profile, others }   (any signed-in customer;
 *                                address/photo only after the WhatsApp code)
 *   GET  ?photo=1             -> the profile photo (JPEG), owner only
 *   POST { action:'save', name, address }     verified session only
 *   POST multipart action=photo, photo=<file> verified session only
 *   POST { action:'photo_remove' }            verified session only
 *
 * See includes/customerprofile.php for why a plain name + mobile session
 * may book but never read or change the address and photo.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/customerprofile.php';

try {
    $me = Auth::user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['photo'])) {
        $uid  = CustomerProfile::verifiedUserId();
        $file = $uid > 0 ? CustomerProfile::photoFor($uid) : '';
        if ($file === '') {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    if ($me === null) {
        Response::error('Please sign in first.', 401);
    }
    $uid = (int) $me['id'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        Security::requireRateLimit('profile_get', Security::clientIp(), 60, 60);
        $row = Database::fetch('SELECT phone, full_name, country_code FROM users WHERE id = :id LIMIT 1', ['id' => $uid]);
        if ($row === null) {
            Response::error('Please sign in first.', 401);
        }
        Response::success([
            'profile' => CustomerProfile::forApp($uid) + [
                'phone'   => (string) $row['phone'],
                'name'    => (string) ($row['full_name'] ?? ''),
                'country' => (string) ($row['country_code'] ?? '') === '977' ? 'NP' : 'IN',
            ],
            // other people's names and numbers: only for a proven number
            'others'  => CustomerProfile::verifiedUserId() === $uid
                ? CustomerProfile::othersBookedFor($uid, (string) $row['phone']) : [],
        ]);
    }

    Security::requirePost();
    Security::requireCsrf();
    Security::requireRateLimit('profile_post', Security::clientIp(), 20, 300);

    if (CustomerProfile::verifiedUserId() !== $uid) {
        Response::error('Please verify your mobile with the WhatsApp code to change your profile.', 403);
    }

    $action = Security::clean((string) Response::field('action', ''), 20);

    if ($action === 'save') {
        CustomerProfile::save(
            $uid,
            Security::clean((string) Response::field('name', ''), 120),
            Security::clean((string) Response::field('address', ''), 255)
        );
    } elseif ($action === 'photo') {
        CustomerProfile::savePhoto($uid, is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : []);
    } elseif ($action === 'photo_remove') {
        CustomerProfile::removePhoto($uid);
    } else {
        Response::error('Unknown action.', 400);
    }

    $row = Database::fetch('SELECT phone, full_name, country_code FROM users WHERE id = :id LIMIT 1', ['id' => $uid]);
    Response::success(['profile' => CustomerProfile::forApp($uid) + [
        'phone'   => (string) ($row['phone'] ?? ''),
        'name'    => (string) ($row['full_name'] ?? ''),
        'country' => (string) ($row['country_code'] ?? '') === '977' ? 'NP' : 'IN',
    ]], 'Saved.');
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
