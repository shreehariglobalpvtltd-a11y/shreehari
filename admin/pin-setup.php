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

if ($st['pin'] || $st['pattern']) {
    $_SESSION[ADMIN_SESSION_KEY]['qk_ok'] = true;
    Response::redirect(ltrim(Auth::homeUrl(), '/'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::requireCsrf();
    try {
        if (!$fresh) {
            throw new RuntimeException('सुरक्षाका लागि फेरि लगइन गर्नुहोस् · For safety, please sign in again first.');
        }
        if (!Security::rateLimit('quick_unlock_set', (string) $id, 10, 900, 900)) {
            throw new RuntimeException('धेरै पटक प्रयास भयो — १५ मिनेटपछि · Too many tries, wait 15 minutes.');
        }
        $kind = (string) ($_POST['qk_kind'] ?? '');
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
        Response::redirect(ltrim(Auth::homeUrl(), '/'));
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
</style>
<div class="ps-wrap">
  <div class="ps-card">
    <h2>🔐 PIN वा Pattern अनिवार्य · A PIN or pattern is required</h2>
    <p class="muted">अबदेखि लगइन: <b>कोड → PIN / pattern</b>। एक पटक मात्र राख्नुहोस् · From now on you sign in with your code and a PIN or pattern. Set it once.</p>
    <p class="ps-code">🪪 <?= Security::e($code) ?></p>
    <?php if ($flash !== null): ?><p class="pill" style="display:block;padding:10px 12px;background:#ffdede;color:#8a1f1f"><?= Security::e($flash) ?></p><?php endif; ?>
    <?php if (!$fresh): ?>
      <p class="pill" style="display:block;padding:10px 12px;background:#fff4d6;color:#7a5200">सुरक्षाका लागि फेरि लगइन गरेर तुरुन्त राख्नुहोस् · For safety, sign in again and set it right away.</p>
      <a class="btn" href="/admin/logout.php">फेरि लगइन · Sign in again</a>
    <?php endif; ?>
  </div>
  <?php if ($fresh): ?>
  <div class="ps-card">
    <h2>🔢 ६ अङ्कको PIN · 6-digit PIN</h2>
    <form method="post" autocomplete="off">
      <?= Security::csrfField() ?><input type="hidden" name="qk_kind" value="pin">
      <label>नयाँ PIN · New PIN<input type="password" name="pin" inputmode="numeric" pattern="\d{6}" minlength="6" maxlength="6" required autofocus></label>
      <label>फेरि उही PIN · Same PIN again<input type="password" name="pin2" inputmode="numeric" pattern="\d{6}" minlength="6" maxlength="6" required></label>
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
</div>
<script src="/assets/js/quick-unlock.js?v=20261101a"></script>
<?php
admin_footer();
