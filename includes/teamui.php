<?php
/**
 * includes/teamui.php — the frame of the /team/ pages (22 Oct 2026): one
 * light / dark design for the team portal, phone first, no app shell (the
 * pages are plain PHP so they work on any phone without the SPA).
 */

declare(strict_types=1);

final class TeamUi
{
    private function __construct() {}

    public static function head(string $title, string $active = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header('X-Frame-Options: DENY');
        $h = static fn($v): string => Security::e((string) $v);
        ?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0a2a8f">
<meta name="csrf-token" content="<?= $h(Security::csrfToken()) ?>">
<link rel="icon" href="/favicon.ico">
<title><?= $h($title) ?> · S Hari Global Team</title>
<style>
:root{--bg:#f3f6fb;--card:#fff;--ink:#16233c;--muted:#5c6b85;--line:#e4e9f1;--soft:#f6f8fc;--royal:#0a2a8f;--royal2:#1f4fe0;--orange:#f07a1e;--ok:#0f9d6b;--bad:#d93025;--chip:#ffe4c7;--chipink:#9a4a00}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#131d30;--ink:#e8eef8;--muted:#a9b6cb;--line:#24324b;--soft:#0f1828;--chip:#3a2a14;--chipink:#ffc98a}}
*{box-sizing:border-box}[hidden]{display:none!important}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.45}
a{color:var(--royal2)}
.tp-top{display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(135deg,var(--royal),var(--royal2));color:#fff}
.tp-top img{height:40px;width:auto;background:#fff;border-radius:10px;padding:3px}
.tp-top b{display:block;font-size:16px;letter-spacing:.02em}.tp-top small{display:block;font-size:11.5px;color:#ffd7a0;font-weight:700;letter-spacing:.06em}
.tp-top .tp-sp{flex:1}.tp-top a{color:#fff;text-decoration:none;font-weight:700;font-size:13px}
.tp-w{max-width:560px;margin:0 auto;padding:16px}
.tp-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;box-shadow:0 10px 30px -22px rgba(10,42,143,.55);margin-bottom:14px}
h1{font-size:21px;margin:0 0 6px;line-height:1.25}h2{font-size:17px;margin:0 0 10px}
.tp-sub{color:var(--muted);margin:0 0 14px;font-size:14px}
label.f{display:block;font-weight:700;font-size:13.5px;margin:12px 0 6px}
input.i,select.i,textarea.i{width:100%;min-height:48px;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line);background:var(--soft);color:var(--ink);font:inherit;font-size:16px}
textarea.i{min-height:84px}
input.i:focus,select.i:focus,textarea.i:focus{outline:none;border-color:var(--royal2);box-shadow:0 0 0 3px rgba(31,79,224,.18)}
.b{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:52px;margin-top:16px;border:0;border-radius:14px;font:inherit;font-weight:900;font-size:16px;color:#fff;cursor:pointer;text-decoration:none;
  background:linear-gradient(120deg,var(--royal2),var(--royal));box-shadow:0 4px 0 #061b5c}
.b:active{transform:translateY(3px);box-shadow:0 1px 0 #061b5c}
.b.or{background:linear-gradient(120deg,#ffa24d,#f07a1e 50%,#d4570c);box-shadow:0 4px 0 #9c3f06}
.b.wa{background:#1faa59;box-shadow:0 4px 0 #127a3e}
.b.gh{background:transparent;color:var(--royal2);border:1.5px solid var(--line);box-shadow:none}
.tp-msg{padding:12px 14px;border-radius:12px;margin-bottom:12px;font-weight:700;font-size:14px}
.tp-msg.ok{background:rgba(15,157,107,.12);color:var(--ok);border:1px solid rgba(15,157,107,.35)}
.tp-msg.bad{background:rgba(217,48,37,.1);color:var(--bad);border:1px solid rgba(217,48,37,.3)}
.tp-msg.info{background:rgba(31,79,224,.08);color:var(--royal2);border:1px solid rgba(31,79,224,.25)}
.tp-links{display:flex;flex-wrap:wrap;gap:8px 16px;justify-content:center;margin-top:14px;font-size:14px;font-weight:700}
.tp-me{display:flex;gap:14px;align-items:center}
.tp-av{flex:0 0 72px;width:72px;height:72px;border-radius:18px;object-fit:cover;border:3px solid var(--orange);background:#fff}
.tp-ini{flex:0 0 72px;width:72px;height:72px;border-radius:18px;display:grid;place-items:center;font-size:28px;font-weight:900;color:var(--royal);background:#eef4fc;border:3px solid var(--orange)}
.tp-chip{display:inline-block;margin:4px 6px 0 0;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800;background:var(--chip);color:var(--chipink)}
.tp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.tp-tile{display:flex;flex-direction:column;gap:4px;min-height:104px;padding:14px;border-radius:16px;text-decoration:none;color:var(--ink);background:var(--card);border:1px solid var(--line);box-shadow:0 6px 18px -14px rgba(10,42,143,.6)}
.tp-tile i{font-style:normal;font-size:26px}.tp-tile b{font-size:15px}.tp-tile small{color:var(--muted);font-size:12.5px}
.tp-tile.hot{background:linear-gradient(135deg,var(--royal),var(--royal2));color:#fff;border:0}.tp-tile.hot small{color:#dbe5ff}
.tp-row{display:grid;grid-template-columns:1.4fr 1fr .6fr;gap:6px;margin-bottom:6px}
.tp-row3{display:grid;grid-template-columns:1.2fr 1fr .5fr;gap:6px;margin-bottom:6px}
.muted{color:var(--muted)}.mono{font-family:ui-monospace,Menlo,Consolas,monospace}
.cv-dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px}.cv-dl dt{color:var(--muted)}.cv-dl dd{margin:0;font-weight:700}
.cv-ul{margin:0 0 6px;padding-left:18px}
h3{font-size:15px;margin:14px 0 6px;color:var(--royal2)}
.tp-foot{text-align:center;color:var(--muted);font-size:12px;padding:6px 16px 24px}
@media (max-width:380px){.tp-row,.tp-row3{grid-template-columns:1fr}}
@media print{.tp-top,.tp-noprint,.tp-foot{display:none!important}body{background:#fff}.tp-card{box-shadow:none;border:0}}
</style>
</head>
<body>
<header class="tp-top">
  <img src="/assets/img/logo-mark-96h.png" alt="">
  <div><b>S HARI GLOBAL™</b><small>टिम पोर्टल · TEAM PORTAL</small></div>
  <span class="tp-sp"></span>
  <?php if ($active !== 'home'): ?><a href="/team/">🏠 Home</a><?php endif; ?>
</header>
<main class="tp-w">
<?php
    }

    public static function foot(): void
    {
        $co = Settings::company();
        ?>
</main>
<p class="tp-foot">समस्या भए · Need help? <a href="tel:<?= Security::e(preg_replace('/[^\d+]/', '', (string) $co['phone']) ?? '') ?>"><?= Security::e($co['phone']) ?></a> · <a href="/">shreehariglobal.in</a></p>
</body>
</html>
<?php
    }

    /** One flash line from the session, shown once. */
    public static function flash(): void
    {
        $f = $_SESSION['team_flash'] ?? null;
        unset($_SESSION['team_flash']);
        if (is_array($f) && isset($f[0], $f[1])) {
            echo '<div class="tp-msg ' . ($f[0] === 'ok' ? 'ok' : ($f[0] === 'info' ? 'info' : 'bad')) . '" role="status">' . Security::e((string) $f[1]) . '</div>';
        }
    }

    public static function setFlash(string $kind, string $text): void
    {
        $_SESSION['team_flash'] = [$kind, $text];
    }

    public static function go(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
