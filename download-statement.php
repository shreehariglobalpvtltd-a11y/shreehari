<?php
/**
 * =====================================================================
 *  download-statement.php — the public gate for agent statement PDFs
 *  (17 Sep 2026)
 *
 *  Twilio / Meta fetch WhatsApp media from a public URL, so the statement
 *  a supervisor sends from the panel has to be reachable without a
 *  session. The files live under /tickets/statements/ (nginx denies the
 *  whole /tickets tree), so the ONLY way in is this script, which needs:
 *
 *    f     the basename Statement::agentStatementPdf() produced
 *    exp   unix expiry stamped into the link
 *    k     substr(HMAC(APP_KEY, 'stmt|f|exp'), 0, 20)
 *
 *  A signed-in supervisor (commissions.view) or the agent themselves may
 *  also open it without the key while it exists. Everything else is 403.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/statement.php';

try {
    Security::requireRateLimit('stmt_dl', Security::clientIp(), 30, 60);

    $file = basename(Security::clean($_GET['f'] ?? '', 120));
    $exp  = (int) ($_GET['exp'] ?? 0);
    $key  = Security::clean($_GET['k'] ?? '', 64);

    if (!Statement::validName($file)) {
        http_response_code(404);
        exit('No such statement.');
    }

    $allowed = $exp > time() && $key !== '' && hash_equals(Statement::token($file, $exp), $key);

    if (!$allowed && Auth::admin() !== null) {
        // Supervisors, or the agent whose statement it is (id is the first
        // number in the file name), while signed in.
        $agentId = (int) (explode('-', $file)[1] ?? 0);
        $scope   = Auth::bookingScopeAdminId();
        if (Auth::can('commissions.view') || ($scope !== null && $scope === $agentId)) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        Logger::warning('Statement download refused', ['file' => $file, 'ip' => Security::clientIp()], 'security');
        http_response_code(403);
        exit('This statement link has expired or is not valid.');
    }

    $path = Statement::dir() . '/' . $file;
    if (!is_file($path)) {
        http_response_code(404);
        exit('This statement is no longer available.');
    }

    Response::inline($path, 'application/pdf');
} catch (Throwable $e) {
    Logger::error('Statement download failed: ' . $e->getMessage(), [], 'security');
    http_response_code(500);
    exit('The statement could not be served.');
}
