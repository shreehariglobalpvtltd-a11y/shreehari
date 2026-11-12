<?php
/**
 * admin/recover.php — forgot the PIN / pattern (12 Nov 2026).
 *
 * Works ONLY on a phone that already remembers the account (the signed
 * device cookie QuickUnlock sets after a real sign-in) and only for an
 * account that saved a security question (admin/setup.php). The right
 * answer lets the person set a NEW PIN — it does not sign anyone in; they
 * then unlock with that PIN on the lock screen. Five wrong answers = 15
 * minutes closed. The password door and "forgot password" stay as they are.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickunlock.php';

$e    = static fn($v): string => Security::e((string) ($v ?? ''));
$acc  = QuickUnlock::rememberedAccount();
$q    = ($acc !== null && QuickUnlock::secReady()) ? QuickUnlock::secQuestion((int) $acc['id']) : '';
$msg  = null;
$done = false;

if ($acc !== null && $q !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · Page was open too long, try again.');
        }
        $id = (int) $acc['id'];
        if (!Security::rateLimit('sec_answer', (string) $id, 5, 900, 900)
            || !Security::rateLimit('sec_answer_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 15, 900, 900)) {
            throw new RuntimeException('धेरै पटक गलत भयो — १५ मिनेटपछि, वा पासवर्डले भित्र जानुहोस् · Too many tries. Wait 15 minutes, or sign in with your password.');
        }
        $pin = trim((string) ($_POST['pin'] ?? ''));
        if ($pin !== trim((string) ($_POST['pin2'] ?? ''))) {
            throw new RuntimeException('दुई PIN मिलेनन् · The two PINs do not match.');
        }
        if (($why = QuickUnlock::pinProblem($pin)) !== null) {
            throw new RuntimeException($why);
        }
        if (!QuickUnlock::secCheck($id, (string) ($_POST['sec_a'] ?? ''))) {
            Logger::audit('admin.unlock.recover_fail', 'admin', (string) $id, null, null, 'Forgot PIN: wrong security answer');
            throw new RuntimeException('उत्तर मिलेन · That answer did not match.');
        }
        QuickUnlock::set($id, 'pin', $pin);
        Logger::audit('admin.unlock.recover', 'admin', (string) $id, null, null, 'Forgot PIN: new PIN set after the security answer');
        $done = true;
    } catch (RuntimeException $ex) {
        $msg = $ex->getMessage();
    }
}
$isDob = $q !== '' && $q === (QuickUnlock::SEC_QUESTIONS['dob'] ?? '');
?><!doctype html>
<html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex"><meta name="theme-color" content="#0B1220">
<title>PIN बिर्सनुभयो? · Forgot PIN</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0B1220;color:#fff;font-family:system-ui,-apple-system,"Segoe UI","Noto Sans Devanagari",sans-serif}
.rc{width:min(92vw,400px);display:grid;gap:12px;padding:20px 0}
h1{font-size:21px;margin:0;text-align:center}p{margin:0;opacity:.8;font-size:14px;line-height:1.5;text-align:center}
label{display:block;font-weight:600;font-size:13.5px}
input{width:100%;box-sizing:border-box;min-height:48px;margin-top:6px;padding:8px 12px;border-radius:12px;border:1px solid #2A3A5E;background:#111B30;color:#fff;font:inherit;font-size:16px}
button{min-height:52px;border:0;border-radius:14px;background:#F07C1F;color:#fff;font:inherit;font-size:16px;font-weight:800;cursor:pointer}
a{color:#9CC2FF}.bad{padding:10px 12px;border-radius:10px;background:#5a1616;color:#ffd7d7;font-weight:600;text-align:left}
.q{padding:12px;border-radius:12px;background:#111B30;border:1px solid #2A3A5E;font-weight:700}
</style></head><body><main class="rc">
  <div style="font-size:38px;text-align:center">🔑</div>
  <?php if ($done): ?>
    <h1>✅ नयाँ PIN राखियो · New PIN saved</h1>
    <p>अब त्यही PIN ले खोल्नुहोस्। · Now unlock with it.</p>
    <a href="/admin/login.php" style="text-align:center;font-weight:800;font-size:16px">🔓 खोल्ने · Open the app</a>
  <?php elseif ($acc === null || $q === ''): ?>
    <h1>PIN बिर्सनुभयो? · Forgot PIN</h1>
    <p><?= $acc === null
        ? 'यो फोनले तपाईंको खाता चिनेको छैन। पासवर्ड वा WhatsApp कोडले भित्र जानुहोस्। · This phone does not remember an account — sign in with your password or the WhatsApp code.'
        : 'यो खातामा सुरक्षा प्रश्न राखिएको छैन। पासवर्डले भित्र गएर सेटअप गर्नुहोस्। · No security question on this account yet — sign in with your password, then finish Setup.' ?></p>
    <a href="/admin/login.php?step=start&amp;other=1" style="text-align:center;font-weight:800">🔑 पासवर्डले भित्र जाने · Sign in with password</a>
  <?php else: ?>
    <h1><?= $e($acc['name']) ?></h1>
    <p>सुरक्षा प्रश्नको उत्तर दिएर नयाँ PIN राख्नुहोस्। · Answer your question, then choose a new PIN.</p>
    <?php if ($msg !== null): ?><div class="bad" role="alert"><?= $e($msg) ?></div><?php endif; ?>
    <form method="post" autocomplete="off" style="display:grid;gap:12px">
      <?= Security::csrfField() ?>
      <div class="q">❓ <?= $e($q) ?></div>
      <label>उत्तर · Answer<input name="sec_a" type="<?= $isDob ? 'date' : 'text' ?>" maxlength="80" required autofocus></label>
      <label>नयाँ PIN (४–६ अङ्क) · New PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required></label>
      <label>फेरि उही PIN · Same PIN again<input type="password" name="pin2" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required></label>
      <button type="submit">नयाँ PIN राख्ने · Save new PIN</button>
    </form>
    <a href="/admin/login.php" style="text-align:center">← फर्कने · Back</a>
  <?php endif; ?>
</main></body></html>
