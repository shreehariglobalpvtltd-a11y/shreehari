<?php
/**
 * admin/login.php — staff sign-in.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$base = rtrim(APP_URL, '/');

// Already signed in? Go straight to the dashboard.
if (Auth::isAdmin()) {
    Response::redirect('admin/index.php');
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = Auth::adminLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            Response::redirect('admin/index.php');
        }
        $error = $result['error'] ?? 'Sign in failed.';
    }
}

$company = Settings::getString('company_name', APP_NAME);
$csrf    = Security::e(Security::csrfToken());
$csrfKey = CSRF_TOKEN_NAME;
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin sign in · <?= Security::e($company) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
     font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
     background:linear-gradient(135deg,#12264E,#2E5FA8)}
.box{background:#fff;width:100%;max-width:380px;margin:20px;padding:34px 30px;border-radius:18px;
     box-shadow:0 24px 60px rgba(0,0,0,.35)}
.logo{font-size:34px;text-align:center}
h1{font-size:19px;text-align:center;margin:8px 0 2px;color:#12264E}
.sub{text-align:center;color:#6b7688;font-size:13px;margin-bottom:22px}
label{display:block;font-size:13px;font-weight:700;color:#1b2436;margin:14px 0 6px}
input{width:100%;padding:12px 14px;border:1px solid #dde3ee;border-radius:10px;font-size:15px}
input:focus{outline:none;border-color:#2E5FA8;box-shadow:0 0 0 3px rgba(46,95,168,.15)}
button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;background:#F07C1F;color:#fff;
       font-weight:800;font-size:15px;cursor:pointer}
button:hover{filter:brightness(1.05)}
.err{background:#f7dcdc;color:#8a1f1f;padding:11px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:8px}
.foot{text-align:center;margin-top:18px;font-size:12px;color:#9aa4b5}
.foot a{color:#2E5FA8;text-decoration:none}
</style></head>
<body>
<form class="box" method="post" autocomplete="off">
  <div class="logo">🚌</div>
  <h1><?= Security::e($company) ?></h1>
  <div class="sub">Staff administration</div>
  <?php if ($error !== ''): ?><div class="err"><?= Security::e($error) ?></div><?php endif; ?>
  <input type="hidden" name="<?= $csrfKey ?>" value="<?= $csrf ?>">
  <label for="u">Username</label>
  <input id="u" name="username" required autofocus value="<?= Security::e($_POST['username'] ?? '') ?>">
  <label for="p">Password</label>
  <input id="p" name="password" type="password" required>
  <button type="submit">Sign in</button>
  <div class="foot"><a href="<?= $base ?>/">← Back to website</a></div>
</form>
</body></html>
