<?php
/**
 * =====================================================================
 *  index.php — the front controller for the public website.
 *
 *  It boots the backend, hands the browser a small bootstrap object
 *  (public settings, a CSRF token, the API base and the signed-in user)
 *  and then serves the full single-page application, wiring its
 *  window.storage seam to the server so operational data is shared and
 *  admin-managed instead of trapped in one browser.
 *
 *  The application markup lives in app.template.html (protected from
 *  direct access by .htaccess) so this controller stays readable.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';

/* ---------------------------------------------------------------------
 *  Bootstrap payload for the browser.
 * ------------------------------------------------------------------- */
$user = Auth::user();

$boot = [
    'apiBase'  => '/api',
    'appUrl'   => APP_URL,
    'csrf'     => Security::csrfToken(),
    'csrfName' => CSRF_TOKEN_NAME,
    'env'      => APP_ENV,
    'settings' => Settings::publicSettings(),
    'user'     => $user !== null ? [
        'phone'  => $user['phone'] ?? '',
        'name'   => $user['full_name'] ?? ($user['name'] ?? ''),
        'role'   => $user['role'] ?? 'customer',
        'points' => (int) ($user['loyalty_points'] ?? 0),
        'tier'   => $user['loyalty_tier'] ?? 'Silver',
    ] : null,
];

$bootJson = Security::jsonForHtml($boot);

/* ---------------------------------------------------------------------
 *  The injected head script: exposes SHG_BOOT and points the app's
 *  window.storage seam at /api/kv.php. The bridge always keeps a local
 *  copy so the site keeps working if the network (or the server ACL)
 *  says no — guests browse and draft locally, admins write to the server.
 * ------------------------------------------------------------------- */
$inject = <<<HTML
<script>
window.SHG_BOOT = {$bootJson};
(function () {
  var BOOT = window.SHG_BOOT || {};
  var API  = (BOOT.apiBase || '/api');

  function localGet(key) {
    try { var v = localStorage.getItem(key); return v != null ? { value: v } : null; }
    catch (e) { return null; }
  }
  function localSet(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }

  // The seam the single-page app already looks for. Server first for
  // shared data, this browser second — never losing the user's own work.
  window.storage = {
    get: async function (key) {
      try {
        var r = await fetch(API + '/kv.php?action=get&key=' + encodeURIComponent(key), {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        if (r.ok) {
          var j = await r.json();
          if (j && j.ok && j.data && j.data.found && j.data.value != null) {
            localSet(key, j.data.value);        // refresh the local cache
            return { value: j.data.value };
          }
        }
      } catch (e) { /* offline or blocked — fall through */ }
      return localGet(key);
    },
    set: async function (key, value) {
      localSet(key, value);                     // never lose local work
      try {
        await fetch(API + '/kv.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': BOOT.csrf || ''
          },
          body: JSON.stringify({ action: 'set', key: key, value: value })
        });
      } catch (e) { /* server declined (guest) — the local copy stands */ }
      return true;
    }
  };
})();
</script>
HTML;

/* ---------------------------------------------------------------------
 *  Serve the application, splicing the bootstrap into the real <head>
 *  (the second </head> in the file lives inside a JS string, so we only
 *  touch the first one).
 * ------------------------------------------------------------------- */
$templatePath = __DIR__ . '/app.template.html';
$html = @file_get_contents($templatePath);

if ($html === false) {
    http_response_code(500);
    exit('Application template is missing.');
}

$pos = stripos($html, '</head>');
if ($pos !== false) {
    $html = substr($html, 0, $pos) . $inject . "\n" . substr($html, $pos);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
