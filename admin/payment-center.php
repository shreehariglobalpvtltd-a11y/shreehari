<?php
/**
 * admin/payment-center.php — पेमेन्ट म्यानेजमेन्ट · Payment Management (25 Nov 2026, mp1 "pay").
 *
 * Owner plan, section 1. Super-admin only. Three tabs:
 *   🇳🇵 नेपाली अनलाइन — the Nepal eSewa / bank QR (the same picture the ticket prints, upload
 *        logic = owner-setup.php's), eSewa id + name, the Gmail box that receives the Global IME
 *        Bank credit alerts (BankMail), Save & Enable, Test connection, last poll, credits table
 *        with 1-click "Link to PNR" and Ignore. Gmail auto-PAID (pay_bankmail_autoconfirm_on) is
 *        OFF by default and shown here read-only (no toggle): a credit that passes every check is
 *        a SUGGESTION - PNR, passenger, due vs credited, DKIM / account / sender chips and a big
 *        "✅ Confirm" (PayCredits::link via the queue gate) after the person checked the bank app.
 *   🇮🇳 इन्डियन अनलाइन — UPI id / name (the company's existing upi_id / upi_name), the bank API
 *        (merchant id, key, secret, webhook secret, sandbox / live, signature header / format,
 *        field map), the webhook address to give the bank, Save & Enable, Test, last webhook,
 *        credits table.
 *   💵 नगद — read-only cash for one day: per counter (CounterBook, the day close) and per
 *        person (counter cash, boarding cash, handed over, balance), with links to the books.
 *
 * 25 Nov 2026 (mp2 "bank Gmail"): on BOTH online tabs a card "📧 बैंक Gmail खाताहरू · Bank Gmail
 * accounts" - every bank Gmail box of that country (BankMailAccounts): Google login / App password,
 * ✓ जोडिएको / ✗ why, last checked, today's credits, "अहिले जाँच्नुहोस् · Check now", पज / चालु,
 * फेरि जोड्नुहोस् (Google), हटाउनुहोस्, Edit (senders / DKIM domain / our account digits / label),
 * "📬 पछिल्लो mail" (the last 20 mails the box looked at = the owner's "sabai update"), and
 * "➕ Gmail जोड्नुहोस्": "🔐 Google बाट Login गर्नुहोस् (browser जस्तै)" (admin/gmail-oauth.php; the
 * one-time Google Cloud setup box with the exact redirect URI while the client is not saved) or an
 * app password. It replaces the old single Gmail form (those settings become account #1 by
 * themselves). The India tab reads Indian bank alerts the same way until the bank API credentials
 * come; each tab's credits table shows its currency from every source and box.
 *
 * Every POST is CSRF-checked and audited; a secret is never shown back (blank keeps it).
 * Money is confirmed ONLY through PayCredits (BookingService gates) - never here directly.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/bankmail.php';   // + BankMailAccounts, GoogleOAuth, SecretBox
require_once INCLUDE_PATH . '/bankapi.php';

$admin = admin_boot('payments.view');
$e     = static fn($v): string => Security::e((string) $v);
if (!Auth::isSuperadmin()) {
    http_response_code(403);
    admin_header('Payment Management', 'payment-center');
    echo '<div class="card" style="max-width:560px;padding:16px">🔒 पेमेन्ट म्यानेजमेन्ट सुपर-एडमिनको मात्र हो · Payment Management is for the super-admin only.</div>';
    admin_footer();
    exit;
}

$me    = (int) $admin['id'];
$tab   = in_array((string) ($_GET['tab'] ?? $_POST['tab'] ?? ''), ['np', 'in', 'cash'], true) ? (string) ($_GET['tab'] ?? $_POST['tab']) : 'np';
$flash = [];
/* the Google sign-in (admin/gmail-oauth.php) comes back here with its result */
if (isset($_SESSION['pc_flash']) && is_array($_SESSION['pc_flash'])) {
    foreach ($_SESSION['pc_flash'] as $f) {
        if (is_array($f) && count($f) === 2) {
            $flash[] = [(string) $f[0] === 'ok' ? 'ok' : 'bad', (string) $f[1]];
        }
    }
    unset($_SESSION['pc_flash']);
}
$mailReady = BankMailAccounts::ready();
$val   = static fn(string $k, string $d = ''): string => Settings::getString($k, $d);
$ready = PayCredits::ready();

/** Save a set of settings; returns the keys that changed. Secrets: blank keeps the saved one. */
$saveKeys = static function (array $spec) use (&$flash): array {
    $changed = [];
    foreach ($spec as $k => [$kind, $max]) {
        if (!array_key_exists($k, $_POST) && $kind !== 'bool') {
            continue;
        }
        if ($kind === 'bool') {
            $v = !empty($_POST[$k]) ? '1' : '0';
            if ($v !== (Settings::getBool($k, false) ? '1' : '0') || Settings::get($k, null) === null) {
                Settings::set($k, $v, 'bool', 'payments');
                $changed[] = $k;
            }
            continue;
        }
        $v = trim((string) $_POST[$k]);
        if ($kind === 'secret' && $v === '') {
            continue;
        }
        if ($kind === 'secret' && str_ends_with($k, '_app_password')) {
            $v = str_replace(' ', '', $v);   // Gmail shows the app password in groups of four
        }
        $v = $kind === 'json' ? $v : mb_substr(Security::clean($v, (int) $max), 0, (int) $max);
        if ($kind === 'json' && $v !== '') {
            $j = json_decode($v, true);
            if (!is_array($j)) {
                $flash[] = ['bad', $k . ': JSON होइन · not valid JSON — kept the old one'];
                continue;
            }
            $v = (string) json_encode($j, JSON_UNESCAPED_SLASHES);
        }
        if ($v === Settings::getString($k, '')) {
            continue;
        }
        Settings::set($k, $v, 'string', in_array($k, ['nepal_esewa_id', 'nepal_esewa_name', 'upi_id', 'upi_name'], true) ? 'company' : 'payments');
        $changed[] = $k;
    }
    Settings::flush();
    return $changed;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash[] = ['bad', 'Session expired — फेरि खोल्नुहोस्।'];
    } elseif ($act === 'np_save') {
        /* the eSewa / bank id + name (the Gmail boxes have their own card since mp2) */
        $tab = 'np';
        $changed = $saveKeys(['nepal_esewa_id' => ['text', 80], 'nepal_esewa_name' => ['text', 120]]);
        if ($changed !== []) {
            Logger::audit('settings.payment_center', 'settings', 'payment-center', null, ['tab' => 'np', 'changed' => $changed], 'Payment Management (Nepal)');
            $flash[] = ['ok', 'सेभ भयो · saved: ' . implode(', ', $changed)];
        } else {
            $flash[] = ['ok', 'केही बदलिएन · nothing changed'];
        }
    } elseif (in_array($act, ['acct_check', 'acct_toggle', 'acct_remove', 'acct_edit'], true)) {
        /* one bank Gmail box (mp2) */
        $acct = $mailReady ? BankMailAccounts::get((int) ($_POST['id'] ?? 0)) : null;
        if ($acct === null) {
            $flash[] = ['bad', 'Gmail खाता भेटिएन · account not found'];
        } else {
            $tab = BankMailAccounts::tabFor((string) $acct['country']);
            $aid = (int) $acct['id'];
            $who = (string) $acct['email'];
            if ($act === 'acct_check' && (int) $acct['is_active'] !== 1) {
                $flash[] = ['bad', $who . ': ⏸ पज छ — पहिले "▶ चालु" थिच्नुहोस् · paused, resume it first.'];
            } elseif ($act === 'acct_check') {
                $r = BankMail::pollAccount($acct, 50);
                Logger::audit('bank_mail.check', 'bank_mail_account', (string) $aid, null,
                    ['ok' => (bool) $r['ok'], 'seen' => (int) $r['seen'], 'credits' => (int) $r['credits'], 'suggested' => (int) $r['suggested']], 'Check now: ' . $who);
                $flash[] = [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✔ ' : '✖ ') . $who . ': ' . (string) $r['msg']];
            } elseif ($act === 'acct_toggle') {
                $on = (int) $acct['is_active'] !== 1;
                BankMailAccounts::setActive($aid, $on);
                Logger::audit('bank_mail.' . ($on ? 'resume' : 'pause'), 'bank_mail_account', (string) $aid, ['is_active' => (int) $acct['is_active']], ['is_active' => $on ? 1 : 0], $who);
                $flash[] = ['ok', $who . ': ' . ($on ? '▶ चालु · on — cron ले हरेक २ मिनेट हेर्छ' : '⏸ पज · paused — mail पढिँदैन')];
            } elseif ($act === 'acct_remove') {
                if ((string) $acct['method'] === 'google') {
                    GoogleOAuth::revoke($acct);   // best effort; the row and its sealed tokens go either way
                }
                BankMailAccounts::remove($aid);
                Logger::audit('bank_mail.remove', 'bank_mail_account', (string) $aid, ['email' => $who, 'country' => (string) $acct['country'], 'method' => (string) $acct['method']], null, 'Bank Gmail removed (tokens deleted)');
                $flash[] = ['ok', $who . ' हटाइयो · removed (token / password मेटियो; पुराना credits ledger मा रहन्छन्)'];
            } else {
                $bad = BankMailAccounts::badSenders((string) ($_POST['senders'] ?? ''));
                $dk  = strtolower(trim((string) ($_POST['dkim_domain'] ?? '')));
                if (!BankMailAccounts::goodDomain($dk)) {
                    $bad[] = 'DKIM domain: जस्तै gibl.com.np · a plain domain';
                }
                $hint = BankMailAccounts::cleanHint((string) ($_POST['account_hint'] ?? ''));
                if ($hint === null) {
                    $bad[] = 'खाताको अन्तिम ४ देखि ६ अंक मात्र · last 4-6 digits of our account only';
                }
                $newPw = str_replace(' ', '', (string) ($_POST['app_password'] ?? ''));
                if ($newPw !== '' && preg_match('/^[A-Za-z0-9]{16}$/', $newPw) !== 1) {
                    $bad[] = 'App password: १६ अक्षर · 16 letters';
                }
                if ($newPw !== '' && !SecretBox::available()) {
                    $bad[] = SecretBox::whyNot();
                }
                if ($bad !== []) {
                    foreach ($bad as $b) { $flash[] = ['bad', $b]; }
                } else {
                    $rules = [
                        'label' => mb_substr(Security::clean((string) ($_POST['label'] ?? ''), 80), 0, 80),
                        'senders' => implode(', ', array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', (string) ($_POST['senders'] ?? ''))))),
                        'dkim_domain' => $dk, 'account_hint' => (string) $hint,
                    ];
                    $changed = [];
                    foreach ($rules as $k => $v) {
                        if ((string) ($acct[$k] ?? '') !== $v) {
                            $changed[] = $k;
                        }
                    }
                    BankMailAccounts::updateRules($aid, $rules);
                    if ($newPw !== '' && (string) $acct['method'] === 'app_password') {
                        Database::update('bank_mail_accounts', ['secret_enc' => SecretBox::seal($newPw, 'bankmail')], 'id = :i', ['i' => $aid]);
                        $changed[] = 'app_password (new)';
                    }
                    if ($changed !== []) {
                        Logger::audit('bank_mail.edit', 'bank_mail_account', (string) $aid, null, ['changed' => $changed, 'senders' => $rules['senders'], 'dkim_domain' => $dk, 'account_hint' => (string) $hint], $who);
                    }
                    $now = BankMailAccounts::get($aid) ?? $acct;
                    $flash[] = ['ok', $who . ': ' . ($changed !== [] ? 'सेभ भयो · saved: ' . implode(', ', $changed) : 'केही बदलिएन · nothing changed')
                        . (BankMailAccounts::isReady($now) ? ' · ✓ तयार · ready' : ' · अधुरो: DKIM domain र खाताको अन्तिम अंक दुवै चाहिन्छ')];
                }
            }
        }
    } elseif ($act === 'acct_add_pw') {
        /* (B) a box with a Google app password (IMAP, read-only) */
        $country = (string) ($_POST['country'] ?? 'NP') === 'IN' ? 'IN' : 'NP';
        $tab   = BankMailAccounts::tabFor($country);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pw    = str_replace(' ', '', (string) ($_POST['app_password'] ?? ''));
        $bad   = [];
        if (!$mailReady) {
            $bad[] = 'Migration upgrade-2026-11-25-mp2-bank-gmail.sql बाँकी · run the migration first';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 190) {
            $bad[] = 'Gmail ठेगाना मिलेन · not an e-mail address';
        }
        if (preg_match('/^[A-Za-z0-9]{16}$/', $pw) !== 1) {
            $bad[] = 'App password: Google ले दिएको १६ अक्षर · the 16 letters Google shows';
        }
        $dk   = strtolower(trim((string) ($_POST['dkim_domain'] ?? '')));
        $hint = BankMailAccounts::cleanHint((string) ($_POST['account_hint'] ?? ''));
        $bad  = array_merge($bad, BankMailAccounts::badSenders((string) ($_POST['senders'] ?? '')));
        if (!BankMailAccounts::goodDomain($dk)) {
            $bad[] = 'DKIM domain: जस्तै gibl.com.np · a plain domain';
        }
        if ($hint === null) {
            $bad[] = 'खाताको अन्तिम ४ देखि ६ अंक मात्र · last 4-6 digits of our account only';
        }
        if ($bad === [] && !SecretBox::available()) {
            $bad[] = SecretBox::whyNot();
        }
        if ($bad !== []) {
            foreach ($bad as $b) { $flash[] = ['bad', $b]; }
        } else {
            try {
                $aid = BankMailAccounts::save([
                    'country' => $country, 'email' => $email, 'method' => 'app_password', 'secret_enc' => SecretBox::seal($pw, 'bankmail'),
                    'senders' => implode(', ', array_filter(array_map(static fn(string $s): string => strtolower(trim($s)), explode(',', (string) ($_POST['senders'] ?? ''))))),
                    'dkim_domain' => $dk, 'account_hint' => (string) $hint,
                ], $me);
                Logger::audit('bank_mail.app_password_add', 'bank_mail_account', (string) $aid, null, ['country' => $country, 'email' => $email, 'method' => 'app_password'], 'Bank Gmail added with an app password');
                $flash[] = ['ok', '✅ ' . $email . ' जोडियो · added (app password, read-only). "🔄 अहिले जाँच्नुहोस्" थिचेर login जाँच्नुहोस्।'];
            } catch (Throwable $ex) {
                $flash[] = ['bad', $ex->getMessage()];
            }
        }
    } elseif ($act === 'google_save') {
        /* the one-time Google Cloud OAuth client (super-admin only settings: AiOps prefix google_oauth_) */
        $tab = ($_POST['tab'] ?? '') === 'in' ? 'in' : 'np';
        $bad = [];
        $cid = trim((string) ($_POST['google_oauth_client_id'] ?? ''));
        $sec = trim((string) ($_POST['google_oauth_client_secret'] ?? ''));
        $red = trim((string) ($_POST['google_oauth_redirect'] ?? ''));
        if ($cid !== '' && preg_match('/^[A-Za-z0-9\-_.]{6,200}\.apps\.googleusercontent\.com$/', $cid) !== 1) {
            $bad[] = 'Client ID: …apps.googleusercontent.com मा सकिनुपर्छ';
        }
        if ($sec !== '' && preg_match('/^[A-Za-z0-9\-_]{10,120}$/', $sec) !== 1) {
            $bad[] = 'Client Secret मिलेन (जस्तै GOCSPX-…)';
        }
        if ($red !== '' && preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?/[^\s<>"\']*$#', $red) !== 1) {
            $bad[] = 'Redirect URI: https:// ले सुरु हुनुपर्छ (खाली = आफैं)';
        }
        if ($bad !== []) {
            foreach ($bad as $b) { $flash[] = ['bad', $b]; }
        } else {
            $changed = [];
            foreach (['google_oauth_client_id' => $cid, 'google_oauth_redirect' => $red] as $k => $v) {
                if (array_key_exists($k, $_POST) && $v !== Settings::getString($k, '')) {
                    Settings::set($k, $v, 'string', 'payments');
                    $changed[] = $k;
                }
            }
            if ($sec !== '' && $sec !== Settings::getString('google_oauth_client_secret', '')) {   // blank keeps the saved one
                Settings::set('google_oauth_client_secret', $sec, 'string', 'payments');
                $changed[] = 'google_oauth_client_secret';
            }
            Settings::flush();
            if ($changed !== []) {
                Logger::audit('settings.google_oauth', 'settings', 'payment-center', null, ['changed' => $changed], 'Google sign-in client for bank Gmail');
            }
            $flash[] = ['ok', $changed !== [] ? 'Google client सेभ भयो · saved: ' . implode(', ', $changed) . (GoogleOAuth::configured() ? ' — अब "🔐 Google बाट Login" थिच्नुहोस्' : '') : 'केही बदलिएन · nothing changed'];
        }
    } elseif ($act === 'np_qr') {
        /* the same handler as admin/owner-setup.php act=esewa_qr: re-encoded PNG, setting nepal_esewa_qr */
        $tab = 'np';
        try {
            if (!empty($_POST['remove'])) {
                $oldQr = Settings::getString('nepal_esewa_qr', '');
                Settings::set('nepal_esewa_qr', '', 'string', 'company');
                Settings::flush();
                Logger::audit('settings.esewa_qr', 'settings', 'payment-center', ['file' => $oldQr], ['file' => ''], 'Nepal eSewa QR removed');
                $flash[] = ['ok', 'eSewa QR हटाइयो · removed'];
            } else {
                $f = $_FILES['esewa_qr'] ?? [];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                    throw new RuntimeException('QR को फोटो छान्नुहोस् · choose the QR image');
                }
                $mime = mime_content_type((string) $f['tmp_name']) ?: '';
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || (int) ($f['size'] ?? 0) > 3 * 1024 * 1024) {
                    throw new RuntimeException('PNG / JPG / WebP, 3 MB सम्म');
                }
                $src = @imagecreatefromstring((string) file_get_contents((string) $f['tmp_name']));
                if ($src === false) {
                    throw new RuntimeException('फोटो खुलेन · image could not be read');
                }
                $w = imagesx($src); $h = imagesy($src);
                $sc = min(1.0, 900 / max(1, $w, $h));
                $dst = imagecreatetruecolor(max(1, (int) round($w * $sc)), max(1, (int) round($h * $sc)));
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
                $dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads', '/\\') . '/payqr';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('Could not create uploads/payqr');
                }
                $name = 'esewa-npj-' . bin2hex(random_bytes(4)) . '.png';
                $ok = imagepng($dst, $dir . '/' . $name . '.tmp', 6) && rename($dir . '/' . $name . '.tmp', $dir . '/' . $name);
                imagedestroy($src); imagedestroy($dst);
                if (!$ok) {
                    @unlink($dir . '/' . $name . '.tmp');
                    throw new RuntimeException('Could not save the QR');
                }
                $oldQr = Settings::getString('nepal_esewa_qr', '');
                Settings::set('nepal_esewa_qr', 'uploads/payqr/' . $name, 'string', 'company');
                Settings::flush();
                Logger::audit('settings.esewa_qr', 'settings', 'payment-center', ['file' => $oldQr], ['file' => 'uploads/payqr/' . $name], 'Nepal eSewa QR uploaded');
                $flash[] = ['ok', 'eSewa QR सेभ भयो · saved — टिकट, pay पेज र QR पेजमा यही देखिन्छ'];
            }
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    } elseif ($act === 'in_save') {
        $tab = 'in';
        $bad = [];
        $upi = trim((string) ($_POST['upi_id'] ?? ''));
        if ($upi !== '' && preg_match('/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/', $upi) !== 1) {
            $bad[] = 'UPI ID मिलेन (जस्तै name@icici)';
        }
        $url = trim((string) ($_POST['bankapi_status_url'] ?? ''));
        if ($url !== '' && preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?(/[^\s]*)?$#', $url) !== 1) {
            $bad[] = 'Status URL: https:// ले सुरु हुनुपर्छ';
        }
        foreach (['bankapi_sig_header', 'bankapi_ts_header', 'bankapi_key_header'] as $hk) {
            $hv = trim((string) ($_POST[$hk] ?? ''));
            if ($hv !== '' && preg_match('/^[A-Za-z0-9\-]{1,60}$/', $hv) !== 1) {
                $bad[] = $hk . ': अक्षर, अंक र - मात्र';
            }
        }
        $mode = (string) ($_POST['bankapi_mode'] ?? 'sandbox');
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            $mode = 'sandbox';
        }
        $_POST['bankapi_mode'] = $mode;
        $_POST['bankapi_sig_format'] = ($_POST['bankapi_sig_format'] ?? 'hex') === 'base64' ? 'base64' : 'hex';
        if ($bad !== []) {
            foreach ($bad as $b) { $flash[] = ['bad', $b]; }
        } else {
            $changed = $saveKeys([
                'upi_id' => ['text', 120], 'upi_name' => ['text', 120], 'bankapi_bank_name' => ['text', 80],
                'bankapi_merchant_id' => ['text', 80], 'bankapi_key' => ['secret', 300], 'bankapi_secret' => ['secret', 300],
                'bankapi_webhook_secret' => ['secret', 300], 'bankapi_sig_header' => ['text', 60], 'bankapi_sig_format' => ['text', 8],
                'bankapi_ts_header' => ['text', 60], 'bankapi_key_header' => ['text', 60], 'bankapi_status_url' => ['text', 300],
                'bankapi_field_map' => ['json', 0],
            ]);
            /* live only once the merchant id and the webhook secret are there */
            if ($mode !== BankApi::mode()) {
                if ($mode === 'live' && ($val('bankapi_merchant_id') === '' || $val('bankapi_webhook_secret') === '')) {
                    $flash[] = ['bad', 'Live गर्न Merchant ID र Webhook secret चाहिन्छ · still sandbox'];
                } else {
                    Settings::set('bankapi_mode', $mode, 'string', 'payments');
                    $changed[] = 'bankapi_mode=' . $mode;
                }
            }
            $want = (string) ($_POST['enable'] ?? '');
            if ($want === '1' || $want === '0') {
                if ($want === '1' && ($val('bankapi_merchant_id') === '' || $val('bankapi_webhook_secret') === '' || !$ready)) {
                    $flash[] = ['bad', 'चालु गर्न Merchant ID र Webhook secret चाहिन्छ' . ($ready ? '' : ' (र migration)')];
                } elseif ($want !== (BankApi::on() ? '1' : '0')) {
                    Settings::set('bankapi_on', $want, 'bool', 'payments');
                    $changed[] = 'bankapi_on=' . $want;
                }
            }
            Settings::flush();
            if ($changed !== []) {
                Logger::audit('settings.payment_center', 'settings', 'payment-center', null, ['tab' => 'in', 'changed' => $changed], 'Payment Management (India)');
                $flash[] = ['ok', 'सेभ भयो · saved: ' . implode(', ', $changed)];
            } elseif (!array_filter($flash, static fn($f) => $f[0] === 'bad')) {
                $flash[] = ['ok', 'केही बदलिएन · nothing changed'];
            }
        }
    } elseif ($act === 'in_test') {
        $tab = 'in';
        $r = BankApi::testConnection();
        Logger::audit('payment_center.test', 'settings', 'bankapi', null, ['ok' => $r['ok']], 'Bank API test');
        $flash[] = [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✔ ' : '✖ ') . $r['msg'] . ' — ' . implode(' · ', array_map(static fn(array $c): string => ($c[1] ? '✔ ' : '✖ ') . $c[0] . ($c[2] !== '' ? ' (' . $c[2] . ')' : ''), $r['checks']))];
    } elseif ($act === 'confirm') {
        /* a Gmail SUGGESTION: the person checked the bank app; the same gate as Link to PNR
           (PayCredits::link -> approveReviewed via 'queue': PaymentRisk + the person stay in the loop) */
        $id = (int) ($_POST['id'] ?? 0);
        $c  = PayCredits::get($id);
        $tab = (string) ($c['currency'] ?? 'NPR') === 'INR' ? 'in' : 'np';
        try {
            if (!PayCredits::isSuggestion($c) || (string) ($c['source'] ?? '') !== 'gmail') {
                throw new RuntimeException('यो अब सुझाव होइन · this credit is no longer a suggestion (already confirmed or changed) — पेज फेरि खोल्नुहोस्।');
            }
            if (empty($_POST['bank_checked'])) {
                throw new RuntimeException('पहिले बैंक app मा पैसा आएको हेर्नुहोस् र टिक लगाउनुहोस् · tick "I saw it in the bank app" first.');
            }
            $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => (int) $c['booking_id']], '');
            if ($pnr === '') {
                throw new RuntimeException('Booking not found.');
            }
            $r = PayCredits::link($id, $pnr, $me, true, 'bank app checked');
            $flash[] = [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✅ ' : '') . $r['msg']];
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    } elseif ($act === 'link' || $act === 'ignore') {
        $id = (int) ($_POST['id'] ?? 0);
        $c  = PayCredits::get($id);
        $tab = $c !== null && ((string) $c['source'] === 'bankapi' || (string) $c['currency'] === 'INR') ? 'in' : 'np';
        try {
            if ($act === 'link') {
                $r = PayCredits::link($id, (string) ($_POST['pnr'] ?? ''), $me, !empty($_POST['bank_checked']), (string) ($_POST['note'] ?? ''));
                $flash[] = [$r['ok'] ? 'ok' : 'bad', $r['msg']];
            } else {
                PayCredits::ignore($id, $me, (string) ($_POST['note'] ?? ''));
                $flash[] = ['ok', 'Credit #' . $id . ' ignored · अलग राखियो'];
            }
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    }
}

/* ---------------- what the page shows ---------------- */
$lastPoll = Settings::get('bankmail_last_poll', null);
if (is_string($lastPoll)) {
    $lastPoll = json_decode($lastPoll, true);
}
$lastPoll = is_array($lastPoll) ? $lastPoll : [];
$lastHook = BankApi::lastWebhook();
if ($mailReady && $tab !== 'cash') {
    BankMailAccounts::migrateLegacy();   // the old single Gmail settings become account #1, once
}

$stateTxt = [
    'new' => ['नयाँ · new', '#185bed'], 'matched' => ['✔ Auto PAID', '#0a7c2f'], 'linked' => ['✔ Linked', '#0a7c2f'],
    'unmatched' => ['⏳ व्यक्ति चाहिन्छ · needs a person', '#b26a00'], 'ignored' => ['Ignored', '#667085'], 'test' => ['TEST (sandbox)', '#7a4b00'],
];
/* $cur: NPR (Nepal tab: the Gmail boxes + the old single box) | INR (India tab: bank API + the India Gmail boxes) */
$credits = static function (string $cur) use ($e, $stateTxt): void {
    $rows = PayCredits::listRecent(['currency' => $cur, 'limit' => 60]);
    if (!PayCredits::ready()) {
        echo '<p class="pc-warn">⚠️ <b>database/upgrade-2026-11-25-mp1-pay-credits.sql</b> चलाइएको छैन — credits table छैन। · Run the migration first.</p>';
        return;
    }
    if ($rows === []) {
        echo '<p class="muted">अहिलेसम्म कुनै credit आएको छैन · no credits yet.</p>';
        return;
    }
    echo '<div class="pc-list">';
    foreach ($rows as $r) {
        $sv = PayCredits::isSuggestion($r) ? PayCredits::suggestionView($r) : null;
        [$lbl, $col] = $sv !== null ? ['💡 सुझाव · Suggested — Confirm चाहिन्छ', '#185bed']
            : ($stateTxt[(string) $r['state']] ?? [(string) $r['state'], '#667085']);
        $open = in_array((string) $r['state'], ['new', 'unmatched'], true);
        $kind = (string) ($r['match_kind'] ?? '');
        echo '<div class="pc-cr">';
        echo '<div class="pc-cr-top"><b>' . $e($r['currency'] . ' ' . number_format((float) $r['amount'], 2)) . '</b>'
           . ' <span class="pc-st" style="color:' . $e($col) . ';border-color:' . $e($col) . '">' . $e($lbl) . '</span>'
           . ($kind === 'amount' ? ' <span class="pc-chip warn">रकम मात्र · amount only</span>' : ($kind === 'pnr' ? ' <span class="pc-chip ok">PNR</span>' : ''))
           . ' <small class="muted">#' . (int) $r['id'] . ' · ' . $e(substr((string) ($r['received_at'] ?? $r['created_at']), 0, 16)) . '</small></div>';
        echo '<div class="pc-cr-meta">' . ($r['source'] === 'gmail' ? '📧 ' . $e((string) (($r['mail_email'] ?? '') !== '' ? $r['mail_email'] : (!empty($r['mail_account_id']) ? 'box #' . (int) $r['mail_account_id'] . ' (removed)' : 'Gmail (old settings)'))) : '🔗 Bank API') . ' · '
           . 'Txn <code>' . $e($r['txn_id'] ?? '—') . '</code>'
           . ($r['ref_text'] ? ' · ' . $e(mb_substr((string) $r['ref_text'], 0, 90)) : '')
           . ($r['booking_pnr'] ? ' · <a href="booking-view.php?pnr=' . rawurlencode((string) $r['booking_pnr']) . '">' . $e($r['booking_pnr']) . '</a>' : '')
           . ($r['source'] === 'gmail' ? ' · ' . ((int) $r['dkim_ok'] === 1 ? '<span style="color:#0a7c2f">DKIM ✔</span>' : '<span style="color:#b3261e">DKIM ✖</span>') . ' ' . $e($r['sender'] ?? '')
               . (!empty($r['credit_account']) ? ' · A/C ' . $e((string) $r['credit_account'] === '?' ? '? (two accounts)' : '…' . substr((string) $r['credit_account'], -6)) : '') : '')
           . '</div>';
        if (!empty($r['note'])) {
            echo '<div class="pc-cr-note">' . $e($r['note']) . ($r['acted_name'] ? ' — ' . $e($r['acted_name']) : '') . '</div>';
        }
        if ($sv !== null) {
            /* a Gmail SUGGESTION: everything matched, auto-PAID is off - the booking is still pending */
            $chip = static fn(string $t, bool $ok): string => '<span class="pc-chip ' . ($ok ? 'ok' : 'no') . '">' . ($ok ? '✓ ' : '✗ ') . $e($t) . '</span>';
            $due  = $sv['currency'] === 'NPR' ? 'NPR ' . number_format($sv['due_npr'], 2) : inr($sv['due_inr']);
            echo '<div class="pc-sug">'
               . '<div class="pc-sug-grid">'
               . '<div><small>PNR</small><b><a href="booking-view.php?pnr=' . rawurlencode($sv['pnr']) . '">' . $e($sv['pnr']) . '</a></b>'
                   . ($sv['status'] !== 'pending' ? '<small class="pc-no">' . $e($sv['status']) . '</small>' : '') . '</div>'
               . '<div><small>यात्री · Passenger</small><b>' . $e($sv['name'] !== '' ? $sv['name'] : '—') . '</b></div>'
               . '<div><small>तिर्नुपर्ने · Due</small><b>' . $e($due) . '</b>'
                   . ($sv['currency'] === 'NPR' ? '<small>' . $e(inr($sv['due_inr'])) . '</small>' : '') . '</div>'
               . '<div><small>बैंकमा आयो · Credited</small><b>' . $e($sv['currency'] . ' ' . number_format($sv['credited'], 2)) . '</b></div>'
               . '</div>'
               . '<div class="pc-chips">';
            foreach ($sv['checks'] as [$ct, $cok]) {
                echo $chip($ct, $cok);
            }
            echo $chip('रकम मिल्यो · amount', $sv['amount_ok']) . '</div>';
            if (($sv['kind'] ?? 'pnr') === 'amount') {
                /* mp2: the alert had NO PNR - only the amount matched this one booking */
                echo '<p class="pc-warn" style="margin:8px 0 0">⚠️ <b>PNR छैन — रकम मात्र मिल्यो</b> · amount-only match: यात्रीको नाम / फोन'
                   . ($sv['phone'] !== '' ? ' (<b>' . $e($sv['phone']) . '</b>)' : '') . ' र बैंक app मा पठाउनेको नाम मिलाएर मात्र Confirm गर्नुहोस्।</p>';
            }
            if ($sv['can_confirm']) {
                echo '<form method="post" class="pc-confirm">' . Security::csrfField()
                   . '<input type="hidden" name="act" value="confirm"><input type="hidden" name="tab" value="' . ($sv['currency'] === 'INR' ? 'in' : 'np') . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                   . '<label class="pc-chk"><input type="checkbox" name="bank_checked" value="1" required> बैंक app मा यो पैसा आएको देखें · I saw this money in the bank app</label>'
                   . '<button class="btn btn-orange pc-big" type="submit">✅ Confirm · पुष्टि गर्नुहोस्</button></form>';
            } else {
                echo '<p class="pc-warn" style="margin:6px 0 0">⚠️ अब केही मिलेन (✗ माथि हेर्नुहोस्) — बैंक statement जाँचेर मात्र Link गर्नुहोस् · something no longer matches.</p>'
                   . '<form method="post" class="pc-inline" style="margin-top:6px">' . Security::csrfField() . '<input type="hidden" name="act" value="link"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                   . '<input name="pnr" required maxlength="40" value="' . $e($sv['pnr']) . '" autocapitalize="characters">'
                   . '<label class="pc-chk"><input type="checkbox" name="bank_checked" value="1" required> बैंकमा पैसा आएको जाँचें · I checked the bank</label>'
                   . '<button class="btn btn-orange" type="submit">🔗 Link to PNR</button></form>';
            }
            echo '<form method="post" class="pc-inline" style="margin-top:6px">' . Security::csrfField() . '<input type="hidden" name="act" value="ignore"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
               . '<input name="note" maxlength="120" placeholder="बैंक app मा छैन? किन · not in the bank app? why"><button class="btn" type="submit">Ignore</button></form>'
               . '</div>';
        }
        if ($open) {
            echo '<div class="pc-cr-act">'
               . '<form method="post" class="pc-inline">' . Security::csrfField() . '<input type="hidden" name="act" value="link"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
               . '<input name="pnr" required maxlength="40" placeholder="SHG-2026-00842" value="' . $e($r['pnr'] ?? '') . '" autocapitalize="characters">'
               . '<label class="pc-chk"><input type="checkbox" name="bank_checked" value="1" required> बैंकमा पैसा आएको जाँचें · I checked the bank</label>'
               . '<button class="btn btn-orange" type="submit">🔗 Link to PNR</button></form>'
               . '<form method="post" class="pc-inline">' . Security::csrfField() . '<input type="hidden" name="act" value="ignore"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
               . '<input name="note" maxlength="120" placeholder="किन? · why (refund, not ours…)"><button class="btn" type="submit">Ignore</button></form>'
               . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
};

/* ---------------- 📧 bank Gmail boxes (mp2) ---------------- */
$outTxt = [
    'suggestion' => ['💡 सुझाव', 'info'], 'suggestion-amount' => ['💡 सुझाव (रकम मात्र)', 'info'], 'confirmed' => ['✔ PAID', 'ok'],
    'unmatched' => ['⏳ व्यक्ति चाहिन्छ', 'warn'], 'duplicate' => ['दोहोरो · duplicate', 'mut'], 'debit-ignored' => ['डेबिट · ignored', 'mut'],
    'not-credit' => ['credit होइन', 'mut'], 'not-bank-sender' => ['बैंकको होइन · not bank sender', 'mut'],
    'setup-incomplete' => ['अधुरो · setup incomplete', 'warn'], 'unreadable' => ['✖ पढ्न सकिएन', 'no'], 'gone' => ['हटाइएको · gone', 'mut'],
];
/** The one-time Google Cloud setup: numbered Nepali steps + the exact redirect URI + the client form. */
$googleSetup = static function (string $tabKey, bool $open) use ($e): void {
    $uri = GoogleOAuth::redirectUri();
    echo '<div class="pc-gsetup">'
       . '<p class="hint" style="margin:0 0 6px"><b>एकपटकको Google setup</b> (करिब १० मिनेट) — यसपछि जुनसुकै बैंक Gmail एक click मा जोडिन्छ:</p>'
       . '<ol class="pc-steps">'
       . '<li><a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">console.cloud.google.com</a> मा project बनाउनुहोस् (जस्तै "S Hari Bank Mail")।</li>'
       . '<li>APIs &amp; Services → Library → <b>"Gmail API"</b> खोजेर <b>Enable</b> गर्नुहोस्।</li>'
       . '<li><b>OAuth consent screen</b>: User type <b>External</b>, app name, आफ्नो email; Scopes मा <code>…/auth/gmail.readonly</code> थप्नुहोस्; <b>Test users</b> मा आफ्नो bank Gmail थप्नुहोस्; अनि <b>"Publish app"</b> (In production) गर्नुहोस् — नत्र Google ले हरेक ७ दिनमा logout गर्छ।</li>'
       . '<li>Credentials → Create credentials → <b>OAuth client ID</b> → <b>Web application</b> → Authorized redirect URIs मा ठ्याक्कै यो राख्नुहोस्:'
       . '<span class="pc-copy"><input class="pc-url" readonly value="' . $e($uri) . '" onclick="this.select()" aria-label="Redirect URI">'
       . '<button class="btn" type="button" onclick="pcCopy(this)">📋 Copy</button></span></li>'
       . '<li><b>Client ID</b> र <b>Client Secret</b> तल राखेर Save गर्नुहोस्।</li>'
       . '</ol>'
       . '<p class="hint">Login गर्दा Google ले <b>"Google hasn\'t verified this app"</b> देखाउँछ — <b>Advanced</b> → <b>Go to … (unsafe)</b> थिच्नुहोस् (यो तपाईंकै app हो)। Gmail पढ्न मात्र (read-only) अनुमति माग्छ; mailbox मा केही बदलिँदैन।</p>'
       . '<details class="pc-adv"' . ($open ? ' open' : '') . '><summary>🔧 Google Client ID / Secret</summary>'
       . '<form method="post" class="pc-f" autocomplete="off">' . Security::csrfField()
       . '<input type="hidden" name="act" value="google_save"><input type="hidden" name="tab" value="' . $e($tabKey) . '">'
       . '<label>Client ID<input name="google_oauth_client_id" value="' . $e(GoogleOAuth::clientId()) . '" placeholder="1234-abc.apps.googleusercontent.com"></label>'
       . '<label>Client Secret ' . (Settings::getString('google_oauth_client_secret', '') !== '' ? '<span class="pc-ok">✔ saved</span>' : '')
           . '<input name="google_oauth_client_secret" type="password" value="" autocomplete="new-password" placeholder="' . (Settings::getString('google_oauth_client_secret', '') !== '' ? '•••• (बदल्न मात्र लेख्नुहोस्)' : 'GOCSPX-…') . '"></label>'
       . '<label>Redirect URI (खाली = माथिको) <input name="google_oauth_redirect" value="' . $e(Settings::getString('google_oauth_redirect', '')) . '" placeholder="' . $e($uri) . '"></label>'
       . '<div class="pc-btns" style="grid-column:1/-1;margin:0"><button class="btn btn-orange" type="submit">सेभ · Save</button></div>'
       . '</form></details></div>';
};
/** The card on a tab: the boxes of one country + "➕ Gmail जोड्नुहोस्". */
$mailCard = static function (string $country) use ($e, $outTxt, $googleSetup, $mailReady): void {
    $tabKey = $country === 'IN' ? 'in' : 'np';
    $ph = $country === 'IN'
        ? ['senders' => 'alerts@icicibank.com, @hdfcbank.net', 'dkim' => 'icicibank.com', 'mail' => 'shg.indiabank@gmail.com']
        : ['senders' => 'alerts@gibl.com.np, @gibl.com.np', 'dkim' => 'gibl.com.np', 'mail' => 'shg.bankalerts@gmail.com'];
    echo '<div class="pc-card" id="pc-mail-' . $e($country) . '">'
       . '<h2>📧 बैंक Gmail खाताहरू · Bank Gmail accounts</h2>'
       . '<p class="hint">बैंकको credit alert आउने Gmail यहीँ जोड्नुहोस्। हरेक २ मिनेटमा नयाँ mail पढिन्छ (read-only)। Alert मा <b>हाम्रै खाता</b> (अन्तिम अंक), PNR र रकम ठ्याक्कै मिलेमा <b>💡 सुझाव</b> आउँछ — बैंक app मा हेरेर Confirm थिच्नुहोस्। PNR नभए पनि रकम एउटै टिकटसँग मात्र मिले <b>रकम मात्र</b> सुझाव आउँछ।</p>';
    if (!$mailReady) {
        echo '<p class="pc-warn">⚠️ Migration <b>database/upgrade-2026-11-25-mp2-bank-gmail.sql</b> बाँकी — Gmail खाता अहिले जोड्न सकिँदैन · run the migration first.</p></div>';
        return;
    }
    $accts = BankMailAccounts::all($country);
    if ($accts === []) {
        echo '<p class="muted">अहिलेसम्म कुनै Gmail जोडिएको छैन · no Gmail connected yet.</p>';
    }
    foreach ($accts as $a) {
        $aid = (int) $a['id'];
        $ready = BankMailAccounts::isReady($a);
        $on = (int) $a['is_active'] === 1;
        $okv = $a['last_ok'] === null ? null : (int) $a['last_ok'];
        $status = $okv === null ? '<span class="pc-chip mut">… अहिलेसम्म जाँचिएको छैन · not checked yet</span>'
            : ($okv === 1 ? '<span class="pc-chip ok">✓ जोडिएको · connected</span>' : '<span class="pc-chip no">✗ ' . $e(mb_substr((string) ($a['last_result'] ?? ''), 0, 140)) . '</span>');
        $post = static fn(string $act, string $label, string $cls = 'btn', string $extra = ''): string =>
            '<form method="post" class="pc-inline"' . $extra . '>' . Security::csrfField()
            . '<input type="hidden" name="act" value="' . $e($act) . '"><input type="hidden" name="tab" value="' . $e($tabKey) . '"><input type="hidden" name="id" value="' . $aid . '">'
            . '<button class="' . $e($cls) . '" type="submit">' . $label . '</button></form>';
        echo '<div class="pc-acc" id="pc-acc-' . $aid . '">'
           . '<div class="pc-acc-top"><b class="pc-acc-mail">' . $e($a['email']) . '</b>'
           . ' <span class="pc-chip info">' . $e(BankMailAccounts::methodLabel($a)) . '</span> ' . $status
           . ($ready ? '' : ' <span class="pc-chip warn">अधुरो · setup incomplete</span>')
           . ($on ? '' : ' <span class="pc-chip mut">⏸ पज · paused</span>') . '</div>'
           . '<div class="pc-acc-meta">' . (trim((string) ($a['label'] ?? '')) !== '' ? $e($a['label']) . ' · ' : '')
           . 'अन्तिम जाँच · checked ' . $e(!empty($a['last_poll_at']) ? timeAgo((string) $a['last_poll_at']) : '—')
           . ' · आज credit · today: <b>' . BankMailAccounts::creditsToday($aid) . '</b>'
           . ' · DKIM ' . $e(BankMailAccounts::dkimDomain($a) !== '' ? BankMailAccounts::dkimDomain($a) : '—')
           . ' · A/C …' . $e(BankMailAccounts::hint($a) !== '' ? substr(BankMailAccounts::hint($a), -4) : '—') . '</div>';
        if (!$ready) {
            echo '<p class="pc-warn" style="margin:6px 0 0;font-size:12.5px">अधुरो: तल "Edit" मा बैंकको <b>DKIM domain</b> र हाम्रो खाताको <b>अन्तिम ४–६ अंक</b> राख्नुहोस्। त्यतिन्जेल mail पढिन्छ र तल log हुन्छ, तर credit / सुझाव बन्दैन।</p>';
        }
        echo '<div class="pc-btns pc-acc-btns">'
           . $post('acct_check', '🔄 अहिले जाँच्नुहोस् · Check now', 'btn btn-orange')
           . $post('acct_toggle', $on ? '⏸ पज · Pause' : '▶ चालु · Resume')
           . ((string) $a['method'] === 'google'
               ? '<a class="btn" href="gmail-oauth.php?start=1&amp;country=' . $e($a['country']) . '&amp;account=' . $aid . '">🔁 फेरि जोड्नुहोस् · Reconnect</a>' : '')
           . $post('acct_remove', '🗑 हटाउनुहोस् · Remove', 'btn', ' onsubmit="return confirm(' . $e((string) json_encode('यो Gmail हटाउने? Token / password मेटिन्छ। · Remove ' . $a['email'] . '?', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) . ')"')
           . '</div>';
        echo '<details class="pc-adv"><summary>✏️ Edit · नियम (sender / DKIM / खाता अंक)</summary>'
           . '<form method="post" class="pc-f" autocomplete="off">' . Security::csrfField()
           . '<input type="hidden" name="act" value="acct_edit"><input type="hidden" name="tab" value="' . $e($tabKey) . '"><input type="hidden" name="id" value="' . $aid . '">'
           . '<label>नाम · label<input name="label" maxlength="80" value="' . $e($a['label'] ?? '') . '" placeholder="' . ($country === 'IN' ? 'ICICI current a/c' : 'Global IME NPJ') . '"></label>'
           . '<label>बैंकको sender (comma; खाली = DKIM domain बाट जुनसुकै) · allowed senders<input name="senders" maxlength="500" value="' . $e($a['senders'] ?? '') . '" placeholder="' . $e($ph['senders']) . '"></label>'
           . '<label>DKIM domain (बैंकको)<input name="dkim_domain" maxlength="120" value="' . $e($a['dkim_domain'] ?? '') . '" placeholder="' . $e($ph['dkim']) . '"></label>'
           . '<label>हाम्रो खाताको अन्तिम ४–६ अंक · our account\'s last 4–6 digits<input name="account_hint" inputmode="numeric" maxlength="8" value="' . $e($a['account_hint'] ?? '') . '" placeholder="9012"></label>'
           . ((string) $a['method'] === 'app_password'
               ? '<label>नयाँ App password (खाली = पुरानै)<input name="app_password" type="password" value="" autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx"></label>' : '')
           . '<div class="pc-btns" style="grid-column:1/-1;margin:0"><button class="btn" type="submit">सेभ · Save</button></div>'
           . '</form></details>';
        $log = BankMailAccounts::recentLog($aid, 20);
        echo '<details class="pc-adv pc-log"><summary>📬 पछिल्लो mail · Latest mails (' . count($log) . ')</summary>';
        if ($log === []) {
            echo '<p class="muted" style="font-size:12.5px">अहिलेसम्म कुनै mail हेरिएको छैन · nothing read yet.</p>';
        } else {
            echo '<ul class="pc-loglist">';
            foreach ($log as $l) {
                [$ot, $oc] = $outTxt[(string) $l['outcome']] ?? [(string) $l['outcome'], 'mut'];
                echo '<li><small class="muted">' . $e(substr((string) ($l['received_at'] ?? $l['created_at']), 5, 11)) . '</small> '
                   . '<span class="pc-chip ' . $e($oc) . '">' . $e($ot) . '</span> '
                   . '<span class="pc-subj">' . $e(trim((string) ($l['subject'] ?? '')) !== '' ? $l['subject'] : '(no subject)') . '</span>'
                   . (!empty($l['credit_id']) ? ' <small class="muted">credit #' . (int) $l['credit_id'] . '</small>' : '') . '</li>';
            }
            echo '</ul>';
        }
        echo '</details></div>';
    }
    /* ➕ add a box */
    echo '<div class="pc-add"><h3>➕ Gmail जोड्नुहोस् · Add Gmail</h3>';
    if (GoogleOAuth::configured()) {
        echo '<a class="btn btn-orange pc-big pc-glogin" href="gmail-oauth.php?start=1&amp;country=' . $e($country) . '">🔐 Google बाट Login गर्नुहोस् (browser जस्तै)</a>'
           . '<p class="hint" style="margin:6px 0 0">Google को page खुल्छ: बैंकको Gmail छान्नुहोस् (वा "Use another account"), "Google hasn\'t verified this app" आए <b>Advanced → Go to …</b> (तपाईंकै app), अनि <b>Continue</b>। पढ्न मात्र अनुमति।</p>';
        $googleSetup($tabKey, false);
    } else {
        echo '<p class="pc-warn" style="margin:0 0 8px">🔐 "Google बाट Login" को लागि पहिले एकपटक Google Client ID / Secret चाहिन्छ:</p>';
        $googleSetup($tabKey, true);
    }
    echo '<details class="pc-adv"><summary>🔑 App Password ले जोड्नुहोस् · with an app password</summary>'
       . '<p class="hint" style="margin:0 0 8px">Google Account → Security → 2-Step Verification → <b>App passwords</b> → १६ अक्षरको password। (IMAP, read-only)</p>'
       . '<form method="post" class="pc-f" autocomplete="off">' . Security::csrfField()
       . '<input type="hidden" name="act" value="acct_add_pw"><input type="hidden" name="tab" value="' . $e($tabKey) . '"><input type="hidden" name="country" value="' . $e($country) . '">'
       . '<label>Gmail ठेगाना · address<input name="email" type="email" required maxlength="190" placeholder="' . $e($ph['mail']) . '"></label>'
       . '<label>App password<input name="app_password" type="password" required autocomplete="new-password" placeholder="xxxx xxxx xxxx xxxx"></label>'
       . '<label>DKIM domain (बैंकको)<input name="dkim_domain" maxlength="120" placeholder="' . $e($ph['dkim']) . '"></label>'
       . '<label>हाम्रो खाताको अन्तिम ४–६ अंक<input name="account_hint" inputmode="numeric" maxlength="8" placeholder="9012"></label>'
       . '<label style="grid-column:1/-1">बैंकको sender (comma, optional)<input name="senders" maxlength="500" placeholder="' . $e($ph['senders']) . '"></label>'
       . '<div class="pc-btns" style="grid-column:1/-1;margin:0"><button class="btn" type="submit">➕ जोड्नुहोस् · Add</button></div>'
       . '</form></details></div></div>';
};

admin_header('पेमेन्ट म्यानेजमेन्ट · Payment Management', 'payment-center');
?>
<style>
.pc-wrap{display:grid;gap:14px;max-width:920px}
.pc-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}
.pc-tabs a{flex:0 0 auto;padding:10px 14px;border:1px solid var(--line,#e0e8f2);border-radius:999px;text-decoration:none;color:inherit;font-weight:700;min-height:44px;display:flex;align-items:center;background:var(--card,#fff)}
.pc-tabs a.on{background:#185bed;border-color:#185bed;color:#fff}
.pc-card{background:var(--card,#fff);color:var(--ink,#0b1220);border:1px solid var(--line,#e0e8f2);border-radius:14px;padding:14px 16px;min-width:0}
.pc-card h2{margin:0 0 4px;font-size:16px}.pc-card p.hint{margin:0 0 10px;color:var(--mut,#637491);font-size:12.5px}
.pc-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.pc-f label{font-size:12px;color:var(--mut,#637491);min-width:0}
.pc-f input,.pc-f select,.pc-f textarea{display:block;width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px;min-height:42px;font:inherit}
.pc-btns{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}.pc-btns .btn{min-height:44px}
.pc-flash{padding:9px 12px;border-radius:10px;overflow-wrap:anywhere}.pc-flash.ok{background:#e9f8ee;color:#0a7c2f}.pc-flash.bad{background:#fdecec;color:#b3261e}
.pc-warn{background:#fff4e5;color:#7a4b00;padding:8px 10px;border-radius:10px}
.pc-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13.5px}.pc-kv b{white-space:nowrap}
.pc-ok{color:#0a7c2f;font-weight:700}.pc-no{color:#b26a00;font-weight:700}
.pc-list{display:grid;gap:8px}
.pc-cr{border:1px solid var(--line,#e0e8f2);border-radius:12px;padding:10px 12px;min-width:0;overflow-wrap:anywhere}
.pc-cr-top b{font-size:16px}.pc-st{display:inline-block;border:1px solid;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700}
.pc-cr-meta,.pc-cr-note{font-size:12.5px;color:var(--mut,#637491);margin-top:4px}.pc-cr-note{color:#7a4b00}
.pc-cr-act{display:grid;gap:6px;margin-top:8px}
.pc-inline{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.pc-inline input[type=text],.pc-inline input:not([type]){flex:1 1 160px;min-width:0;padding:8px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px;min-height:42px;font:inherit}
.pc-chk{font-size:12px;display:flex;gap:6px;align-items:center}
.pc-sug{margin-top:8px;padding:10px;border:1px solid #b9cdfb;border-radius:12px;background:var(--hover,#f3f7ff)}
.pc-sug-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}
.pc-sug-grid div{min-width:0}.pc-sug-grid small{display:block;font-size:11.5px;color:var(--mut,#637491)}.pc-sug-grid b{display:block;font-size:15px;overflow-wrap:anywhere}
.pc-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.pc-chip{font-size:12px;font-weight:700;padding:3px 9px;border-radius:999px;border:1px solid}.pc-chip.ok{color:#0a7c2f;border-color:#0a7c2f}.pc-chip.no{color:#b3261e;border-color:#b3261e}
.pc-confirm{display:grid;gap:8px;margin-top:10px}
.pc-big{min-height:52px;font-size:17px;font-weight:800;width:100%}
.pc-auto{grid-column:1/-1;border:1px dashed var(--line,#e0e8f2);border-radius:10px;padding:8px 10px;font-size:13px;color:inherit}
.pc-auto p{margin:4px 0 0;font-size:12.5px;color:var(--mut,#637491)}
.pc-url{display:block;width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--line,#e0e8f2);border-radius:8px;font-size:13px}
.pc-tbl{width:100%;border-collapse:collapse;font-size:13px}.pc-tbl th,.pc-tbl td{padding:6px 6px;border-bottom:1px solid var(--line,#e0e8f2);text-align:right;white-space:nowrap}
.pc-tbl th:first-child,.pc-tbl td:first-child{text-align:left;white-space:normal}
.pc-scroll{overflow-x:auto;max-width:100%}
details.pc-adv summary{cursor:pointer;font-weight:700;margin:10px 0 6px}
.pc-chip.info{color:#185bed;border-color:#185bed}.pc-chip.warn{color:#8a5a00;border-color:#d99a00;background:#fff7e0}.pc-chip.mut{color:var(--mut,#637491);border-color:var(--line,#e0e8f2)}
.pc-acc{border:1px solid var(--line,#e0e8f2);border-radius:12px;padding:10px 12px;margin:0 0 10px;min-width:0;overflow-wrap:anywhere}
.pc-acc-top{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.pc-acc-mail{font-size:15px;min-width:0;overflow-wrap:anywhere}
.pc-acc-meta{font-size:12.5px;color:var(--mut,#637491);margin-top:4px}
.pc-acc-btns{margin-top:8px}.pc-acc-btns .btn{min-height:44px}
.pc-add{border-top:1px dashed var(--line,#e0e8f2);margin-top:6px;padding-top:10px;min-width:0}.pc-add h3{margin:0 0 8px;font-size:15px}
.pc-glogin{display:flex;align-items:center;justify-content:center;text-align:center;text-decoration:none;box-sizing:border-box;padding:10px 14px}
.pc-gsetup{margin-top:10px;min-width:0}
.pc-steps{margin:0 0 8px;padding-left:20px;font-size:13px;line-height:1.55}.pc-steps li{margin:0 0 6px;overflow-wrap:anywhere}
.pc-copy{display:flex;gap:6px;margin-top:6px;min-width:0}.pc-copy .pc-url{flex:1 1 auto;min-width:0}.pc-copy .btn{flex:0 0 auto;min-height:40px}
.pc-loglist{list-style:none;margin:0;padding:0;display:grid;gap:4px;font-size:12.5px}
.pc-loglist li{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border-bottom:1px solid var(--line,#e0e8f2);padding:4px 0;min-width:0}
.pc-subj{flex:1 1 160px;min-width:0;overflow-wrap:anywhere}
</style>
<script>
function pcCopy(b){var i=b.previousElementSibling;if(!i)return;i.select();try{if(navigator.clipboard){navigator.clipboard.writeText(i.value);}else{document.execCommand('copy');}b.textContent='✔ Copied';}catch(x){}}
</script>
<div class="pc-wrap">
  <nav class="pc-tabs" aria-label="Payment tabs">
    <a href="?tab=np" class="<?= $tab === 'np' ? 'on' : '' ?>">🇳🇵 नेपाली अनलाइन</a>
    <a href="?tab=in" class="<?= $tab === 'in' ? 'on' : '' ?>">🇮🇳 इन्डियन अनलाइन</a>
    <a href="?tab=cash" class="<?= $tab === 'cash' ? 'on' : '' ?>">💵 नगद</a>
  </nav>
  <?php foreach ($flash as [$t, $m]): ?><div class="pc-flash <?= $e($t) ?>"><?= $e($m) ?></div><?php endforeach; ?>
  <?php if (!$ready && $tab !== 'cash'): ?><div class="pc-warn">⚠️ Migration <b>upgrade-2026-11-25-mp1-pay-credits.sql</b> बाँकी — Gmail / bank API अहिले बन्द रहन्छ · runs quietly off until it is applied.</div><?php endif; ?>

<?php if ($tab === 'np'): $qrNow = Ticket::esewaQrFile(); $autoOn = PayCredits::gmailAutoOn(); ?>
  <div class="pc-card pc-note" role="note">
    <h2>💡 Gmail बाट आएको पैसा · Gmail credits</h2>
    <?php if ($autoOn): ?>
    <p class="hint" style="margin:0">Gmail Auto-PAID <b class="pc-ok">चालु · ON</b> छ: सबै जाँच मिलेको credit आफैं PAID हुन्छ; नमिलेको तल "व्यक्ति चाहिन्छ" मा आउँछ।</p>
    <?php else: ?>
    <p class="hint" style="margin:0">Gmail बाट आएको पैसा अहिले सुझावको रूपमा आउँछ; बैंक app मा हेरेर Confirm थिच्नुहोस्। Global IME को साँचो alert email को नमुना मिलाएपछि Auto-PAID खोल्न सकिन्छ। <span class="pc-no">(Auto-PAID: बन्द · OFF)</span></p>
    <?php endif; ?>
    <div class="pc-auto" id="pc-auto-state" style="margin-top:8px">
      <b>Gmail Auto-PAID</b> <span class="<?= $autoOn ? 'pc-ok' : 'pc-no' ?>"><?= $autoOn ? '● चालु · ON' : '○ बन्द · OFF' ?></span> <small class="muted">(पढ्न मात्र · read-only · <code>pay_bankmail_autoconfirm_on</code>)</small>
      <p><?= $autoOn
          ? 'सबै जाँच मिलेको Gmail credit आफैं PAID हुन्छ (PNR भएको मात्र; "रकम मात्र" सधैं सुझाव)। बन्द गर्न: Settings → Payments → pay_bankmail_autoconfirm_on।'
          : 'बन्द: सबै मिलेको credit 💡 सुझाव मात्र हुन्छ, टिकट PAID हुँदैन — बैंक app मा हेरेर तल Confirm थिच्नुहोस्। यहाँबाट खोलिँदैन; साँचो Global IME alert को नमुना मिलाएपछि मात्र Settings → Payments मा खोल्नुहोस्।' ?></p>
    </div>
  </div>
  <form method="post" enctype="multipart/form-data" class="pc-card">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="np_qr"><input type="hidden" name="tab" value="np">
    <h2>🇳🇵 नेपाली QR · Nepal eSewa / Fonepay / bank QR</h2>
    <p class="hint">यही एउटा QR टिकट, pay पेज र QR पेजमा देखिन्छ। Customer लाई <b>Remarks मा PNR</b> (जस्तै SHG-2026-00842) लेख्न भन्नुहोस्।</p>
    <?php if ($qrNow !== ''): ?>
      <p><img src="data:<?= $e(mime_content_type($qrNow) ?: 'image/png') ?>;base64,<?= base64_encode((string) file_get_contents($qrNow)) ?>" alt="Nepal QR" style="width:160px;max-width:100%;height:auto;border:1px solid #e2e8f0;border-radius:10px"></p>
    <?php else: ?><p class="hint"><b>अहिले कुनै QR छैन · no QR yet.</b></p><?php endif; ?>
    <div class="pc-f"><label>QR फोटो (PNG / JPG / WebP, 3 MB)<input type="file" name="esewa_qr" accept="image/png,image/jpeg,image/webp"></label></div>
    <div class="pc-btns"><button class="btn btn-orange" type="submit">अपलोड · Upload</button>
      <?php if ($val('nepal_esewa_qr') !== ''): ?><button class="btn" type="submit" name="remove" value="1">हटाउ · Remove</button><?php endif; ?></div>
  </form>

  <form method="post" class="pc-card" autocomplete="off">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="np_save"><input type="hidden" name="tab" value="np">
    <h2>🪪 eSewa / bank ID</h2>
    <div class="pc-f">
      <label>eSewa / bank ID<input name="nepal_esewa_id" value="<?= $e($val('nepal_esewa_id')) ?>" placeholder="98XXXXXXXX"></label>
      <label>eSewa / खाता नाम · name<input name="nepal_esewa_name" value="<?= $e($val('nepal_esewa_name')) ?>"></label>
    </div>
    <div class="pc-btns"><button class="btn" type="submit">सेभ · Save</button></div>
  </form>

  <?php $mailCard('NP'); ?>
  <div class="pc-kv pc-card" style="margin-top:-4px">
    <b>Last run</b><span><?= $lastPoll !== [] ? $e(($lastPoll['at'] ?? '') . ' · ' . (!empty($lastPoll['ok']) ? '✔ ' : '✖ ') . ($lastPoll['msg'] ?? '')) : '—' ?></span>
    <b>Cron</b><span><code>cron/bank-mail.php</code> हरेक २ मिनेट · every 2 min</span>
  </div>

  <div class="pc-card">
    <h2>🏦 Credits (Gmail) · NPR</h2>
    <p class="hint"><b>💡 सुझाव</b>: बैंक app मा पैसा आएको हेरेर "✅ Confirm" थिच्नुहोस् (Payment risk जाँच पनि हुन्छ)। <b>रकम मात्र</b>: alert मा PNR थिएन — नाम / फोन मिलाउनुहोस्। "Link to PNR": रकम ठ्याक्कै मिल्नुपर्छ। Ignore: हाम्रो होइन / refund / बैंक app मा छैन।</p>
    <?php $credits('NPR'); ?>
  </div>

<?php elseif ($tab === 'in'): $apiOn = BankApi::on(); $map = BankApi::fieldMap(); ?>
  <div class="pc-card pc-note" role="note">
    <p class="hint" style="margin:0"><b>💡</b> बैंक API credential नआउन्जेल Gmail bank alert बाट पैसा आएको हेर्न सकिन्छ (सुझाव + Confirm)। · Until the bank API credentials come, Indian bank alerts in Gmail show the money (suggestion + Confirm).</p>
  </div>
  <?php $mailCard('IN'); ?>
  <form method="post" class="pc-card" autocomplete="off">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="in_save"><input type="hidden" name="tab" value="in">
    <h2>🇮🇳 UPI + bank API</h2>
    <p class="hint">UPI ID र नाम = कम्पनीको अहिलेकै UPI (टिकट र pay पेजमा)। Bank API: bank ले दिएको Merchant ID, key, secret र webhook secret। <b>Sandbox</b> मा आएको payment "TEST" हुन्छ, कहिल्यै टिकट PAID गर्दैन। Secret एकपटक सेभ भएपछि देखाइँदैन (खाली छोडे पुरानै)।</p>
    <div class="pc-f">
      <label>UPI ID (VPA)<input name="upi_id" value="<?= $e($val('upi_id')) ?>" placeholder="shreehari@icici"></label>
      <label>UPI नाम · payee name<input name="upi_name" value="<?= $e($val('upi_name')) ?>"></label>
      <label>Bank नाम<input name="bankapi_bank_name" value="<?= $e($val('bankapi_bank_name')) ?>" placeholder="ICICI / HDFC / Axis…"></label>
      <label>Merchant ID<input name="bankapi_merchant_id" value="<?= $e($val('bankapi_merchant_id')) ?>"></label>
      <?php foreach (['bankapi_key' => 'API key', 'bankapi_secret' => 'API secret', 'bankapi_webhook_secret' => 'Webhook secret'] as $sk => $sl): ?>
      <label><?= $e($sl) ?> <?= $val($sk) !== '' ? '<span class="pc-ok">✔ saved</span>' : '' ?><input name="<?= $e($sk) ?>" type="password" value="" autocomplete="new-password" placeholder="<?= $val($sk) !== '' ? '•••• (बदल्न मात्र लेख्नुहोस्)' : '' ?>"></label>
      <?php endforeach; ?>
      <label>Mode<select name="bankapi_mode"><option value="sandbox" <?= BankApi::mode() === 'sandbox' ? 'selected' : '' ?>>Sandbox (test)</option><option value="live" <?= BankApi::mode() === 'live' ? 'selected' : '' ?>>Live (असली पैसा)</option></select></label>
    </div>
    <details class="pc-adv"><summary>⚙️ Advanced · signature र field map</summary>
      <div class="pc-f">
        <label>Signature header<input name="bankapi_sig_header" value="<?= $e($val('bankapi_sig_header', 'X-Signature')) ?>"></label>
        <label>Signature format<select name="bankapi_sig_format"><option value="hex" <?= $val('bankapi_sig_format', 'hex') !== 'base64' ? 'selected' : '' ?>>hex</option><option value="base64" <?= $val('bankapi_sig_format') === 'base64' ? 'selected' : '' ?>>base64</option></select></label>
        <label>Timestamp header (5 min)<input name="bankapi_ts_header" value="<?= $e($val('bankapi_ts_header', 'X-Timestamp')) ?>"></label>
        <label>API key header<input name="bankapi_key_header" value="<?= $e($val('bankapi_key_header', 'X-Api-Key')) ?>"></label>
        <label>Status URL (Test, optional)<input name="bankapi_status_url" value="<?= $e($val('bankapi_status_url')) ?>" placeholder="https://…"></label>
        <label style="grid-column:1/-1">Field map (JSON dot-paths)<textarea name="bankapi_field_map" rows="4" spellcheck="false"><?= $e(json_encode($map, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) ?></textarea></label>
      </div>
    </details>
    <p class="hint" style="margin-top:10px">Bank लाई दिने webhook address:</p>
    <input class="pc-url" readonly value="<?= $e(BankApi::webhookUrl()) ?>" onclick="this.select()">
    <div class="pc-btns">
      <button class="btn" type="submit">सेभ · Save</button>
      <?php if ($apiOn): ?><button class="btn" type="submit" name="enable" value="0">⏸ बन्द · Turn off</button>
      <?php else: ?><button class="btn btn-orange" type="submit" name="enable" value="1">Save &amp; Enable</button><?php endif; ?>
      <button class="btn" type="submit" name="act" value="in_test" formnovalidate>🔌 Test</button>
    </div>
    <div class="pc-kv" style="margin-top:10px">
      <b>Status</b><span class="<?= $apiOn ? 'pc-ok' : 'pc-no' ?>"><?= $apiOn ? '● चालु · ON (' . $e(BankApi::mode()) . ')' : '○ बन्द · OFF' ?></span>
      <b>Last webhook</b><span><?= $lastHook !== [] ? $e(($lastHook['at'] ?? '') . ' · HTTP ' . ($lastHook['code'] ?? '') . ' · ' . ($lastHook['state'] ?? '') . (($lastHook['note'] ?? '') !== '' ? ' · ' . $lastHook['note'] : '')) : '—' ?></span>
      <b>ICICI PG</b><span>अलग्गै चल्छ (Owner setup) · unchanged</span>
    </div>
  </form>

  <div class="pc-card">
    <h2>🏦 Credits (INR · bank API + Gmail)</h2>
    <?php $credits('INR'); ?>
  </div>

<?php else:
    /* ---------------- 💵 cash, read-only ---------------- */
    require_once INCLUDE_PATH . '/counterbook.php';
    require_once INCLUDE_PATH . '/countershift.php';
    $day = Security::isValidDate((string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : todayISO();
    $t1  = addDaysISO($day, 1);
    $book = ['rows' => [], 'totals' => []];
    try { $book = CounterBook::day($day); } catch (Throwable $ex) { $flash[] = ['bad', 'Counter book: ' . $ex->getMessage()]; }
    $closes = [];
    foreach (CounterShift::dayCloses($day) as $dc) { $closes[(string) $dc['desk_key']] = $dc; }

    /* per person: who verified the cash (whose drawer / bag), handed over (agent ledger), shift closes */
    $people = [];
    $p = static function (int $id) use (&$people): void {
        $people[$id] ??= ['counter' => 0.0, 'boarding' => 0.0, 'handed' => 0.0, 'shift' => [], 'n' => 0];
    };
    $tw = ['f' => $day, 't1' => $t1];
    foreach (Database::fetchAll(
        "SELECT COALESCE(p.verified_by, 0) AS who, b.is_cod, COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS amt
           FROM payments p JOIN bookings b ON b.id = p.booking_id
          WHERE p.status = 'verified' AND p.method IN ('cash','cod')
            AND p.verified_at >= :f AND p.verified_at < :t1
            AND COALESCE(p.gateway_payload, '') NOT LIKE '%\"cod_holder\":\"online\"%'
          GROUP BY who, b.is_cod", $tw) as $r) {
        $id = (int) $r['who'];
        $p($id);
        $people[$id][(int) $r['is_cod'] === 1 ? 'boarding' : 'counter'] += (float) $r['amt'];
        $people[$id]['n'] += (int) $r['n'];
    }
    try {
        foreach (Database::fetchAll(
            "SELECT agent_admin_id AS who, COALESCE(SUM(ABS(amount)), 0) AS amt FROM agent_ledger
              WHERE entry_type = 'cash_handover' AND created_at >= :f AND created_at < :t1 GROUP BY agent_admin_id", $tw) as $r) {
            $p((int) $r['who']);
            $people[(int) $r['who']]['handed'] += (float) $r['amt'];
        }
    } catch (Throwable $ex) {
        // no ledger table on a bare database
    }
    try {
        foreach (Database::fetchAll(
            "SELECT admin_id, counted_cash, closed_at, " . (CounterDesk::hasColumn('counter_shifts', 'handover_to') ? 'handover_to' : "NULL AS handover_to") . "
               FROM counter_shifts WHERE closed_at >= :f AND closed_at < :t1 ORDER BY closed_at", $tw) as $r) {
            $p((int) $r['admin_id']);
            $people[(int) $r['admin_id']]['shift'][] = inr((float) $r['counted_cash']) . (!empty($r['handover_to']) ? ' → ' . $r['handover_to'] : '');
        }
    } catch (Throwable $ex) {
        // shifts not set up
    }
    $names = [];
    $ids = array_filter(array_keys($people));
    if ($ids !== []) {
        foreach (Database::fetchAll('SELECT id, full_name, username, role FROM admins WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $a) {
            $names[(int) $a['id']] = trim((string) (($a['full_name'] ?? '') ?: $a['username'])) . ' · ' . $a['role'];
        }
    }
    $tot = ['counter' => 0.0, 'boarding' => 0.0, 'handed' => 0.0];
?>
  <form method="get" class="pc-card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="tab" value="cash">
    <label style="font-size:12px;color:var(--mut,#637491)">मिति · Day<input type="date" name="day" value="<?= $e($day) ?>" style="display:block;min-height:42px;padding:6px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px"></label>
    <button class="btn" type="submit" style="min-height:44px">हेर्नुहोस् · Show</button>
    <span class="muted" style="font-size:12px">पढ्न मात्र · read-only. सच्याउन तलका पेज।</span>
  </form>

  <div class="pc-card">
    <h2>🏪 काउन्टर अनुसार · Per counter / desk</h2>
    <p class="hint">नगद = काउन्टर नगद + बसमा लिएको (COD), जसले verify गर्‍यो उसको desk मा। NPR desk को drawer NPR मा।</p>
    <div class="pc-scroll"><table class="pc-tbl">
      <thead><tr><th>Desk</th><th>टिकट</th><th>नगद ₹</th><th>Drawer</th><th>अनलाइन ₹</th><th>बसमा बाँकी</th><th>Day close</th></tr></thead><tbody>
      <?php foreach ($book['rows'] as $k => $r): $dc = $closes[(string) $k] ?? null; ?>
        <tr><td><?= $e($r['label'] ?? $k) ?></td><td><?= (int) $r['tickets'] ?></td><td><?= $e(inr((float) $r['cash'])) ?></td>
          <td><?= $e(($r['currency'] ?? 'INR') === 'INR' ? inr((float) $r['cashLocal']) : ($r['currency'] . ' ' . number_format((float) $r['cashLocal']))) ?></td>
          <td><?= $e(inr((float) $r['online'])) ?></td><td><?= $e(inr((float) $r['pending'])) ?><?= (int) $r['pendingN'] > 0 ? ' (' . (int) $r['pendingN'] . ')' : '' ?></td>
          <td><?= $dc !== null ? $e(number_format((float) $dc['counted_cash']) . ' ' . $dc['currency'] . ' · ' . ((float) $dc['variance'] == 0.0 ? '✔' : ((float) $dc['variance'] > 0 ? '+' : '') . number_format((float) $dc['variance']))) : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($book['rows'] === []): ?><tr><td colspan="7" class="muted">यो दिन कुनै बिक्री / नगद छैन · nothing on this day.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($book['rows'] !== []): ?><tfoot><tr><th>जम्मा · Total</th><th><?= (int) ($book['totals']['tickets'] ?? 0) ?></th><th><?= $e(inr((float) ($book['totals']['cash'] ?? 0))) ?></th><th></th><th><?= $e(inr((float) ($book['totals']['online'] ?? 0))) ?></th><th><?= $e(inr((float) ($book['totals']['pending'] ?? 0))) ?></th><th></th></tr></tfoot><?php endif; ?>
    </table></div>
  </div>

  <div class="pc-card">
    <h2>👤 व्यक्ति अनुसार · Per staff</h2>
    <p class="hint">काउन्टर नगद · बसमा लिएको नगद (COD, online holder बाहेक) · हस्तान्तरण (agent ledger cash handover) · बाँकी = नगद − हस्तान्तरण। Shift close = गनेको नगद → कसलाई।</p>
    <div class="pc-scroll"><table class="pc-tbl">
      <thead><tr><th>Staff</th><th>काउन्टर</th><th>बसमा</th><th>हस्तान्तरण</th><th>बाँकी</th><th>Shift close</th></tr></thead><tbody>
      <?php foreach ($people as $id => $r): $bal = $r['counter'] + $r['boarding'] - $r['handed'];
            $tot['counter'] += $r['counter']; $tot['boarding'] += $r['boarding']; $tot['handed'] += $r['handed']; ?>
        <tr><td><?= $e($id === 0 ? 'Office / system' : ($names[$id] ?? ('#' . $id))) ?></td><td><?= $e(inr($r['counter'])) ?></td><td><?= $e(inr($r['boarding'])) ?></td>
          <td><?= $e(inr($r['handed'])) ?></td><td><b><?= $e(inr($bal)) ?></b></td><td><?= $r['shift'] !== [] ? $e(implode(' · ', $r['shift'])) : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if ($people === []): ?><tr><td colspan="6" class="muted">यो दिन नगद आएको छैन · no cash on this day.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($people !== []): ?><tfoot><tr><th>जम्मा · Total</th><th><?= $e(inr($tot['counter'])) ?></th><th><?= $e(inr($tot['boarding'])) ?></th><th><?= $e(inr($tot['handed'])) ?></th><th><?= $e(inr($tot['counter'] + $tot['boarding'] - $tot['handed'])) ?></th><th></th></tr></tfoot><?php endif; ?>
    </table></div>
  </div>

  <div class="pc-card">
    <h2>📚 पूरा हिसाब · The books</h2>
    <div class="pc-btns">
      <a class="btn" href="cash-book.php?from=<?= $e($day) ?>&amp;to=<?= $e($day) ?>&amp;status=all">Cash Book</a>
      <a class="btn" href="shift.php">Shift &amp; Cash Handover</a>
      <a class="btn" href="counters.php">Counters</a>
      <a class="btn" href="settlements.php">Settlements</a>
      <a class="btn" href="accounting.php">Accounting</a>
      <a class="btn" href="payments.php">Verify Payments</a>
    </div>
  </div>
<?php endif; ?>
</div>
<?php admin_footer(); ?>
