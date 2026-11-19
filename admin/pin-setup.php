<?php
/**
 * admin/pin-setup.php — compulsory PIN / pattern before any page (15 Nov 2026).
 *
 * Auth::requireAdmin() sends every signed-in account that has neither a PIN nor a
 * pattern here (setting login_pin_required, default ON with the code-first door).
 * Someone who just signed in (within 15 minutes) proved who they are through the
 * door they used; anyone else is sent back through sign-in, so an unattended
 * open browser cannot plant a PIN. Both values are typed / drawn twice; only the
 * bcrypt hash is stored (QuickUnlock::set). Nothing goes to browser storage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/codelogin.php';

$admin = admin_boot();
$id    = (int) $admin['id'];
$fresh = (time() - (int) ($_SESSION[ADMIN_SESSION_KEY]['logged_in_at'] ?? 0)) <= 900;
$st    = QuickUnlock::status($id);
$flash = null;

$secOn  = QuickUnlock::secReady();
$hasQ   = $secOn && QuickUnlock::secQuestion($id) !== '';
$secAsk = ($st['pin'] || $st['pattern']) && $secOn && !$hasQ && (string) ($_GET['secq'] ?? ($_POST['secq'] ?? '')) === '1';
if (($st['pin'] || $st['pattern']) && !$secAsk) {
    $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;
    Response::redirect(ltrim(Auth::homeUrl(), '/'));
}
/* 19 Nov 2026 (u7, LG-1): with the mobile-first door the PIN / pattern is OFFERED, not forced
   (CodeLogin::required() is false): ?offer=1 shows "Skip for now". */
$offer = !CodeLogin::required();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['qk_kind'] ?? '') === 'skip') {
    Security::requireCsrf();
    if (CodeLogin::required()) {
        $flash = 'PIN / pattern अनिवार्य छ · A PIN or pattern is required.';
    } else {
        $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;
        Logger::audit('admin.login.setup_skipped', 'admin', (string) $id, null, null, 'PIN / pattern offer skipped (mobile-first door)');
        Response::redirect(ltrim(Auth::homeUrl(), '/'));
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();
    try {
        if (!$fresh) {
            throw new RuntimeException('सुरक्षाका लागि फेरि लगइन गर्नुहोस् · For safety, please sign in again first.');
        }
        if (!Security::rateLimit('quick_unlock_set', (string) $id, 10, 900, 900)) {
            throw new RuntimeException('धेरै पटक प्रयास भयो — १५ मिनेटपछि · Too many tries, wait 15 minutes.');
        }
        $kind = (string) ($_POST['qk_kind'] ?? '');
        if ($kind === 'secq') {
            // 15 Nov 2026 (owner): security question right after the PIN / pattern, optional
            $qk = (string) ($_POST['sec_q'] ?? '');
            // 19 Nov 2026 (u7): only the offered questions for a NEW answer (no date of birth); existing answers keep working
            $q  = $qk === 'own' ? trim(Security::clean((string) ($_POST['sec_own'] ?? ''), 150)) : (QuickUnlock::SEC_QUESTIONS_OFFERED[$qk] ?? '');
            QuickUnlock::secSet($id, $q, (string) ($_POST['sec_a'] ?? ''));
            $_SESSION['home_flash'] = '✅ सुरक्षा प्रश्न राखियो · Security question saved.';
            Response::redirect(ltrim(Auth::homeUrl(), '/'));
        }
        if ($kind === 'pin') {
            $pin = trim((string) ($_POST['pin'] ?? ''));
            if (($why = CodeLogin::newProblem('pin', $pin)) !== null) {
                throw new RuntimeException($why);
            }
            if (!hash_equals($pin, trim((string) ($_POST['pin2'] ?? '')))) {
                throw new RuntimeException('दुई PIN मिलेनन् · The two PINs do not match.');
            }
            QuickUnlock::set($id, 'pin', $pin);
        } elseif ($kind === 'pattern') {
            $p = (string) ($_POST['qk_val'] ?? '');   // quick-unlock.js "set" mode: drawn twice, sent once both match
            if (($why = CodeLogin::newProblem('pattern', $p)) !== null) {
                throw new RuntimeException($why);
            }
            QuickUnlock::set($id, 'pattern', $p);
        } else {
            throw new RuntimeException('PIN वा pattern छान्नुहोस् · Choose a PIN or a pattern.');
        }
        Logger::audit('admin.login.setup_done', 'admin', (string) $id, null, ['kind' => $kind, 'how' => 'signed_in_gate'], 'Compulsory PIN / pattern set after sign-in');
        $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;
        QuickUnlock::rememberDevice($id);
        $_SESSION['home_flash'] = '✅ PIN / pattern राखियो — अबदेखि कोड र PIN / pattern ले भित्र आउनुहोस् · Saved. From now on sign in with your code and PIN / pattern.';
        Response::redirect($secOn && !$hasQ ? 'admin/pin-setup.php?secq=1' : ltrim(Auth::homeUrl(), '/'));
    } catch (RuntimeException $e) {
        $flash = $e->getMessage();
    }
}

$code = CodeLogin::codeFor($id);
admin_header('PIN / Pattern', '');
?>
<style>
.ps-wrap{max-width:460px;margin:0 auto;display:grid;gap:14px}
.ps-card{background:var(--card,#fff);border:1px solid var(--line,#dfe5ef);border-radius:16px;padding:18px}
.ps-card h2{margin:0 0 6px;font-size:18px}
.ps-card label{display:block;font-weight:600;margin:10px 0 0}
.ps-card input[type=password]{width:100%;min-height:48px;margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid #c9d3e4;font-size:20px;letter-spacing:.4em;text-align:center}
.ps-card .btn{min-height:48px;margin-top:12px;width:100%}
.ps-code{font-weight:800;font-size:18px;text-align:center}
.ps-card label[hidden]{display:none}
.ps-card select,.ps-card input[type=text],.ps-card input[type=date]{width:100%;min-height:44px;margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid #c9d3e4;font-size:16px;background:var(--well,#fff);color:inherit}
</style>
<div class="ps-wrap">
  <div class="ps-card">
    <h2><?= $secAsk ? '✅ PIN / pattern राखियो · PIN / pattern saved'
        : ($offer ? '🔐 PIN वा Pattern (ऐच्छिक) · A PIN or pattern (optional)' : '🔐 PIN वा Pattern अनिवार्य · A PIN or pattern is required') ?></h2>
    <?php if ($offer && !$secAsk): ?>
    <p class="muted">यो फोनमा छिटो खोल्न PIN वा pattern राख्न सक्नुहुन्छ — नराखे पनि पासवर्डले भित्र आउन मिल्छ · Set a PIN or pattern to open faster on this phone, or skip: your password still works.</p>
    <?php else: ?>
    <p class="muted">अबदेखि लगइन: <b>कोड → PIN / pattern</b>। एक पटक मात्र राख्नुहोस् · From now on you sign in with your code and a PIN or pattern. Set it once.</p>
    <?php endif; ?>
    <p class="ps-code">🪪 <?= Security::e($code) ?></p>
    <?php if ($flash !== null): ?><p class="pill" style="display:block;padding:10px 12px;background:#ffdede;color:#8a1f1f"><?= Security::e($flash) ?></p><?php endif; ?>
    <?php if (!$fresh): ?>
      <p class="pill" style="display:block;padding:10px 12px;background:#fff4d6;color:#7a5200">सुरक्षाका लागि फेरि लगइन गरेर तुरुन्त राख्नुहोस् · For safety, sign in again and set it right away.</p>
      <a class="btn" href="/admin/logout.php">फेरि लगइन · Sign in again</a>
    <?php endif; ?>
  </div>
  <?php if ($secAsk): ?>
  <div class="ps-card">
    <h2>🛡️ सुरक्षा प्रश्न · Security question</h2>
    <p class="note">PIN / pattern बिर्सिँदा: code + मोबाइल + यही उत्तर = नयाँ PIN। सजिलो तर अरूले थाहा नपाउने उत्तर राख्नुहोस् · If you forget your PIN: code + mobile + this answer gives a new one. Pick an answer others do not know.</p>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="qk_kind" value="secq"><input type="hidden" name="secq" value="1">
      <label>प्रश्न · Question
        <select name="sec_q" onchange="document.getElementById('psOwn').hidden=this.value!=='own'">
          <?php foreach (QuickUnlock::SEC_QUESTIONS_OFFERED as $k => $label): ?><option value="<?= Security::e($k) ?>"><?= Security::e($label) ?></option><?php endforeach; ?>
          <option value="own">आफ्नै प्रश्न लेख्ने · Write my own</option>
        </select></label>
      <label id="psOwn" hidden>तपाईंको प्रश्न · Your question<input name="sec_own" maxlength="150"></label>
      <label>उत्तर · Answer<input id="psA" name="sec_a" type="text" maxlength="80" required></label>

      <button class="btn ok" type="submit">सेभ गर्नुहोस् · Save</button>
    </form>
  </div>
  <?php elseif ($fresh): ?>
  <div class="ps-card">
    <h2>🔢 ४ वा ६ अङ्कको PIN · 4 or 6-digit PIN</h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="qk_kind" value="pin">
      <label>नयाँ PIN · New PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{4}|\d{6}" minlength="4" maxlength="6" required autofocus></label>
      <label>फेरि उही PIN · Same PIN again<input type="password" name="pin2" inputmode="numeric" pattern="\d{4}|\d{6}" minlength="4" maxlength="6" required></label>
      <button class="btn ok" type="submit">PIN राख्नुहोस् · Save PIN</button>
    </form>
  </div>
  <div class="ps-card">
    <h2>◯ वा Pattern · Or a pattern</h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="qk_kind" value="pattern"><input type="hidden" name="qk_val" value="">
      <p class="note">कम्तीमा ४ थोप्ला जोड्नुहोस्, अनि पक्का गर्न फेरि उही कोर्नुहोस् · Join 4+ dots, then draw it again to confirm.</p>
      <div class="qk-pad" data-qk-pad data-qk-mode="set" aria-label="Pattern"></div>
      <p data-qk-msg></p>
    </form>
  </div>
  <?php endif; ?>
  <?php if ($offer && !$secAsk): ?>
  <form method="post" class="ps-card" style="text-align:center"><?= Security::csrfField() ?><input type="hidden" name="qk_kind" value="skip">
    <button class="btn" type="submit" style="background:transparent;color:inherit;border:1px solid var(--line,#dfe5ef)">अहिलेलाई छोड्नुहोस् · Skip for now →</button></form>
  <?php endif; ?>
</div>
<script src="/assets/js/quick-unlock.js?v=20261120c"></script>
<?php
if (is_file(INCLUDE_PATH . '/passkeys.php')) {
    require_once INCLUDE_PATH . '/passkeys.php';
    if (Passkeys::ready()) { ?>
<meta name="csrf-token" content="<?= Security::e(Security::csrfToken()) ?>">
<div class="ps-card" style="text-align:center;margin-top:14px">
  <h3 style="margin:0 0 6px">👆 फिंगरप्रिन्ट / Face ID</h3>
  <p class="muted" style="margin:0 0 10px">यो फोनको आफ्नै lock (औंठाछाप वा अनुहार) ले खोल्न मिल्छ — PIN भन्दा छिटो। औंठाछाप फोनमै रहन्छ, हामीकहाँ आउँदैन। · Open with this phone's own fingerprint or face.</p>
  <div id="pkMsg" class="msg ok" data-cls="msg" hidden></div>
  <input type="hidden" data-pk-label value="<?= Security::e(mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'phone'), 0, 40)) ?>">
  <button class="btn btn-orange" type="button" data-pk="add">👆 यो फोन थप्नुहोस् · Add this phone</button>
  <p class="muted" id="pkNo" hidden style="margin-top:8px">यो फोन/ब्राउजरमा फिंगरप्रिन्ट छैन — PIN / pattern प्रयोग गर्नुहोस्।</p>
</div>
<script src="/assets/js/team-passkey.js?v=20261120d" data-next="<?= Security::e(Auth::homeUrl()) ?>"></script>
<?php }
}
admin_footer();
