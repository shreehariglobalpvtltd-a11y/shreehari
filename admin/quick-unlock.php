<?php
/**
 * admin/quick-unlock.php — a staff member sets their own PIN and / or pattern
 * (1 Nov 2026, includes/quickunlock.php).
 *
 * Owner: "agent, staff portal ma Android / iPhone jastai pattern ra PIN le
 * login hune." Any role, like change-password.php: no permission argument.
 * Every change asks for the account's CURRENT password, so an unattended
 * signed-in browser cannot plant someone else's PIN. The password door itself
 * is never touched here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/quickunlock.php';

$admin = admin_boot();
$id    = (int) $admin['id'];
$flash = null;
// 15 Nov 2026: someone who signed in within 15 minutes (code + PIN / WhatsApp / password) is not asked for a password they may never have used
$fresh = (time() - (int) ($_SESSION[ADMIN_SESSION_KEY]['logged_in_at'] ?? 0)) <= 900;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (!QuickUnlock::ready()) {
            throw new RuntimeException('PIN / pattern अझै खुलेको छैन · PIN / pattern is not switched on yet.');
        }
        if (!Security::rateLimit('quick_unlock_set', (string) $id, 10, 900, 900)) {
            throw new RuntimeException('धेरै पटक प्रयास भयो — १५ मिनेटपछि · Too many tries, wait 15 minutes.');
        }
        $hash = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $id], '');
        if (!$fresh && ($hash === '' || !Security::verifyPassword((string) ($_POST['current_password'] ?? ''), $hash))) {
            throw new RuntimeException('हालको पासवर्ड मिलेन · Your current password did not match.');
        }
        // 15 Nov 2026 (code-first login): new PINs are 6 digits, and the last way in cannot be removed
        require_once INCLUDE_PATH . '/codelogin.php';
        $stNow = QuickUnlock::status($id);
        if (CodeLogin::required() && (($act === 'remove_pin' && !$stNow['pattern']) || ($act === 'remove_pattern' && !$stNow['pin']))) {
            throw new RuntimeException('PIN वा pattern मध्ये एउटा अनिवार्य छ — पहिले अर्को राख्नुहोस् · One of PIN or pattern is required: set the other one first.');
        }
        if ($act === 'set_pin' && CodeLogin::on() && preg_match('/^(\d{4}|\d{6})$/', trim((string) ($_POST['pin'] ?? ''))) !== 1) {
            throw new RuntimeException('PIN ४ वा ६ अङ्कको हुनुपर्छ · The PIN must be 4 or 6 digits.');
        }
        switch ($act) {
            case 'set_pin':
                $pin = trim((string) ($_POST['pin'] ?? ''));
                if ($pin !== trim((string) ($_POST['pin2'] ?? ''))) {
                    throw new RuntimeException('दुई PIN मिलेनन् · The two PINs do not match.');
                }
                QuickUnlock::set($id, 'pin', $pin);
                $flash = ['ok', '✅ PIN राखियो — अब लगइनमा PIN ले भित्र आउन सकिन्छ · PIN saved.'];
                break;
            case 'set_pattern':
                QuickUnlock::set($id, 'pattern', (string) ($_POST['qk_val'] ?? ''));
                $flash = ['ok', '✅ Pattern राखियो — अब लगइनमा pattern कोरेर भित्र आउन सकिन्छ · Pattern saved.'];
                break;
            case 'set_secq':
                $qk = (string) ($_POST['sec_q'] ?? '');
                // 19 Nov 2026 (u7/i3): a NEW answer only from the offered questions (no date of birth); saved answers keep working
                $q  = $qk === 'own' ? trim(Security::clean((string) ($_POST['sec_own'] ?? ''), 150)) : (QuickUnlock::SEC_QUESTIONS_OFFERED[$qk] ?? '');
                QuickUnlock::secSet($id, $q, (string) ($_POST['sec_a'] ?? ''));
                $flash = ['ok', '✅ सुरक्षा प्रश्न राखियो · Security question saved.'];
                break;
            case 'remove_pin':
                QuickUnlock::remove($id, 'pin');
                $flash = ['ok', 'PIN हटाइयो · PIN removed.'];
                break;
            case 'remove_pattern':
                QuickUnlock::remove($id, 'pattern');
                $flash = ['ok', 'Pattern हटाइयो · Pattern removed.'];
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (RuntimeException $e) {
        $flash = ['bad', $e->getMessage()];
    }
}

$st = QuickUnlock::status($id);
admin_header('PIN / Pattern', '');
$pwField = $fresh ? '' : '<label>हालको पासवर्ड · Current password<input type="password" name="current_password" autocomplete="current-password" required></label>';
?>
<style>
.qk-wrap{max-width:560px;margin:0 auto;display:grid;gap:16px}
.qk-card{background:var(--card,#fff);border:1px solid var(--line,#dfe5ef);border-radius:16px;padding:18px}
.qk-card h2{margin:0 0 4px;font-size:18px}
.qk-card label{display:block;font-weight:600;margin:10px 0 0}
.qk-card input[type=password]{width:100%;min-height:44px;margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid #c9d3e4;font-size:16px}
.qk-card .btn{min-height:44px;margin-top:12px}
.qk-state{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700}
.qk-state.on{background:#d9f5e3;color:#0a6b33}.qk-state.off{background:#eef1f6;color:#5b6577}
.qk-card label[hidden]{display:none}
.qk-card select,.qk-card input[type=text],.qk-card input[type=date]{width:100%;min-height:44px;margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid #c9d3e4;font-size:16px;background:var(--well,#fff);color:inherit}
</style>
<div class="qk-wrap">
  <?php if ($flash !== null): ?>
    <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? '#d9f5e3' : '#ffdede' ?>;color:<?= $flash[0] === 'ok' ? '#0a6b33' : '#8a1f1f' ?>"><?= Security::e($flash[1]) ?></p>
  <?php endif; ?>
  <?php if (!QuickUnlock::on()): ?>
    <p class="pill" style="display:block;padding:10px 12px;background:#fff4d6;color:#7a5200">PIN / pattern अहिले बन्द छ (quick_unlock_on) · PIN / pattern sign-in is switched off.</p>
  <?php endif; ?>
  <p class="muted">PIN वा pattern ले लगइन छिटो हुन्छ — पासवर्ड, ID र "पासवर्ड बिर्सनुभयो?" उस्तै रहन्छन् · A PIN or pattern is a quick way in; your password still works.</p>

  <div class="qk-card">
    <h2>◯ Pattern <span class="qk-state <?= $st['pattern'] ? 'on' : 'off' ?>"><?= $st['pattern'] ? 'राखिएको · set' : 'छैन · not set' ?></span></h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="set_pattern"><input type="hidden" name="qk_val" value="">
      <?= $pwField ?>
      <p class="note">पासवर्ड लेखेर, कम्तीमा ४ थोप्ला जोड्दै pattern कोर्नुहोस्, अनि पक्का गर्न फेरि उही कोर्नुहोस् · Type your password, draw a pattern (4+ dots), then draw it again.</p>
      <div class="qk-pad" data-qk-pad data-qk-mode="set" aria-label="Pattern"></div>
      <p data-qk-msg></p>
    </form>
    <?php if ($st['pattern']): ?>
    <form method="post" autocomplete="off"><?= Security::csrfField() ?><input type="hidden" name="action" value="remove_pattern">
      <details><summary>Pattern हटाउने · Remove pattern</summary><?= $pwField ?><button class="btn bad" type="submit">हटाउनुहोस् · Remove</button></details>
    </form>
    <?php endif; ?>
  </div>

  <div class="qk-card">
    <h2>🔢 PIN <span class="qk-state <?= $st['pin'] ? 'on' : 'off' ?>"><?= $st['pin'] ? 'राखिएको · set' : 'छैन · not set' ?></span></h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="set_pin">
      <?= $pwField ?>
      <label>नयाँ PIN (४–६ अङ्क) · New PIN (4-6 digits)<input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required></label>
      <label>फेरि उही PIN · Same PIN again<input type="password" name="pin2" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required></label>
      <button class="btn ok" type="submit">PIN राख्नुहोस् · Save PIN</button>
    </form>
    <?php if ($st['pin']): ?>
    <form method="post" autocomplete="off"><?= Security::csrfField() ?><input type="hidden" name="action" value="remove_pin">
      <details><summary>PIN हटाउने · Remove PIN</summary><?= $pwField ?><button class="btn bad" type="submit">हटाउनुहोस् · Remove</button></details>
    </form>
    <?php endif; ?>
  </div>
<?php if (QuickUnlock::secReady()): $hasQ = QuickUnlock::secQuestion($id) !== ''; ?>
  <div class="qk-card">
    <h2>🛡️ सुरक्षा प्रश्न · Security question <span class="qk-state <?= $hasQ ? 'on' : 'off' ?>"><?= $hasQ ? 'राखिएको · set' : 'छैन · not set' ?></span></h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="set_secq">
      <?= $pwField ?>
      <label>प्रश्न · Question
        <select name="sec_q" onchange="document.getElementById('quOwn').hidden=this.value!=='own'">
          <?php foreach (QuickUnlock::SEC_QUESTIONS_OFFERED as $k => $label): ?><option value="<?= Security::e($k) ?>"><?= Security::e($label) ?></option><?php endforeach; ?>
          <option value="own">आफ्नै प्रश्न लेख्ने · Write my own</option>
        </select></label>
      <label id="quOwn" hidden>तपाईंको प्रश्न · Your question<input type="text" name="sec_own" maxlength="150"></label>
      <label>उत्तर · Answer<input id="quA" type="text" name="sec_a" maxlength="80" required></label>
      <button class="btn ok" type="submit"><?= $hasQ ? 'बदल्नुहोस् · Change' : 'राख्नुहोस् · Save' ?></button>
    </form>
  </div>
<?php endif; ?>
</div>
<script src="/assets/js/quick-unlock.js?v=20261101a"></script>
<?php
admin_footer();
