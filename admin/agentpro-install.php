<?php
/**
 * admin/agentpro-install.php — install Agent Pro from the browser (Oct 2026).
 *
 * Upload the files, open this page as the super-admin, press ⚡ Install all.
 * Each step says whether it is done; doing a step twice changes nothing.
 * The two PHP files it edits (admin/_guard.php for the menu, includes/
 * wabot.php for the WhatsApp hooks) get a backup first and are parsed
 * before they are saved (includes/agentpro-install.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro-install.php';
$admin = admin_boot('');
if (!Auth::isSuperadmin()) {
    http_response_code(403);
    exit('Only the super-admin can install Agent Pro.');
}
$root = ROOT_PATH;
$log = [];
if (Security::isPost()) {
    if (!Security::verifyCsrf()) {
        $log[] = ['bad', 'Session expired — please try again.'];
    } else {
        $step = (string) ($_POST['step'] ?? '');
        foreach ($step === 'all' ? ['db', 'menu', 'wa'] : [$step] as $s) {
            try {
                if ($s === 'db') {
                    $r = AgentProInstall::runSql(Database::pdo(), $root);
                    $log[] = [$r['errors'] === [] ? 'ok' : 'bad', 'Database: ' . $r['ok'] . ' statements done' . ($r['errors'] !== [] ? ', ' . count($r['errors']) . ' failed:' : '.')];
                    foreach ($r['errors'] as $err) {
                        $log[] = ['bad', $err];
                    }
                    Logger::audit('agentpro.install.db', 'system', 'agentpro', null, ['ok' => $r['ok'], 'errors' => count($r['errors'])]);
                } elseif ($s === 'menu') {
                    $log[] = ['ok', AgentProInstall::patchMenu($root)];
                } elseif ($s === 'wa') {
                    $log[] = ['ok', AgentProInstall::patchWaBot($root)];
                }
            } catch (Throwable $e) {
                $log[] = ['bad', $e->getMessage()];
            }
        }
    }
}
$missingFiles = AgentProInstall::missingFiles($root);
try {
    $missingSchema = AgentProInstall::missingSchema(Database::pdo());
} catch (Throwable $e) {
    $missingSchema = ['(could not read the database: ' . $e->getMessage() . ')'];
}
$menuDone = AgentProInstall::menuDone($root);
$waDone = AgentProInstall::hooksDone($root);
$allDone = $missingFiles === [] && $missingSchema === [] && $menuDone && $waDone;
$cron = '* * * * * php ' . $root . '/cron/agentpro-worker.php >/dev/null 2>&1';
$seen = (int) Settings::getString('ap_worker_seen', '0');
$e = static fn($v): string => Security::e((string) $v);

admin_header('Install Agent Pro', 'agentpro-install');
?>
<style>
.li-step{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 18px;border-top:1px solid var(--line)}
.li-step:first-of-type{border-top:0}
.li-step .n{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;background:var(--soft);color:var(--mut)}
.li-step.done .n{background:var(--ok-bg);color:var(--ok)}
.li-step b{display:block}
.li-step small{color:var(--mut);font-size:12.5px;display:block;margin-top:2px}
.li-log{padding:12px 18px;font-size:13px;line-height:1.6}
.li-log div.ok::before{content:'✓ ';color:var(--ok);font-weight:800}
.li-log div.bad{color:var(--bad)}
.li-log div.bad::before{content:'✗ ';font-weight:800}
.li-code{font-family:var(--f-mono);font-size:12.5px;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:8px 10px;word-break:break-all;display:block;margin:6px 0}
</style>
<?php if ($log !== []): ?>
<div class="panel"><h2>What happened</h2><div class="li-log"><?php foreach ($log as $l): ?><div class="<?= $l[0] ?>"><?= $e($l[1]) ?></div><?php endforeach; ?></div></div>
<?php endif; ?>
<div class="panel">
  <h2>🪪 Install Agent Pro · एजेन्ट प्रो जडान</h2>
  <div class="li-step <?= $missingFiles === [] ? 'done' : '' ?>"><span class="n"><?= $missingFiles === [] ? '✓' : '1' ?></span>
    <div><b>Files uploaded · फाइल अपलोड</b><small><?= $missingFiles === [] ? 'All ' . count(AgentProInstall::FILES) . ' files are in place.' : 'Still missing: ' . $e(implode(', ', $missingFiles)) . ' — upload 1-upload-to-public_html again.' ?></small></div><span></span></div>
  <div class="li-step <?= $missingSchema === [] ? 'done' : '' ?>"><span class="n"><?= $missingSchema === [] ? '✓' : '2' ?></span>
    <div><b>Database · डाटाबेस</b><small><?= $missingSchema === [] ? 'All ' . count(AgentProInstall::TABLES) . ' Agent Pro tables and settings are there.' : 'To add: ' . $e(implode(', ', $missingSchema)) . '. Only new tables and settings — nothing existing changes.' ?></small></div>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="db"><button class="btn <?= $missingSchema === [] ? 'ghost' : 'primary' ?>"><?= $missingSchema === [] ? 'Run again' : 'Run' ?></button></form></div>
  <div class="li-step <?= $menuDone ? 'done' : '' ?>"><span class="n"><?= $menuDone ? '✓' : '3' ?></span>
    <div><b>Menu · मेनु</b><small>Control Center (top) · Team &amp; ID Cards · My ID &amp; Wallet (Agents) · AI (VPS) &amp; WhatsApp (Settings) — in admin/_guard.php, backup kept.</small></div>
    <?php if (!$menuDone): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="menu"><button class="btn primary">Add</button></form><?php else: ?><span></span><?php endif; ?></div>
  <div class="li-step <?= $waDone ? 'done' : '' ?>"><span class="n"><?= $waDone ? '✓' : '4' ?></span>
    <div><b>WhatsApp desk · WhatsApp</b><small>Two small hooks in includes/wabot.php: agents' &amp; staff commands first; the VPS AI for passengers when it is on. Backup kept; nothing else in the bot changes.</small></div>
    <?php if (!$waDone): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="wa"><button class="btn primary">Add</button></form><?php else: ?><span></span><?php endif; ?></div>
  <?php if (!$allDone && $missingFiles === []): ?>
  <div class="li-step"><span class="n">⚡</span><div><b>Everything at once · सबै एकै पटक</b><small>Steps 2, 3 and 4 in one go.</small></div>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="step" value="all"><button class="btn ok">Install all</button></form></div>
  <?php endif; ?>
  <div class="li-step <?= $seen > time() - 600 ? 'done' : '' ?>"><span class="n"><?= $seen > time() - 600 ? '✓' : '5' ?></span>
    <div><b>Background worker (hPanel → Cron Jobs, every minute)</b><small>Answers slow AI messages and reads ID photos with the AI. <?= $seen > 0 ? 'Last ran ' . $e(timeAgo(date('Y-m-d H:i:s', $seen))) . '.' : 'Not seen yet.' ?></small>
      <code class="li-code"><?= $e($cron) ?></code></div><span></span></div>
</div>
<?php if ($allDone): ?>
<div class="panel"><h2>✅ Ready · तयार</h2><div class="li-log">
  Next: <a href="/admin/hub.php"><b>Control Center</b></a> · <a href="/admin/team.php"><b>Team &amp; ID Cards</b></a> ·
  <a href="/admin/ai-local.php"><b>AI (VPS) &amp; WhatsApp</b></a> (pick / download the model, then turn the AI on) ·
  share <a href="/join.php" target="_blank" rel="noopener"><b>/join.php</b></a> with new agents · every member opens <a href="/me/" target="_blank" rel="noopener"><b>/me/</b></a>.
</div></div>
<?php endif; ?>
<?php admin_footer();
