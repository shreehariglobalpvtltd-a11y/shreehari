<?php
/**
 * admin/change-password.php — a staff member sets their own password.
 *
 * Two jobs:
 *   1. The FORCED change (master prompt §20). staff.php hands out a temporary
 *      password and flags the account must_change_pw; Auth::requireAdmin()
 *      funnels that account here and nowhere else until it is cleared. Before
 *      this page existed the flag was written and displayed but never acted
 *      on, so a shared temporary password stayed valid indefinitely.
 *   2. Voluntary changes, from the "Change password" link in the header.
 *
 * Deliberately NOT gated behind a permission: every role must be able to
 * change its own password, and a scanner or agent who cannot reach any other
 * admin page must still be able to reach this one.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

// No permission argument: this page is for whoever is signed in.
$admin = admin_boot();
$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$forced = !empty($_SESSION[ADMIN_SESSION_KEY]['must_change_pw']);
$flash  = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'twofa') {
    // Self-service two-factor toggle (Point 10). The account is already
    // authenticated to be on this page; enabling needs a phone on file
    // (enforced in Auth::setAdmin2fa). Opt-in only — nothing changes for
    // accounts that leave it off.
    Security::requireCsrf();
    $want = (($_POST['twofa'] ?? '') === 'on');
    try {
        Auth::setAdmin2fa((int) $admin['id'], $want, (int) $admin['id']);
        $flash = ['ok', $want
            ? 'Two-factor sign-in is now ON — you will get a code on your mobile at each login.'
            : 'Two-factor sign-in is now OFF for your account.'];
    } catch (Throwable $e) {
        $flash = ['bad', $e->getMessage()];
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();

    $current = (string) ($_POST['current_password'] ?? '');
    $next    = (string) ($_POST['new_password'] ?? '');
    $again   = (string) ($_POST['confirm_password'] ?? '');

    $row = Database::fetch(
        'SELECT id, username, password_hash FROM admins WHERE id = :id LIMIT 1',
        ['id' => (int) $admin['id']]
    );

    /* The current password is required in every normal case: it is what stops
       someone who walks up to an unattended signed-in browser from locking the
       real owner out of the account.

       One exception, and only one: a FORCED change on a session that arrived
       through the agent code + mobile-OTP door. That user has never typed a
       password, so requiring the old one would deadlock them — which is
       exactly why Auth::finishAdminSession() used to drop the must_change_pw
       flag for OTP logins altogether, quietly voiding the control. Honouring
       the flag and carving out this one case enforces the change instead of
       skipping it. The unattended-browser risk does not apply here, because
       reaching this session at all required a WhatsApp OTP to the registered
       mobile — a stronger proof than the temporary password being replaced. */
    $otpForced = $forced && !empty($_SESSION[ADMIN_SESSION_KEY]['via_otp']);

    if ($row === null) {
        $flash = ['bad', 'Your account could not be loaded. Please sign in again.'];
    } elseif (!$otpForced && !Security::verifyPassword($current, (string) $row['password_hash'])) {
        $flash = ['bad', 'Your current password is not correct.'];
        Logger::audit('admin.changepw.fail', 'admin', (string) $row['username'],
            null, null, 'wrong current password');
    } elseif (strlen($next) < 8) {
        $flash = ['bad', 'The new password must be at least 8 characters.'];
    } elseif ($next !== $again) {
        $flash = ['bad', 'The two new passwords do not match.'];
    } elseif (Security::verifyPassword($next, (string) $row['password_hash'])) {
        /* A forced change that re-sets the same temporary password would
           defeat the whole point of forcing it. */
        $flash = ['bad', 'Please choose a password different from your current one.'];
    } else {
        Database::update('admins', [
            'password_hash'  => Security::hashPassword($next),
            'must_change_pw' => 0,
        ], 'id = :id', ['id' => (int) $row['id']]);

        // Clear the gate for this session too, or requireAdmin() keeps
        // bouncing them back here on the next click.
        $_SESSION[ADMIN_SESSION_KEY]['must_change_pw'] = false;

        // A password change is a session-fixation boundary.
        session_regenerate_id(true);

        Logger::audit('admin.changepw', 'admin', (string) $row['username'],
            null, null, 'password changed by the account holder'
                . ($otpForced ? ' (forced change via OTP session — old password not required)' : ''));

        $flash  = ['ok', 'Your password has been changed.'];
        $forced = false;
    }
}

$my2fa   = Auth::admin2faEnabled((int) $admin['id']);
$myPhone = normalisePhone((string) Database::scalar('SELECT phone FROM admins WHERE id = :id', ['id' => (int) $admin['id']], ''));

admin_header('Change password', '');
?>
<style>
  .pwwrap { max-width: 460px; }
  .pwwrap label { display:block; margin:14px 0 4px; font-weight:600; }
  .pwwrap input { width:100%; padding:10px; font-size:16px; }
  .pwnote { background:#fff4d1; color:#8a6d00; border-radius:8px; padding:12px 14px; margin-bottom:6px; }
</style>

<div class="pwwrap">
  <h2 style="margin-top:0">Change password</h2>

  <?php
    // Mirrors the POST-side rule: a forced change on an OTP session does not
    // ask for the old password, because the agent never had one to type.
    $otpForcedView = $forced && !empty($_SESSION[ADMIN_SESSION_KEY]['via_otp']);
  ?>
  <?php if ($forced): ?>
    <p class="pwnote">
      <strong>You must change your password before continuing.</strong><br>
      This account is still using the temporary password it was created with.
      <?php if ($otpForcedView): ?>
        <br>You signed in with an OTP, so just set a new password below — you
        do not need the old one.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if ($flash !== null): ?>
    <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? '#d9f5e3' : '#ffdede' ?>;color:<?= $flash[0] === 'ok' ? '#0a6b33' : '#8a1f1f' ?>">
      <?= Security::e($flash[1]) ?>
    </p>
  <?php endif; ?>

  <?php if (!$forced && ($flash[0] ?? '') === 'ok'): ?>
    <p><a class="btn" href="<?= $base ?>/admin/index.php">← Back to the dashboard</a></p>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?= Security::csrfField() ?>

    <?php if (!$otpForcedView): ?>
      <label for="cur">Current password</label>
      <input id="cur" type="password" name="current_password" required autocomplete="current-password">
    <?php endif; ?>

    <label for="np">New password</label>
    <input id="np" type="password" name="new_password" required minlength="8"
           placeholder="at least 8 characters" autocomplete="new-password">

    <label for="cp">Repeat new password</label>
    <input id="cp" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">

    <p style="margin-top:18px">
      <button class="btn" type="submit">Change password</button>
      <?php if (!$forced): ?>
        <a class="btn ghost" href="<?= $base ?>/admin/index.php">Cancel</a>
      <?php else: ?>
        <a class="btn ghost" href="<?= $base ?>/admin/logout.php">Sign out instead</a>
      <?php endif; ?>
    </p>
  </form>

  <?php /* Two-factor sign-in (Point 10) — opt-in. Not shown during a forced
           password change so nothing distracts from clearing that gate. */ ?>
  <?php if (!$forced): ?>
  <div style="margin-top:26px;padding-top:18px;border-top:1px solid var(--line)">
    <h2 style="margin:0 0 6px;font-size:16px">🔐 Two-factor sign-in <span class="pill" style="font-size:11px;padding:2px 8px;background:<?= $my2fa ? '#d9f5e3' : '#eef1f6' ?>;color:<?= $my2fa ? '#0a6b33' : '#6b7688' ?>"><?= $my2fa ? 'ON' : 'OFF' ?></span></h2>
    <p class="muted" style="font-size:13px;margin:0 0 12px;max-width:460px">
      When ON, each sign-in also asks for a one-time code sent to your mobile
      <?php if ($myPhone !== ''): ?>(ending <?= Security::e(substr($myPhone, -4)) ?>)<?php endif; ?>.
      Your password is still required — the code is an extra step, so it never
      locks you out if a message is delayed.
    </p>
    <?php if ($myPhone === '' && !$my2fa): ?>
      <p class="pwnote" style="max-width:460px">Add a mobile number to your account (ask an admin on the Staff page) before turning this on — that is where the code is sent.</p>
    <?php endif; ?>
    <form method="post" style="margin:0">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="twofa">
      <input type="hidden" name="twofa" value="<?= $my2fa ? 'off' : 'on' ?>">
      <button class="btn <?= $my2fa ? 'ghost' : '' ?>" type="submit" <?= ($myPhone === '' && !$my2fa) ? 'disabled' : '' ?>>
        <?= $my2fa ? 'Turn OFF two-factor' : 'Turn ON two-factor' ?>
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
