<?php
/**
 * admin/wa-verify.php?t=<token> — a staff member proves that the WhatsApp
 * number the assistant is talking to is THEIR account (24 Sep 2026).
 *
 * The assistant sends this link into the WhatsApp chat when a staff or
 * office number asks for something sensitive (see includes/aiverify.php).
 * Opening it requires the normal staff sign-in (admin_boot redirects to
 * the login page and comes back here with the token intact), and the
 * signed-in account must be the one whose phone the number maps to. On
 * success the number is "fresh" for wa_ops_stepup_minutes; a mismatch
 * burns the link and is audited. No password ever travels on WhatsApp.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aiverify.php';
$admin = admin_boot();

$token  = (string) ($_GET['t'] ?? '');
$result = $token === ''
    ? ['ok' => false, 'error' => 'Open the exact link the assistant sent you on WhatsApp.']
    : AiVerify::consume($token, $admin);

admin_header('WhatsApp verification', 'wa-verify');
?>
<style>
  .wv{max-width:560px;margin:24px auto;padding:22px 24px;border-radius:16px;background:var(--card);border:1px solid var(--line)}
  .wv h2{margin:0 0 10px}
  .wv p{line-height:1.55;margin:8px 0}
  .wv .ok{color:var(--ok);font-weight:700} .wv .bad{color:var(--bad);font-weight:700}
</style>
<div class="wv">
  <?php if ($result['ok']): ?>
    <h2 class="ok">✅ Verified</h2>
    <p>Your WhatsApp number ending in <b><?= Security::e(substr((string) $result['phone'], -4)) ?></b> is now verified for
      <b><?= (int) $result['minutes'] ?> minutes</b>. Go back to WhatsApp and ask the assistant again.</p>
    <p>Signed in as <b><?= Security::e((string) ($admin['full_name'] ?? $admin['username'] ?? '')) ?></b>
      (<?= Security::e((string) ($admin['role'] ?? '')) ?>).</p>
  <?php else: ?>
    <h2 class="bad">Not verified</h2>
    <p><?= Security::e((string) ($result['error'] ?? 'That link could not be used.')) ?></p>
    <p>Ask the assistant on WhatsApp for a new verification link. Each link works once and expires after ten minutes.
      The assistant will never ask for your password — if anything on WhatsApp does, do not answer it.</p>
  <?php endif; ?>
  <p><a class="btn ghost" href="/admin/index.php">Back to the dashboard</a></p>
</div>
<?php admin_footer(); ?>
