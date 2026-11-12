<?php
/**
 * admin/setup.php — first-open setup, like a new phone (12 Nov 2026).
 *
 * Owner: "app open hune bittikai setup boot jasto hos — pattern ya PIN set
 * garne, ani security key, DOB jasto secure question; harek agent, admin,
 * staff ko ID ma."
 *
 * admin/home.php sends an account here once per sign-in while it has no
 * PIN / pattern or no security question. One screen: a PIN, a question and
 * its answer. "पछि · Later" skips it for this sign-in. A pattern is still
 * drawn on admin/quick-unlock.php (linked).
 *
 * Who may save: someone who signed in within the last 15 minutes (they have
 * just proved who they are), else the current password is asked — the same
 * rule quick-unlock.php keeps, so an unattended open browser cannot plant a
 * PIN or a question.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/quickunlock.php';

$admin = admin_boot();
$id    = (int) $admin['id'];
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$flash = null;
$fresh = (time() - (int) ($_SESSION[ADMIN_SESSION_KEY]['logged_in_at'] ?? 0)) <= 900;
$st    = QuickUnlock::status($id);
$hasQk = $st['pin'] || $st['pattern'];
$secOn = QuickUnlock::secReady();
$hasQ  = $secOn && QuickUnlock::secQuestion($id) !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();
    if ((string) ($_POST['action'] ?? '') === 'later') {
        $_SESSION['setup_later'] = 1;
        Response::redirect('admin/home.php');
    }
    try {
        if (!QuickUnlock::on()) {
            throw new RuntimeException('PIN / pattern अहिले बन्द छ · PIN / pattern is switched off.');
        }
        if (!Security::rateLimit('quick_unlock_set', (string) $id, 10, 900, 900)) {
            throw new RuntimeException('धेरै पटक प्रयास भयो — १५ मिनेटपछि · Too many tries, wait 15 minutes.');
        }
        if (!$fresh) {
            $hash = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $id], '');
            if ($hash === '' || !Security::verifyPassword((string) ($_POST['current_password'] ?? ''), $hash)) {
                throw new RuntimeException('हालको पासवर्ड मिलेन · Your current password did not match.');
            }
        }
        $pin = trim((string) ($_POST['pin'] ?? ''));
        $ans = (string) ($_POST['sec_a'] ?? '');
        $qk  = (string) ($_POST['sec_q'] ?? '');
        $q   = $qk === 'own' ? trim(Security::clean((string) ($_POST['sec_own'] ?? ''), 150)) : (QuickUnlock::SEC_QUESTIONS[$qk] ?? '');
        $did = [];
        // check everything first, save after — never half a setup
        if ($pin !== '' || !$hasQk) {
            if ($pin !== trim((string) ($_POST['pin2'] ?? ''))) {
                throw new RuntimeException('दुई PIN मिलेनन् · The two PINs do not match.');
            }
            if (($why = QuickUnlock::pinProblem($pin)) !== null) {
                throw new RuntimeException($why);
            }
        }
        if ($secOn && (trim($ans) !== '' || !$hasQ)) {
            if (($why = QuickUnlock::secProblem($q, $ans)) !== null) {
                throw new RuntimeException($why);
            }
        }
        if ($pin !== '') {
            QuickUnlock::set($id, 'pin', $pin);
            $did[] = 'PIN';
        }
        if ($secOn && trim($ans) !== '') {
            QuickUnlock::secSet($id, $q, $ans);
            $did[] = 'सुरक्षा प्रश्न · security question';
        }
        QuickUnlock::rememberDevice($id);
        $_SESSION['setup_later'] = 1;
        $_SESSION['home_flash']  = '✅ तयार भयो · Ready: ' . implode(' + ', $did) . '. अब यो फोनमा PIN ले खुल्छ।';
        Response::redirect('admin/home.php');
    } catch (RuntimeException $ex) {
        $flash = $ex->getMessage();
    }
}

admin_header('Setup · सुरुको सेटअप', '');
?>
<style>
.su{max-width:460px;margin:0 auto;display:grid;gap:14px}
.su-hero{border-radius:18px;padding:20px;background:linear-gradient(150deg,#0B1220,#12264E);color:#fff;text-align:center}
.su-hero h2{margin:6px 0 4px;font-size:21px}.su-hero p{margin:0;opacity:.8;font-size:13.5px;line-height:1.5}
.su-card{background:var(--card,#fff);border:1px solid var(--line,#dfe5ef);border-radius:16px;padding:16px}
.su-card h3{margin:0 0 2px;font-size:16px;display:flex;gap:8px;align-items:center}
.su-card h3 i{font-style:normal;display:inline-grid;place-items:center;width:26px;height:26px;border-radius:50%;background:#F07C1F;color:#fff;font-size:13px;font-weight:800}
.su-card h3 .ok{margin-left:auto;font-size:12px;font-weight:800;color:#0a6b33;background:#d9f5e3;border-radius:99px;padding:2px 10px}
.su-card label{display:block;font-weight:600;font-size:13.5px;margin:10px 0 0}.su-card label[hidden]{display:none}
.su-card input,.su-card select{width:100%;min-height:46px;margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid #c9d3e4;font:inherit;font-size:16px;background:var(--card,#fff);color:inherit}
.su-card small{display:block;margin-top:6px;opacity:.7;font-size:12.5px;line-height:1.45}
.su-go{min-height:52px;border:0;border-radius:14px;background:#0054A8;color:#fff;font:inherit;font-size:16px;font-weight:800;cursor:pointer;width:100%}
.su-later{background:none;border:0;color:inherit;opacity:.7;font:inherit;text-decoration:underline;cursor:pointer;min-height:40px;width:100%}
.su-bad{padding:10px 12px;border-radius:10px;background:#ffdede;color:#8a1f1f;font-weight:600}
</style>
<div class="su">
  <div class="su-hero"><div style="font-size:34px">🔐</div>
    <h2>सुरुको सेटअप · Set up your app</h2>
    <p>एक पटक मात्र। त्यसपछि यो फोनमा PIN ले २ सेकेन्डमा खुल्छ। · Only once — after this the app opens with your PIN.</p></div>
  <?php if ($flash !== null): ?><div class="su-bad" role="alert"><?= $e($flash) ?></div><?php endif; ?>
  <?php if (!QuickUnlock::on()): ?><div class="su-bad">PIN / pattern अहिले बन्द छ · switched off (quick_unlock_on).</div><?php endif; ?>

  <form method="post" autocomplete="off" class="su" style="margin:0">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="save">
    <div class="su-card">
      <h3><i>1</i> PIN<?= $hasQk ? '<span class="ok">' . ($st['pin'] ? 'PIN छ' : 'Pattern छ') . ' ✓</span>' : '' ?></h3>
      <label><?= $hasQk ? 'नयाँ PIN (बदल्ने भए मात्र) · New PIN, only to change it' : 'PIN (४–६ अङ्क) · 4 to 6 digits' ?>
        <input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6"<?= $hasQk ? '' : ' required autofocus' ?>></label>
      <label>फेरि उही PIN · Same PIN again
        <input type="password" name="pin2" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6"<?= $hasQk ? '' : ' required' ?>></label>
      <small>1234, 0000 जस्ता सजिला नराख्नुहोस्। Pattern कोर्न मन छ? <a href="/admin/quick-unlock.php">◯ Pattern राख्ने</a></small>
    </div>

    <?php if ($secOn): ?>
    <div class="su-card">
      <h3><i>2</i> सुरक्षा प्रश्न · Security question<?= $hasQ ? '<span class="ok">छ ✓</span>' : '' ?></h3>
      <label>प्रश्न · Question
        <select name="sec_q" id="suQ">
          <?php foreach (QuickUnlock::SEC_QUESTIONS as $k => $label): ?><option value="<?= $e($k) ?>"><?= $e($label) ?></option><?php endforeach; ?>
          <option value="own">आफ्नै प्रश्न लेख्ने · Write my own</option>
        </select></label>
      <label id="suOwnL" hidden>तपाईंको प्रश्न · Your question<input name="sec_own" maxlength="150"></label>
      <label>उत्तर · Answer<input name="sec_a" id="suA" type="date" maxlength="80"<?= $hasQ ? '' : ' required' ?>></label>
      <small>PIN बिर्सिँदा यही उत्तरले नयाँ PIN राख्न मिल्छ — यही फोनमा मात्र। उत्तर कसैलाई नभन्नुहोस्। · If you forget the PIN, this answer lets you set a new one, on this phone only.<?= $hasQ ? ' खाली छोडे पुरानै रहन्छ।' : '' ?></small>
    </div>
    <?php endif; ?>

    <?php if (!$fresh): ?>
    <div class="su-card">
      <h3><i>3</i> पक्का गर्न · Confirm it is you</h3>
      <label>हालको पासवर्ड · Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
    </div>
    <?php endif; ?>
    <button class="su-go" type="submit">✅ सेभ गरेर सुरु गर्ने · Save and start</button>
  </form>
  <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="later">
    <button class="su-later" type="submit">पछि गर्छु · Later</button></form>
</div>
<script>
(function(){
  var q = document.getElementById('suQ'), a = document.getElementById('suA'), o = document.getElementById('suOwnL');
  if (!q || !a) return;
  function sync(){ a.type = q.value === 'dob' ? 'date' : 'text'; if (o) o.hidden = q.value !== 'own'; }
  q.addEventListener('change', function(){ a.value = ''; sync(); }); sync();
})();
</script>
<?php admin_footer();
