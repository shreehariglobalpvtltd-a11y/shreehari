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
 * counter or scanner login). The app keeps its books in the viewer's own
 * browser (IndexedDB); the website never stores a plaintext book.
 *
 *   /admin/finance.php             open the app
 *   /admin/finance.php?go=<route>&k=v…
 *                                  deep link from another admin page (e.g.
 *                                  ?go=trace&q=SHG-…); the app turns it into
 *                                  its own #/route?k=v on boot. Served exactly
 *                                  like a plain open — a #hash would not survive
 *                                  the login redirect, a query string does.
 *   /admin/finance.php?download=1  download the same file (to open offline on
 *                                  a phone/PC; still behind this admin login).
 *                                  The download is the untouched file: no
 *                                  session token or staff details inside.
 *
 * v2 (9 Oct 2026): when opened (not downloaded) two tags are injected right
 * after <head> so the app can talk to its own admin endpoints:
 *     <meta name="csrf" content="…">         for POSTs (X-CSRF-Token header)
 *     <meta name="shg-admin" content="{…}">  {"id","name","role","can":{"vault","feed"}}
 *        vault = superadmin or the 'finance.vault' grant, never a counter agent
 *                (same rule as admin/api/finance-vault.php)
 *        feed  = reports.view and not a counter agent (admin/api/finance-feed.php)
 * Both are HTML-escaped, and Content-Length is computed on the modified page.
 * The page also gets its own, tighter Content-Security-Policy (it replaces the
 * site-wide one from Security::sendHeaders): no third-party scripts and
 * same-origin fetch only.
 *
 * Development only (APP_ENV !== 'production'): the SHG_FINANCE_HTML
 * environment variable may point at another readable *.html build to serve
 * instead, so a freshly built app can be tested behind the real login.
 */
declare(strict_types=1);
require_once __DIR__ . '/_guard.php';

/**
 * The app file to serve. The SHG_FINANCE_HTML override is honoured only
 * outside production, and only when it resolves to a readable *.html file.
 */
function finance_app_file(string $appEnv, string|false $override, string $default): string
{
    if (strtolower($appEnv) !== 'production' && is_string($override) && $override !== '' && !str_contains($override, "\0")) {
        $real = realpath($override);
        if ($real !== false && is_file($real) && is_readable($real) && strtolower(substr($real, -5)) === '.html') {
            return $real;
        }
    }
    return $default;
}

/**
 * What the app may know about the signed-in staff member (no username, no
 * permissions list — just enough to show or hide the website features).
 *
 * @param array<string,mixed> $admin Auth::admin()
 * @return array{id:int, name:string, role:string, can:array{vault:bool, feed:bool}}
 */
function finance_admin_meta(array $admin): array
{
    $counter = Auth::isCounterAgent();
    return [
        'id'   => (int) ($admin['id'] ?? 0),
        'name' => (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? '')),
        'role' => (string) ($admin['role'] ?? ''),
        'can'  => [
            'vault' => !$counter && (Auth::isSuperadmin() || Auth::can('finance.vault')),
            'feed'  => !$counter && Auth::can('reports.view'),
        ],
    ];
}

/**
 * Insert the csrf + shg-admin <meta> tags right after the first real <head>
 * tag (case-insensitive, attributes allowed, never inside an HTML comment,
 * never <header>). Without a <head> they go after <html …>, else first.
 *
 * @param array<string,mixed> $meta finance_admin_meta()
 */
function finance_inject_meta(string $html, string $csrf, array $meta): string
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $tags = "\n<meta name=\"csrf\" content=\"" . htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">"
          . "\n<meta name=\"shg-admin\" content=\"" . htmlspecialchars((string) $json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">";

    // A "<head>" written inside an HTML comment is not the tag: the position is
    // inside a comment when the last "<!--" before it has no "-->" after it.
    $inComment = static function (int $pos) use ($html): bool {
        $prefix = substr($html, 0, $pos);
        $open = strrpos($prefix, '<!--');
        if ($open === false) {
            return false;
        }
        $close = strpos($prefix, '-->', $open + 2);   // +2: '<!-->' is a closed (empty) comment
        return $close === false;
    };
    foreach (['/<head(?:\s[^>]*)?>/i', '/<html(?:\s[^>]*)?>/i'] as $re) {
        $offset = 0;
        while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $at = (int) $m[0][1];
            if (!$inComment($at)) {
                $end = $at + strlen($m[0][0]);
                return substr($html, 0, $end) . $tags . substr($html, $end);
            }
            $offset = $at + 1;
        }
    }
    return ltrim($tags) . "\n" . $html;
}

/**
 * The page's own CSP: the single-file app inlines everything and fetches only
 * its own admin endpoints, so no CDN script host, no third-party fetch and no
 * remote images (the site-wide policy allows all three for the booking map).
 * default-src 'self' closes everything not listed; frame/worker/media allow
 * same-origin blob: (a print preview or export built in the page) but no
 * other origin.
 */
function finance_csp(): string
{
    return "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline'; "
         . "connect-src 'self'; "
         . "img-src 'self' data: blob:; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' data: https://fonts.gstatic.com; "
         . "frame-src 'self' blob:; "
         . "worker-src 'self' blob:; "
         . "media-src 'self' data: blob:; "
         . "frame-ancestors 'self'; "
         . "object-src 'none'; "
         . "base-uri 'none'; "
         . "form-action 'self'";
}

if (defined('SHG_FINANCE_PAGE_LIB')) {
    return;   // tests: helpers only
}

$admin = admin_boot('reports.view');

$file = finance_app_file(APP_ENV, getenv('SHG_FINANCE_HTML'), INCLUDE_PATH . '/finance/shg-finance-master.html');
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    admin_header('Finance Master', 'finance.php');
    echo '<div class="card"><h2>Finance Master file is missing</h2>'
       . '<p>Expected <code>includes/finance/shg-finance-master.html</code> on the server. Re-deploy the site files.</p></div>';
    admin_footer();
    exit;
}

$download = isset($_GET['download']);
$go = is_string($_GET['go'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{0,23}$/D', (string) $_GET['go']) === 1 ? (string) $_GET['go'] : '';
Logger::audit($download ? 'finance.app_download' : 'finance.app_open', 'finance', '', null, null,
    $download ? 'Finance Master file downloaded' : 'Finance Master opened' . ($go !== '' ? ' (go=' . $go . ')' : ''));

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
if ($download) {
    header('Content-Length: ' . (string) filesize($file));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="shg-finance-master.html"');
    readfile($file);
    exit;
}

$html = file_get_contents($file);
if ($html === false) {
    http_response_code(500);
    exit('Finance Master could not be read.');
}
$html = finance_inject_meta($html, Security::csrfToken(), finance_admin_meta($admin));

header('Content-Security-Policy: ' . finance_csp(), true);
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . (string) strlen($html));
echo $html;
