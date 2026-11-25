<?php
/**
 * admin/gmail-oauth.php — "🔐 Google बाट Login गर्नुहोस् (browser जस्तै)" for a bank Gmail box
 * (25 Nov 2026, mp2 "bank Gmail"). Super-admin only.
 *
 *   ?start=1&country=NP|IN[&account=<id>]  - from Payment Management: a fresh single-use state
 *        (+ PKCE) in the session, then the browser goes to Google's sign-in (GoogleOAuth::start).
 *        GET is fine: it only redirects (nothing is stored but the session's own state).
 *   ?state=…&code=… | ?state=…&error=…     - Google sends the browser back here: the state is
 *        checked + consumed, the code swapped, the box stored with sealed tokens
 *        (GoogleOAuth::finish), and the browser goes back to Payment Management with the result.
 *
 * Tokens are never printed. This is also the "Authorized redirect URI" the owner pastes into
 * Google Cloud (GoogleOAuth::redirectUri, shown with a Copy button on Payment Management).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/bankmail.php';   // + BankMailAccounts, GoogleOAuth, SecretBox

$admin = admin_boot('payments.view');
$e     = static fn($v): string => Security::e((string) $v);
if (!Auth::isSuperadmin()) {
    http_response_code(403);
    admin_header('Bank Gmail', 'payment-center');
    echo '<div class="card" style="max-width:560px;padding:16px">🔒 बैंक Gmail जोड्ने काम सुपर-एडमिनको मात्र हो · Connecting a bank Gmail is for the super-admin only.</div>';
    admin_footer();
    exit;
}
$me = (int) $admin['id'];

/** Back to Payment Management with one message (shown once there). */
$back = static function (string $country, string $kind, string $msg) use ($e): never {
    $_SESSION['pc_flash'] = [[$kind === 'ok' ? 'ok' : 'bad', $msg]];
    $to = '/admin/payment-center.php?tab=' . BankMailAccounts::tabFor($country) . '#pc-mail-' . ($country === 'IN' ? 'IN' : 'NP');
    header('Location: ' . $to, true, 303);
    echo '<!doctype html><meta charset="utf-8"><p>' . $e($msg) . '</p><p><a href="' . $e($to) . '">Payment Management →</a></p>';
    exit;
};

if (isset($_GET['start'])) {
    $country = (string) ($_GET['country'] ?? 'NP') === 'IN' ? 'IN' : 'NP';
    $acct = null;
    if ((int) ($_GET['account'] ?? 0) > 0) {
        $acct = BankMailAccounts::get((int) $_GET['account']);
        if ($acct === null) {
            $back($country, 'bad', 'फेरि जोड्ने Gmail खाता भेटिएन · account not found.');
        }
        $country = (string) $acct['country'] === 'IN' ? 'IN' : 'NP';
    }
    if (!BankMailAccounts::ready()) {
        $back($country, 'bad', 'Migration upgrade-2026-11-25-mp2-bank-gmail.sql बाँकी · run the migration first.');
    }
    if (!SecretBox::available()) {
        $back($country, 'bad', SecretBox::whyNot());
    }
    if (!GoogleOAuth::configured()) {
        $back($country, 'bad', 'पहिले Google Client ID र Client Secret सेभ गर्नुहोस् (तलको setup box) · save the Google client first.');
    }
    $url = GoogleOAuth::start($country, $acct, $_SESSION, $me);
    header('Location: ' . $url, true, 302);
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><title>Google</title><p><a id="go" href="' . $e($url) . '">Google बाट Login गर्न यहाँ थिच्नुहोस् · continue to Google</a></p>';
    exit;
}

if (isset($_GET['state']) || isset($_GET['code']) || isset($_GET['error'])) {
    $r = GoogleOAuth::finish($_GET, $_SESSION, $me);
    if (!$r['ok']) {
        Logger::warning('Bank Gmail Google sign-in refused', ['why' => mb_substr($r['msg'], 0, 160), 'admin' => $me], 'payment');
    }
    $back($r['country'], $r['ok'] ? 'ok' : 'bad', $r['msg']);
}

$back('NP', 'bad', 'Payment Management बाट "🔐 Google बाट Login" थिच्नुहोस् · start from Payment Management.');
