<?php
/**
 * admin/notifications.php — the notification centre (5 Oct 2026, owner's
 * Point 9): everything the panel has to tell the office, newest first, with
 * the bell in the top bar counting what is unread - and the alert switches.
 *
 * Reads the `notifications` table (new bookings and cancellations have been
 * written there since August; the money and activity alerts are added by
 * includes/adminalerts.php from cron/alerts.php). Reading is dashboard.view;
 * the alert preferences need settings.manage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/adminalerts.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$self    = '/admin/notifications.php';
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        if ($act === 'read') {
            AdminAlerts::markRead((int) ($_POST['id'] ?? 0));
            $go = (string) ($_POST['go'] ?? '');
            if (preg_match('~^/admin/[A-Za-z0-9_.\-/]+(\?[A-Za-z0-9_.\-=&%+]*)?$~', $go)) {
                Response::redirect(ltrim($go, '/'));
            }
        } elseif ($act === 'read_all') {
            $n = AdminAlerts::markAllRead();
            $flash = ['ok', $n . ' notification' . ($n === 1 ? '' : 's') . ' marked read.'];
        } elseif ($act === 'prefs' || $act === 'check') {
            if (!$canEdit) {
                throw new RuntimeException('Changing the alerts needs the Settings permission.');
            }
            if ($act === 'prefs') {
                $n = AdminAlerts::savePrefs((array) ($_POST['r'] ?? []), !empty($_POST['whatsapp']));
                Logger::audit('alerts.prefs', 'settings', 'alerts', null, ['changed' => $n], 'alert preferences saved by admin #' . (int) $admin['id']);
                $flash = ['ok', $n > 0 ? $n . ' setting' . ($n === 1 ? '' : 's') . ' saved. / सेभ भयो।' : 'Nothing changed.'];
            } else {
                $res = AdminAlerts::run();
                $flash = ['ok', 'Checked now: ' . $res['found'] . ' alert' . ($res['found'] === 1 ? '' : 's') . ' stand, ' . $res['new'] . ' new.'];
            }
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$only   = ($_GET['show'] ?? '') === 'all' ? false : true;
$unread = AdminAlerts::unreadCount();
if ($only && $unread === 0) {
    $only = false;   // nothing unread: show the history instead of an empty page
}
$rows  = AdminAlerts::recent(200, $only);
$prefs = AdminAlerts::prefs();
$waOn  = Settings::getBool('alert_whatsapp_on', false);

admin_header('Notifications', 'notifications');
admin_page_head('New bookings, cancellations and the alerts you switched on — unread first. The bell at the top counts these.');
?>
<style>
.nt-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.nt-tabs .chip{min-height:40px;padding:0 15px;text-decoration:none;font-size:13.5px}
.nt-tabs .chip.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.nt-list{display:grid;gap:10px}
.nt{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:4px 12px;align-items:center;padding:12px 14px;border-radius:14px;background:var(--card);
  border:1px solid var(--line);border-left:5px solid var(--line);box-shadow:0 3px 0 rgba(16,38,77,.06),0 8px 18px rgba(16,38,77,.07)}
.nt.new{border-left-color:var(--blue)}
.nt-ic{grid-row:1 / 3;width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:21px;background:var(--soft)}
.nt b{font-size:14.5px;line-height:1.3}.nt p{margin:0;font-size:13px;color:var(--mut);grid-column:2}
.nt time{font-size:11.5px;color:var(--mut);white-space:nowrap;justify-self:end}
.nt-acts{grid-column:3;grid-row:2;display:flex;gap:6px;justify-self:end}
.nt-acts .btn{min-height:36px;padding:0 12px;font-size:12.5px}
.nt.old{opacity:.72}
.nt-prefs{margin-top:18px}
.nt-rule{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 12px;align-items:start;padding:12px 0;border-top:1px solid var(--line)}
.nt-rule:first-of-type{border-top:0}
.nt-rule input[type=checkbox]{width:22px;height:22px;margin-top:2px;accent-color:#1FA35B}
.nt-rule b{font-size:14.5px}.nt-rule small{display:block;color:var(--mut);font-size:12.5px}
.nt-nums{grid-column:2;display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.nt-nums label{display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--ink-2)}
.nt-nums input{width:110px;min-height:40px;padding:6px 9px;border:1px solid var(--line);border-radius:9px;font-size:15px;background:var(--card);color:var(--ink)}
@media(max-width:560px){.nt{grid-template-columns:40px minmax(0,1fr)}.nt time{grid-column:2;justify-self:start}.nt-acts{grid-column:1 / -1;grid-row:auto;justify-self:stretch}.nt-acts .btn{flex:1}}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>" role="status"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="nt-tabs">
  <a class="chip<?= $only ? ' on' : '' ?>" href="<?= $e($self) ?>">Unread (<?= $unread ?>)</a>
  <a class="chip<?= $only ? '' : ' on' ?>" href="<?= $e($self . '?show=all') ?>">All</a>
  <?php if ($unread > 0): ?>
  <form method="post" data-no-guard style="margin-left:auto"><?= Security::csrfField() ?><input type="hidden" name="action" value="read_all">
    <button class="btn ghost sm" type="submit">✓ Mark all read</button></form>
  <?php endif; ?>
</div>

<?php if ($rows === []): ?>
  <?= admin_empty('Nothing here yet', 'New bookings, cancellations and alerts will appear here.', '🔔') ?>
<?php else: ?>
<div class="nt-list">
  <?php foreach ($rows as $n):
      $link = (string) ($n['link'] ?? '');
      if ($link === '' && !empty($n['pnr'])) { $link = '/admin/booking-view.php?pnr=' . rawurlencode((string) $n['pnr']); }
      $new = (int) $n['is_read'] === 0; ?>
  <div class="nt <?= $new ? 'new' : 'old' ?>">
    <span class="nt-ic" aria-hidden="true"><?= $e($n['icon'] ?: '🔔') ?></span>
    <b><?= $e($n['title']) ?></b>
    <time datetime="<?= $e($n['created_at']) ?>" title="<?= $e(formatDate((string) $n['created_at'], 'j M Y, g:i A')) ?>"><?= $e(timeAgo((string) $n['created_at'])) ?></time>
    <p><?= $e($n['body']) ?></p>
    <div class="nt-acts">
      <?php if ($new): ?>
      <form method="post" data-no-guard><?= Security::csrfField() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
        <?php if ($link !== ''): ?><input type="hidden" name="go" value="<?= $e($link) ?>"><button class="btn sm" type="submit">Open →</button>
        <?php else: ?><button class="btn ghost sm" type="submit">✓ Read</button><?php endif; ?></form>
      <?php elseif ($link !== ''): ?><a class="btn ghost sm" href="<?= $e($link) ?>">Open</a><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<details class="panel nt-prefs"<?= isset($_GET['prefs']) || ($flash !== null && in_array($_POST['action'] ?? '', ['prefs', 'check'], true)) ? ' open' : '' ?>>
  <summary style="cursor:pointer;font-weight:800;font-size:15px">⚙️ Alert preferences <span class="muted" style="font-weight:400;font-size:12.5px">· which alerts you get, and at what numbers</span></summary>
  <form method="post" style="margin-top:8px">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="prefs">
    <?php foreach (AdminAlerts::RULES as $rule => [$label, $what, , $params]): ?>
    <div class="nt-rule">
      <input type="checkbox" id="r_<?= $e($rule) ?>" name="r[<?= $e($rule) ?>][on]" value="1"<?= $prefs[$rule]['on'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
      <label for="r_<?= $e($rule) ?>"><b><?= $e($label) ?></b><small><?= $e($what) ?></small></label>
      <?php if ($params !== []): ?>
      <div class="nt-nums">
        <?php foreach ($params as $p => [$plabel]): ?>
        <label><?= $e($plabel) ?><input type="number" min="0" step="1" inputmode="numeric" name="r[<?= $e($rule) ?>][<?= $e($p) ?>]" value="<?= (int) $prefs[$rule][$p] ?>"<?= $canEdit ? '' : ' readonly' ?>></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="nt-rule">
      <input type="checkbox" id="r_wa" name="whatsapp" value="1"<?= $waOn ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
      <label for="r_wa"><b>💬 Also send new alerts to the admin WhatsApp</b><small>One message per check with the alerts that are new (the number in Settings → admin WhatsApp). Off = alerts stay in this panel only.</small></label>
    </div>
    <?php if ($canEdit): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
      <button class="btn ok" type="submit" style="min-height:46px">💾 Save preferences · सेभ गर्नुहोस्</button>
      <button class="btn ghost" type="submit" name="action" value="check" style="min-height:46px">↻ Check now</button>
      <span class="muted" style="font-size:12.5px;align-self:center">Checked automatically every 30 minutes. An alert reads the ledger — it never changes money.</span>
    </div>
    <?php else: ?><p class="muted" style="font-size:12.5px">View only — changing these needs the Settings permission.</p><?php endif; ?>
  </form>
</details>
<?php admin_footer();
