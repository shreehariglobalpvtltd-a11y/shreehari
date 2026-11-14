<?php
/**
 * =====================================================================
 *  admin/api/id-reveal.php — show ONE full passenger ID number
 *  (15 Nov 2026, P4 desk tools).
 *
 *  The lists (Customers, the booking page) show ID numbers masked
 *  (DeskTools::maskId). A super-admin who needs the whole number presses
 *  "dekhau"; the page posts here and this answers with that one value
 *  and writes an audit row (id.reveal) naming who saw which record. The
 *  number itself is never written to the audit row or the log.
 *
 *  POST JSON {kind: bk|px|cu, ref}   bk = bookings.id, px =
 *  booking_passengers.id, cu = the customer's mobile.
 *  Gates: signed-in staff, super-admin only, CSRF (X-CSRF-Token),
 *  30 reveals a minute per admin.
 * =====================================================================
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_guard.php';
require_once INCLUDE_PATH . '/desktools.php';
$admin = admin_boot();

header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::error('POST only.', 405);
}
if (!Security::verifyCsrf()) {
    Response::error('Your session expired — reload the page and try again.', 419);
}
if (!Auth::isSuperadmin()) {
    Logger::warning('ID reveal refused: not a super-admin', ['admin' => $admin['username'] ?? ''], 'security');
    Response::forbidden('Only a super-admin can see a full ID number. / पूरा नम्बर super-admin ले मात्र हेर्न मिल्छ।');
}
$adminId = (int) ($admin['id'] ?? 0);
if (!Security::rateLimit('id_reveal', 'admin:' . $adminId, 30, 60)) {
    Response::error('Too many reveals in a minute — pause and try again.', 429);
}

$in   = Response::input();
$kind = Security::clean((string) ($in['kind'] ?? ''), 4);
$ref  = Security::clean((string) ($in['ref'] ?? ''), 20);
$val  = DeskTools::revealValue($kind, $ref);
if ($val === null) {
    Response::notFound('No ID number on this record.');
}

Logger::audit('id.reveal', $val['entity'], $ref, null, ['shown' => 'id_number', 'id_type' => $val['type']],
    'Full ID number shown to super-admin #' . $adminId);

Response::json(['ok' => true, 'value' => trim($val['type'] . ' ' . $val['number'])]);
