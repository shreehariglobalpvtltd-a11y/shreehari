<?php
/**
 * admin/facebook.php — the assistant on our Facebook Page (1 Nov 2026).
 *
 * Owner: "Facebook bata pani WhatsApp jastai — AI le kura garos, ticket
 * katera pathaos, photo napaye link." includes/fbmessenger.php does it;
 * this page connects it:
 *
 *   - paste the Page access token and the Meta app secret (never shown back)
 *   - copy the webhook URL + verify token into the Meta app
 *   - test the token, subscribe the Page, set the "Get Started" button
 *   - switch it on, and separately allow selling (quote → "ho")
 *
 * Office only (dashboard.view); changes need the Settings permission, the
 * same as the AI Brain page. Writes are CSRF-guarded and audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/fbmessenger.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$e       = static fn($v): string => Security::e((string) $v);
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        if (!$canEdit) {
            throw new RuntimeException('Changing Facebook needs the Settings permission.');
        }
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'save') {
            /* Check every pasted value FIRST: a bad paste must change nothing
               (it used to save the switches and then refuse the token, which
               looked like "saved" with no token). */
            $writes = [];
            foreach (['fb_page_token' => 'token', 'fb_app_secret' => 'secret'] as $key => $field) {
                if (isset($_POST['clear_' . $field])) {
                    $writes[$key] = '';
                    continue;
                }
                $raw = (string) ($_POST[$field] ?? '');
                if (trim($raw) === '') {
                    continue;
                }
                $v = FbMessenger::cleanToken($raw);
                if ($v === '') {
                    throw new RuntimeException(($field === 'token'
                        ? 'The Page token was not found in what you pasted — copy only the long code that starts with EAA. / टोकन भेटिएन: EAA बाट सुरु हुने लामो कोड मात्र कपी गर्नुहोस्।'
                        : 'The app secret does not look right — copy it again.') . ' Nothing was saved.');
                }
                $writes[$key] = $v;
            }
            $on = isset($_POST['fb_msgr_on']);
            Settings::set('fb_msgr_on', $on, 'bool', 'facebook');
            Settings::set('fb_msgr_sell', isset($_POST['fb_msgr_sell']), 'bool', 'facebook');
            foreach ($writes as $key => $v) {
                Settings::set($key, $v, 'string', 'facebook');
            }
            Logger::audit('facebook.save', 'setting', 'fb_msgr', null,
                ['changed' => array_merge(['fb_msgr_on', 'fb_msgr_sell'], array_keys($writes))], 'by admin #' . $admin['id']);
            $tokenNow = FbMessenger::token() !== '';
            $flash = $on && !$tokenNow
                ? ['bad', 'Saved, but there is still NO Page token — paste it in the box above and Save again. / टोकन छैन — माथि पेस्ट गरेर फेरि Save गर्नुहोस्।']
                : ['ok', 'Saved. / सेभ भयो।' . (isset($writes['fb_page_token']) && $writes['fb_page_token'] !== '' ? ' Page token saved ✓ — now press “Test token”.' : '')];
        } elseif ($act === 'new_verify') {
            Settings::set('fb_verify_token', '', 'string', 'facebook');
            FbMessenger::verifyToken();
            Logger::audit('facebook.verify_token', 'setting', 'fb_verify_token', null, [], 'by admin #' . $admin['id']);
            $flash = ['ok', 'New verify token made — paste it into the Meta app again.'];
        } elseif (in_array($act, ['test', 'subscribe', 'profile'], true)) {
            $r = match ($act) {
                'test'      => FbMessenger::testToken(),
                'subscribe' => FbMessenger::subscribe(),
                default     => FbMessenger::setupProfile(),
            };
            Logger::audit('facebook.' . $act, 'setting', 'fb_msgr', null, ['ok' => $r['ok']], 'by admin #' . $admin['id']);
            $flash = [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✓ ' : '✗ ') . $r['detail']];
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

Settings::flush();
$on       = Settings::getBool('fb_msgr_on', false);
$sell     = Settings::getBool('fb_msgr_sell', false);
$hasToken = FbMessenger::token() !== '';
$hasSec   = FbMessenger::appSecret() !== '';
$pageName = Settings::getString('fb_page_name', '');
$verify   = FbMessenger::verifyToken();
$hook     = FbMessenger::webhookUrl();
$live     = FbMessenger::enabled();

admin_header('Facebook Messenger AI', 'facebook');
?>
<style>
.fb-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.55}
.fb-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin:0 0 16px;display:grid;gap:10px}
.fb-card h2{margin:0;font-size:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.fb-pill{font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px}
.fb-on{background:var(--ok-bg);color:var(--ok)} .fb-off{background:var(--soft);color:var(--mut)} .fb-warn{background:var(--warn-bg);color:var(--warn)}
.fb-card label{display:grid;gap:4px;font-size:12.5px;color:var(--mut)}
.fb-card input[type=text],.fb-card input[type=password]{width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px}
.fb-check{display:flex !important;grid-template-columns:none;gap:8px;align-items:center;font-size:14px !important;color:var(--ink) !important}
.fb-check input{width:auto}
.fb-copy{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.fb-copy code{padding:7px 10px;border:1px dashed var(--line);border-radius:8px;background:var(--soft);font-size:13px;word-break:break-all}
.fb-steps{margin:0;padding-left:20px;font-size:13.5px;line-height:1.7}
.fb-actions{display:flex;gap:10px;flex-wrap:wrap}
.fb-actions form{display:inline}
</style>

<p class="fb-note">
  The same assistant as WhatsApp and the website, answering messages to your <strong>Facebook Page</strong> in Messenger —
  Nepali, Hindi or English, like a person. It quotes, books (when you allow it), sends the ticket picture, or the link when the
  picture cannot go. A visitor proves their mobile with the same WhatsApp code the app uses before any booking is read or a ticket is issued.
</p>

<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<div class="fb-card">
  <h2>Status
    <?php if ($live): ?><span class="fb-pill fb-on">● Answering</span>
    <?php elseif ($on): ?><span class="fb-pill fb-warn">On, but the token or app secret is missing</span>
    <?php else: ?><span class="fb-pill fb-off">Off</span><?php endif; ?>
    <?php if ($sell): ?><span class="fb-pill fb-warn">Selling ON</span><?php endif; ?>
  </h2>
  <div class="fb-note" style="margin:0">
    Page: <strong><?= $pageName !== '' ? $e($pageName) : '— (press “Test token”)' ?></strong> ·
    Token: <strong><?= $hasToken ? 'saved' : 'not saved' ?></strong> ·
    App secret: <strong><?= $hasSec ? (FbMessenger::secretFromWhatsApp() ? 'using the WhatsApp app’s secret (same Meta app)' : 'saved') : 'not saved' ?></strong>
  </div>
</div>

<div class="fb-card">
  <h2>1. In your Meta app (developers.facebook.com)</h2>
  <ol class="fb-steps">
    <li>Create (or open) an app of type <em>Business</em> and add the <strong>Messenger</strong> product.</li>
    <li>Under Messenger → <em>Webhooks</em>, set the callback URL and verify token below, and tick <code>messages</code> and <code>messaging_postbacks</code>.</li>
    <li>Under Messenger → <em>Access tokens</em>, connect your Page and generate a <strong>Page access token</strong>; paste it below.</li>
    <li>App settings → Basic → <strong>App secret</strong>; paste it below.</li>
    <li>Until Meta approves <code>pages_messaging</code> (App Review), only Page admins / app testers get answers — test with your own account first.</li>
  </ol>
  <div class="fb-copy">Callback URL: <code><?= $e($hook) ?></code></div>
  <div class="fb-copy">Verify token: <code><?= $e($verify) ?></code>
    <?php if ($canEdit): ?>
      <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="new_verify"><button class="btn ghost" type="submit" onclick="return confirm('Make a new verify token? The Meta app must be updated too.')">New token</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="fb-card">
  <h2>2. Connect and switch on</h2>
  <form method="post" style="display:grid;gap:10px"><?= Security::csrfField() ?><input type="hidden" name="action" value="save">
    <label>Page access token <?= $hasToken ? '(saved — leave empty to keep)' : '' ?>
      <input type="password" name="token" autocomplete="off" placeholder="<?= $hasToken ? '•••••••• saved' : 'paste the Page access token' ?>" <?= $canEdit ? '' : 'disabled' ?>>
    </label>
    <?php if ($hasToken): ?><label class="fb-check"><input type="checkbox" name="clear_token" <?= $canEdit ? '' : 'disabled' ?>> remove the saved token</label><?php endif; ?>
    <label>App secret <?= FbMessenger::secretFromWhatsApp() ? '(optional — the WhatsApp app’s secret is used; paste only if the Page is on a different Meta app)' : ($hasSec ? '(saved — leave empty to keep)' : '') ?>
      <input type="password" name="secret" autocomplete="off" placeholder="<?= $hasSec ? '•••••••• saved' : 'paste the app secret' ?>" <?= $canEdit ? '' : 'disabled' ?>>
    </label>
    <?php if ($hasSec): ?><label class="fb-check"><input type="checkbox" name="clear_secret" <?= $canEdit ? '' : 'disabled' ?>> remove the saved secret</label><?php endif; ?>
    <label class="fb-check"><input type="checkbox" name="fb_msgr_on" <?= $on ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> The Page answers messages with the AI</label>
    <label class="fb-check"><input type="checkbox" name="fb_msgr_sell" <?= $sell ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> The AI may issue tickets in Messenger (after the number is proven and the passenger says “ho”)</label>
    <?php if ($canEdit): ?><div class="fb-actions"><button class="btn primary" type="submit">Save</button></div><?php endif; ?>
  </form>
</div>

<?php if ($canEdit): ?>
<div class="fb-card">
  <h2>3. Check and finish set-up</h2>
  <div class="fb-actions">
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="test"><button class="btn ghost" type="submit" <?= $hasToken ? '' : 'disabled' ?>>Test token</button></form>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="subscribe"><button class="btn ghost" type="submit" <?= $hasToken ? '' : 'disabled' ?>>Subscribe the Page</button></form>
    <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="profile"><button class="btn ghost" type="submit" <?= $hasToken ? '' : 'disabled' ?>>Set “Get Started” + greeting</button></form>
  </div>
  <p class="fb-note" style="margin:0">Then send “namaste” to your Page from your own Facebook account. The answer comes from the same AI as the website chat (One AI page), so it needs that AI switched on.</p>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
