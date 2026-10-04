<?php
/**
 * admin/backup.php — ☁️ Backups & Google Drive (5 Oct 2026).
 *
 * The nightly database dump (cron/backup.php) has always stayed on the same
 * server it protects. This page sends copies to the owner's own Google
 * Drive: connect once, then back up with one tap (the panel also asks once
 * a day when the last Drive copy is old — Back up / Skip), or let it run
 * every night by itself. Local copies can be made and downloaded here too.
 *
 * Owner (superadmin) or settings.manage only; downloading a raw database
 * file is superadmin-only and audited. includes/gdrive.php has the design.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/gdrive.php';
$admin = admin_boot('dashboard.view');
$me    = (int) $admin['id'];
$super = Auth::isSuperadmin();
if (!$super && !Auth::canManageSettings()) {
    admin_header('Backups', 'backup');
    echo '<div class="flash bad">Backups are for the owner and managers with settings access.</div>';
    admin_footer();
    exit;
}
$json = static function (array $out, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
$flash = null;

/* ---- Google sends the owner back here after "Allow" ---- */
if (isset($_GET['oauth'])) {
    $state = (string) ($_GET['state'] ?? '');
    $want  = (string) ($_SESSION['gdrive_state'] ?? '');
    unset($_SESSION['gdrive_state']);
    if ($want === '' || !hash_equals($want, $state)) {
        $flash = ['bad', 'That Google answer did not match this session — press Connect again.'];
    } elseif (isset($_GET['error'])) {
        $flash = ['bad', 'Google said: ' . Security::clean((string) $_GET['error'], 80) . ' — nothing was connected.'];
    } else {
        $r = GDrive::finishConnect((string) ($_GET['code'] ?? ''));
        Logger::audit('backup.gdrive_connect', 'settings', 'gdrive', null, ['ok' => $r['ok'], 'account' => $r['email'] ?? ''], 'by admin #' . $me);
        if ($r['ok']) {
            header('Location: /admin/backup.php?connected=1');
            exit;
        }
        $flash = ['bad', 'Could not connect: ' . $r['error']];
    }
}
if (isset($_GET['connect'])) {
    if (!GDrive::configured()) {
        $flash = ['bad', 'Paste the Google Client ID and Client secret first (step 1).'];
    } else {
        $_SESSION['gdrive_state'] = bin2hex(random_bytes(16));
        header('Location: ' . GDrive::authUrl((string) $_SESSION['gdrive_state']));
        exit;
    }
}
if (isset($_GET['connected'])) {
    $flash = ['ok', 'Google Drive connected' . (GDrive::account() !== '' ? ' (' . GDrive::account() . ')' : '') . '. Press “Back up to Drive now” to send the first copy.'];
}

/* ---- download a local backup (owner only) ---- */
if (isset($_GET['download'])) {
    $f = basename((string) $_GET['download']);
    if ($f === 'latest') {
        $l = DbDump::latest();
        $f = $l['file'] ?? '';
    }
    $path = BACKUP_PATH . '/' . $f;
    if (!$super || preg_match('/^backup_\d{8}_\d{6}\.sql\.gz$/', $f) !== 1 || !is_file($path)) {
        http_response_code($super ? 404 : 403);
        exit($super ? 'No such backup.' : 'Only the owner can download a database file.');
    }
    Logger::audit('backup.download', 'backup', $f, null, ['size' => filesize($path)], 'downloaded by admin #' . $me);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="shg-' . preg_replace('/^backup_/', '', $f) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/* ---- the once-a-day prompt asks for this ---- */
if (isset($_GET['status'])) {
    $json(['ok' => true, 'connected' => GDrive::connected(), 'due' => GDrive::due(), 'auto' => Settings::getBool('gdrive_auto', false),
           'last' => Settings::getString('gdrive_last_at', ''), 'account' => GDrive::account()]);
}

/* ---- actions ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act  = (string) ($_POST['action'] ?? '');
    $ajax = ($_POST['ajax'] ?? '') === '1';
    if (!Security::verifyCsrf()) {
        if ($ajax) {
            $json(['ok' => false, 'error' => 'Session expired — reload the page.'], 419);
        }
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif ($act === 'run') {
        $r = GDrive::backupNow('manual', $me);
        if ($ajax) {
            $json($r + ['sizeKB' => isset($r['size']) ? (int) round($r['size'] / 1024) : 0]);
        }
        $flash = $r['ok'] ? ['ok', 'Saved to Google Drive: ' . $r['file'] . ' (' . number_format(($r['size'] ?? 0) / 1024) . ' KB' . (!empty($r['encrypted']) ? ', encrypted' : '') . ').']
                          : ['bad', 'Not saved: ' . $r['error']];
    } elseif ($act === 'local') {
        $r = DbDump::run('manual', $me);
        Logger::audit('backup.local', 'backup', $r['file'] ?? '', null, ['ok' => $r['ok']], 'by admin #' . $me);
        $flash = $r['ok'] ? ['ok', 'Local backup made: ' . $r['file'] . ' (' . number_format($r['size'] / 1024) . ' KB, ' . $r['tables'] . ' tables, ' . number_format($r['rows']) . ' rows).']
                          : ['bad', 'Backup failed: ' . ($r['error'] ?? '')];
    } elseif ($act === 'auto_on' || $act === 'auto_off') {
        Settings::set('gdrive_auto', $act === 'auto_on' ? '1' : '0', 'bool', 'backup');
        Logger::audit('backup.gdrive_auto', 'settings', 'gdrive_auto', null, ['on' => $act === 'auto_on'], 'by admin #' . $me);
        if ($ajax) {
            $json(['ok' => true]);
        }
        $flash = ['ok', $act === 'auto_on' ? 'Every night after the 02:15 backup, a copy goes to Google Drive by itself.' : 'Nightly Drive copy switched off.'];
    } elseif ($act === 'save') {
        $cid = trim((string) ($_POST['client_id'] ?? ''));
        if ($cid !== '' && preg_match('/^[0-9A-Za-z._-]+\.apps\.googleusercontent\.com$/', $cid) !== 1) {
            $flash = ['bad', 'That does not look like a Google Client ID (…apps.googleusercontent.com).'];
        } else {
            Settings::set('gdrive_client_id', $cid, 'string', 'backup');
            $sec = trim((string) ($_POST['client_secret'] ?? ''));
            if ($sec !== '') {
                Settings::set('gdrive_client_secret', $sec, 'string', 'backup');
            }
            Settings::set('gdrive_keep', (string) max(3, min(365, (int) ($_POST['keep'] ?? 30))), 'int', 'backup');
            Settings::set('gdrive_encrypt', isset($_POST['encrypt']) ? '1' : '0', 'bool', 'backup');
            Logger::audit('backup.gdrive_settings', 'settings', 'gdrive', null, ['client' => $cid !== ''], 'by admin #' . $me);
            $flash = ['ok', 'Saved.' . (GDrive::configured() && !GDrive::connected() ? ' Now press “Connect Google Drive”.' : '')];
        }
    } elseif ($act === 'disconnect') {
        GDrive::disconnect();
        Logger::audit('backup.gdrive_disconnect', 'settings', 'gdrive', null, null, 'by admin #' . $me);
        $flash = ['ok', 'Google Drive disconnected. Copies already in Drive stay there.'];
    }
}

$local     = DbDump::listLocal(15);
$copies    = [];
$copiesErr = '';
if (GDrive::connected() && isset($_GET['list'])) {
    try {
        $copies = GDrive::listCopies(30);
    } catch (Throwable $e) {
        $copiesErr = $e->getMessage();
    }
}
$e    = static fn ($v): string => Security::e((string) $v);
$csrf = '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . Security::csrfToken() . '">';
$encPassSet = mb_strlen(Settings::getString('backup_offsite_password', '')) >= 10;

admin_header('Backups & Google Drive', 'backup');
?>
<style>
  .bk-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.55}
  .bk-steps{counter-reset:s;display:grid;gap:12px}
  .bk-step{border:1px solid var(--line);border-radius:14px;padding:12px 14px;background:var(--card)}
  .bk-step h3{margin:0 0 6px;font-size:15px}
  .bk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:10px;margin:8px 0}
  .bk-grid label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--mut)}
  .bk-grid input{padding:9px 10px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font:inherit;min-height:40px}
  .bk-copy{display:flex;gap:6px;align-items:center}
  .bk-copy code{flex:1;min-width:0;overflow-wrap:anywhere;background:var(--line);padding:6px 8px;border-radius:7px;font-size:12px}
  .bk-big{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0}
  .bk-ok{color:var(--ok);font-weight:700} .bk-bad{color:var(--bad);font-weight:700}
</style>

<?php if ($flash !== null): ?>
  <p style="display:block;padding:10px 12px;border-radius:10px;background:<?= $flash[0] === 'ok' ? 'var(--ok-bg)' : 'var(--bad-bg)' ?>;color:<?= $flash[0] === 'ok' ? 'var(--ok)' : 'var(--bad)' ?>"><?= $e($flash[1]) ?></p>
<?php endif; ?>

<div class="kpis">
  <div class="kpi <?= GDrive::connected() ? 'tone-green' : 'tone-orange' ?>"><span class="ki"><svg class="a-ic"><use href="#a-upload"/></svg></span><div class="kt"><div class="kk">Google Drive</div><div class="kv"><?= GDrive::connected() ? 'Connected' : 'Not connected' ?></div><div class="ks"><?= $e(GDrive::account() ?: '—') ?></div></div></div>
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-clock"/></svg></span><div class="kt"><div class="kk">Last Drive copy</div><div class="kv"><?= GDrive::lastAt() ? $e(date('d M H:i', GDrive::lastAt())) : 'never' ?></div><div class="ks"><?= Settings::getBool('gdrive_auto', false) ? 'every night automatically' : 'by hand (panel asks daily)' ?></div></div></div>
  <div class="kpi tone-teal"><span class="ki"><svg class="a-ic"><use href="#a-doc"/></svg></span><div class="kt"><div class="kk">On this server</div><div class="kv"><?= count($local) ?> copies</div><div class="ks"><?= $local !== [] ? 'newest ' . $e(date('d M H:i', $local[0]['mtime'])) : 'none yet' ?></div></div></div>
</div>
<?php if (Settings::getString('gdrive_last_error', '') !== ''): ?><p class="bk-note bk-bad">Last problem: <?= $e(Settings::getString('gdrive_last_error', '')) ?></p><?php endif; ?>

<div class="panel">
  <h2>☁️ Back up now</h2>
  <div class="bk-big">
    <?php if (GDrive::connected()): ?>
      <form method="post"><?= $csrf ?><button class="btn" name="action" value="run" type="submit">☁️ Back up to Google Drive now</button></form>
      <form method="post"><?= $csrf ?><button class="btn ghost" name="action" value="<?= Settings::getBool('gdrive_auto', false) ? 'auto_off' : 'auto_on' ?>" type="submit"><?= Settings::getBool('gdrive_auto', false) ? '⏸ Stop the nightly Drive copy' : '🌙 Copy to Drive every night' ?></button></form>
      <a class="btn ghost" href="?list=1">📂 Show copies in Drive</a>
    <?php else: ?>
      <a class="btn" href="#bkSetup">Set up Google Drive ↓</a>
    <?php endif; ?>
    <form method="post"><?= $csrf ?><button class="btn ghost" name="action" value="local" type="submit">💾 Make a backup on the server</button></form>
    <?php if ($super && $local !== []): ?><button class="btn ghost" type="button" id="bkPhone">📲 Save latest to this phone / Drive app</button><?php endif; ?>
  </div>
  <p class="bk-note">“Save to this phone” uses the phone’s share sheet — pick <b>Drive</b> there and it lands in your Google Drive with no setup. On a computer it simply downloads.
    <?= $encPassSet ? 'Drive copies are encrypted with the off-site backup password (keep it on paper — docs/RESTORE.md shows how to open one).' : 'Tip: set an off-site backup password (Settings → automation) and Drive copies are encrypted too.' ?></p>
  <?php if ($copiesErr !== ''): ?><p class="bk-note bk-bad"><?= $e($copiesErr) ?></p><?php endif; ?>
  <?php if ($copies !== []): ?>
    <div class="dt-wrap"><table class="dt"><thead><tr><th>In Google Drive</th><th>Size</th><th>When</th></tr></thead><tbody>
      <?php foreach ($copies as $c): ?><tr><td><a href="https://drive.google.com/file/d/<?= $e($c['id']) ?>/view" target="_blank" rel="noopener"><?= $e($c['name']) ?></a></td><td><?= number_format($c['size'] / 1024) ?> KB</td><td><?= $e(substr(str_replace('T', ' ', $c['created']), 0, 16)) ?> UTC</td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<div class="panel" id="bkSetup">
  <h2>⚙ Google Drive setup <?= GDrive::connected() ? '<span class="bk-ok">✓ connected</span>' : '' ?></h2>
  <div class="bk-steps">
    <div class="bk-step"><h3>1 · Google Cloud “OAuth client” (one time, 5 minutes)</h3>
      <p class="bk-note">console.cloud.google.com → new project “S Hari Backup” → <b>APIs &amp; Services → Library → Google Drive API → Enable</b> →
        <b>OAuth consent screen</b>: External, app name “S Hari Global Backup”, your email → scopes: only <code>…/auth/drive.file</code> → <b>Publish app</b> (“In production” — otherwise Google stops the connection after 7 days) →
        <b>Credentials → Create credentials → OAuth client ID → Web application</b> → Authorised redirect URI:</p>
      <div class="bk-copy"><code id="bkUri"><?= $e(GDrive::redirectUri()) ?></code><button class="btn ghost" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('bkUri').textContent)">Copy</button></div>
      <form method="post" style="margin-top:10px"><?= $csrf ?><input type="hidden" name="action" value="save">
        <div class="bk-grid">
          <label>Client ID<input name="client_id" value="<?= $e(GDrive::clientId()) ?>" placeholder="1234-abc.apps.googleusercontent.com" autocomplete="off"></label>
          <label>Client secret <?= Settings::getString('gdrive_client_secret', '') !== '' ? '(saved — blank keeps it)' : '' ?><input name="client_secret" type="password" autocomplete="new-password" placeholder="GOCSPX-…"></label>
          <label>Keep this many copies in Drive<input type="number" name="keep" min="3" max="365" value="<?= max(3, Settings::getInt('gdrive_keep', 30)) ?>"></label>
        </div>
        <label style="font-size:13px;display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="encrypt" value="1" <?= Settings::getBool('gdrive_encrypt', true) ? 'checked' : '' ?>> encrypt Drive copies with the off-site backup password<?= $encPassSet ? '' : ' (not set yet — copies go up unencrypted)' ?></label>
        <p><button class="btn ghost" type="submit">Save</button></p>
      </form></div>
    <div class="bk-step"><h3>2 · Connect</h3>
      <p class="bk-note">Sign in with the Google account whose Drive should hold the backups and press <b>Allow</b>. The app sees only the files it makes.</p>
      <div class="bk-big">
        <a class="btn <?= GDrive::configured() ? '' : 'ghost' ?>" href="?connect=1">🔗 <?= GDrive::connected() ? 'Reconnect' : 'Connect' ?> Google Drive</a>
        <?php if (GDrive::connected()): ?><form method="post" onsubmit="return confirm('Disconnect Google Drive? Copies already there stay.')"><?= $csrf ?><button class="btn ghost" name="action" value="disconnect" type="submit">Disconnect</button></form><?php endif; ?>
      </div></div>
  </div>
</div>

<div class="panel">
  <h2>💾 Backups on this server</h2>
  <p class="bk-note">Made every night at 02:15 (cron/backup.php) and when you press the button above; the newest <?= max(3, Settings::getInt('backup_keep', 14)) ?> are kept.</p>
  <?php if ($local === []): ?><p class="bk-note">None yet.</p><?php else: ?>
  <div class="dt-wrap"><table class="dt"><thead><tr><th>File</th><th>Size</th><th>Made</th><th></th></tr></thead><tbody>
    <?php foreach ($local as $l): ?><tr><td><?= $e($l['file']) ?></td><td><?= number_format($l['size'] / 1024) ?> KB</td><td><?= $e(date('d M Y H:i', $l['mtime'])) ?></td>
      <td><?php if ($super): ?><a class="btn ghost" href="?download=<?= $e(rawurlencode($l['file'])) ?>">⬇ Download</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($super && $local !== []): ?>
<script>
(function () {
  var b = document.getElementById('bkPhone'); if (!b) return;
  b.addEventListener('click', function () {
    b.disabled = true; var old = b.textContent; b.textContent = '…';
    fetch('?download=latest', { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var name = (r.headers.get('Content-Disposition') || '').replace(/.*filename="([^"]+)".*/, '$1') || 'shg-backup.sql.gz';
      return r.blob().then(function (blob) { return new File([blob], name, { type: 'application/gzip' }); });
    }).then(function (file) {
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        return navigator.share({ files: [file], title: file.name }).catch(function () {});
      }
      var a = document.createElement('a'); a.href = URL.createObjectURL(file); a.download = file.name; document.body.appendChild(a); a.click(); a.remove();
    }).catch(function (e) { alert('Could not get the backup: ' + e.message); })
      .then(function () { b.disabled = false; b.textContent = old; });
  });
})();
</script>
<?php endif; ?>
<?php admin_footer(); ?>
