<?php
/**
 * admin/logout.php — end the staff session.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

// 22 Oct 2026 (re-attack): an agent from the team portal signs out here too. Leave
// no WhatsApp proof or half-done password reset behind for the next person on
// this browser (TeamPortal::logout() clears the same keys on /team/).
// 31 Oct 2026 (review): the "which ID?" lists and a 2FA step too (Auth::DOOR_KEYS — adminLogout() clears them as well).
foreach (['wa_verified', 'wa_tokens', 'wa_tried', 'team_reset', 'staff_otp_ok', 'staff_otp_try', 'staff_webauthn', 'a4_mine', 'agr_webauthn',
          'ap_login_pick', 'team_pick', 'admin_2fa_pending', 'one_door'] as $k) {
    unset($_SESSION[$k]);
}
Auth::adminLogout();
Response::redirect('admin/login.php');
