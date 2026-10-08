<?php
/**
 * admin/finance.php — SHG Finance Master (CEO finance command center).
 *
 * The app itself is ONE self-contained HTML file kept outside the web root's
 * reach (includes/ is denied by nginx and includes/.htaccess):
 *
 *     includes/finance/shg-finance-master.html
 *
 * This page is the only door to it from the website: an admin must be signed
 * in and hold reports.view (superadmin, manager, accountant — never an agent,
 * counter or scanner login). It simply streams the file; the app keeps its
 * books in the viewer's own browser (localStorage) and never calls back to
 * this server, so no finance data is stored here and nothing in the booking
 * database is read or changed.
 *
 *   /admin/finance.php             open the app
 *   /admin/finance.php?download=1  download the same file (to open offline on
 *                                  a phone/PC; still behind this admin login)
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('reports.view');

$file = INCLUDE_PATH . '/finance/shg-finance-master.html';
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    admin_header('Finance Master', 'finance.php');
    echo '<div class="card"><h2>Finance Master file is missing</h2>'
       . '<p>Expected <code>includes/finance/shg-finance-master.html</code> on the server. Re-deploy the site files.</p></div>';
    admin_footer();
    exit;
}

$download = isset($_GET['download']);
Logger::audit($download ? 'finance.app_download' : 'finance.app_open', 'finance', '', null, null,
    $download ? 'Finance Master file downloaded' : 'Finance Master opened');

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Length: ' . (string) filesize($file));
if ($download) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="shg-finance-master.html"');
} else {
    header('Content-Type: text/html; charset=utf-8');
}
readfile($file);
