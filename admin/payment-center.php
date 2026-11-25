<?php
/**
 * admin/payment-center.php — पेमेन्ट म्यानेजमेन्ट · Payment Management (25 Nov 2026, mp1 "pay").
 *
 * Owner plan, section 1. Super-admin only. Three tabs:
 *   🇳🇵 नेपाली अनलाइन — the Nepal eSewa / bank QR (the same picture the ticket prints, upload
 *        logic = owner-setup.php's), eSewa id + name, the Gmail box that receives the Global IME
 *        Bank credit alerts (BankMail), Save & Enable, Test connection, last poll, credits table
 *        with 1-click "Link to PNR" and Ignore.
 *   🇮🇳 इन्डियन अनलाइन — UPI id / name (the company's existing upi_id / upi_name), the bank API
 *        (merchant id, key, secret, webhook secret, sandbox / live, signature header / format,
 *        field map), the webhook address to give the bank, Save & Enable, Test, last webhook,
 *        credits table.
 *   💵 नगद — read-only cash for one day: per counter (CounterBook, the day close) and per
 *        person (counter cash, boarding cash, handed over, balance), with links to the books.
 *
 * Every POST is CSRF-checked and audited; a secret is never shown back (blank keeps it).
 * Money is confirmed ONLY through PayCredits (BookingService gates) - never here directly.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/bankmail.php';
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
        $tab = 'np';
        $bad = [];
        if (trim((string) ($_POST['bankmail_user'] ?? '')) !== '' && filter_var(trim((string) $_POST['bankmail_user']), FILTER_VALIDATE_EMAIL) === false) {
            $bad[] = 'Gmail ठेगाना मिलेन · not an e-mail address';
        }
        foreach (array_filter(array_map('trim', explode(',', (string) ($_POST['bankmail_from'] ?? '')))) as $f) {
            if (preg_match('/^(@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/', $f) !== 1) {
                $bad[] = 'Sender "' . $f . '": ठेगाना वा @domain चाहिन्छ';
            }
        }
        $dk = trim((string) ($_POST['bankmail_dkim_domain'] ?? ''));
        if ($dk !== '' && preg_match('/^[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $dk) !== 1) {
            $bad[] = 'DKIM domain: जस्तै gimebank.com';
        }
        $host = trim((string) ($_POST['bankmail_host'] ?? ''));
        if ($host !== '' && preg_match('/^[A-Za-z0-9.\-]+$/', $host) !== 1) {
            $bad[] = 'IMAP host मिलेन';
        }
        if ($bad !== []) {
            foreach ($bad as $b) { $flash[] = ['bad', $b]; }
        } else {
            $changed = $saveKeys([
                'nepal_esewa_id' => ['text', 80], 'nepal_esewa_name' => ['text', 120],
                'bankmail_host' => ['text', 120], 'bankmail_user' => ['text', 190], 'bankmail_app_password' => ['secret', 80],
                'bankmail_from' => ['text', 500], 'bankmail_dkim_domain' => ['text', 120], 'pay_bankmail_autoconfirm_on' => ['bool', 0],
            ]);
            $want = (string) ($_POST['enable'] ?? '');
            if ($want === '1' || $want === '0') {
                $cfg = BankMail::config();
                if ($want === '1' && ($cfg['user'] === '' || $cfg['pass'] === '' || $cfg['from'] === [] || $cfg['dkim'] === '' || !$ready)) {
                    $flash[] = ['bad', 'चालु गर्न Gmail, app password, sender र DKIM domain चाहिन्छ' . ($ready ? '' : ' (र migration)') . ' · fill all four first'];
                } elseif ($want !== (BankMail::on() ? '1' : '0')) {
                    Settings::set('bankmail_on', $want, 'bool', 'payments');
                    Settings::flush();
                    $changed[] = 'bankmail_on=' . $want;
                }
            }
            if ($changed !== []) {
                Logger::audit('settings.payment_center', 'settings', 'payment-center', null, ['tab' => 'np', 'changed' => $changed], 'Payment Management (Nepal)');
                $flash[] = ['ok', 'सेभ भयो · saved: ' . implode(', ', $changed)];
            } elseif (!array_filter($flash, static fn($f) => $f[0] === 'bad')) {
                $flash[] = ['ok', 'केही बदलिएन · nothing changed'];
            }
        }
    } elseif ($act === 'np_test') {
        $tab = 'np';
        $r = BankMail::testConnection();
        Logger::audit('payment_center.test', 'settings', 'bankmail', null, ['ok' => $r['ok']], 'Gmail test connection');
        $flash[] = [$r['ok'] ? 'ok' : 'bad', ($r['ok'] ? '✔ ' : '✖ ') . $r['msg']];
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
    } elseif ($act === 'link' || $act === 'ignore') {
        $id = (int) ($_POST['id'] ?? 0);
        $c  = PayCredits::get($id);
        $tab = $c !== null && (string) $c['source'] === 'bankapi' ? 'in' : 'np';
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

$stateTxt = [
    'new' => ['नयाँ · new', '#185bed'], 'matched' => ['✔ Auto PAID', '#0a7c2f'], 'linked' => ['✔ Linked', '#0a7c2f'],
    'unmatched' => ['⏳ व्यक्ति चाहिन्छ · needs a person', '#b26a00'], 'ignored' => ['Ignored', '#667085'], 'test' => ['TEST (sandbox)', '#7a4b00'],
];
$credits = static function (string $src) use ($e, $stateTxt): void {
    $rows = PayCredits::listRecent(['source' => $src, 'limit' => 60]);
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
        [$lbl, $col] = $stateTxt[(string) $r['state']] ?? [(string) $r['state'], '#667085'];
        $open = in_array((string) $r['state'], ['new', 'unmatched'], true);
        echo '<div class="pc-cr">';
        echo '<div class="pc-cr-top"><b>' . $e($r['currency'] . ' ' . number_format((float) $r['amount'], 2)) . '</b>'
           . ' <span class="pc-st" style="color:' . $e($col) . ';border-color:' . $e($col) . '">' . $e($lbl) . '</span>'
           . ' <small class="muted">#' . (int) $r['id'] . ' · ' . $e(substr((string) ($r['received_at'] ?? $r['created_at']), 0, 16)) . '</small></div>';
        echo '<div class="pc-cr-meta">Txn <code>' . $e($r['txn_id'] ?? '—') . '</code>'
           . ($r['ref_text'] ? ' · ' . $e(mb_substr((string) $r['ref_text'], 0, 90)) : '')
           . ($r['booking_pnr'] ? ' · <a href="booking-view.php?pnr=' . rawurlencode((string) $r['booking_pnr']) . '">' . $e($r['booking_pnr']) . '</a>' : '')
           . ($r['source'] === 'gmail' ? ' · ' . ((int) $r['dkim_ok'] === 1 ? '<span style="color:#0a7c2f">DKIM ✔</span>' : '<span style="color:#b3261e">DKIM ✖</span>') . ' ' . $e($r['sender'] ?? '') : '')
           . '</div>';
        if (!empty($r['note'])) {
            echo '<div class="pc-cr-note">' . $e($r['note']) . ($r['acted_name'] ? ' — ' . $e($r['acted_name']) : '') . '</div>';
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

admin_header('पेमेन्ट म्यानेजमेन्ट · Payment Management', 'payment-center');
?>
<style>
.pc-wrap{display:grid;gap:14px;max-width:920px}
.pc-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:2px}
.pc-tabs a{flex:0 0 auto;padding:10px 14px;border:1px solid var(--line,#e0e8f2);border-radius:999px;text-decoration:none;color:inherit;font-weight:700;min-height:44px;display:flex;align-items:center;background:var(--card,#fff)}
.pc-tabs a.on{background:#185bed;border-color:#185bed;color:#fff}
.pc-card{background:var(--surface,#fff);border:1px solid var(--line,#e0e8f2);border-radius:14px;padding:14px 16px;min-width:0}
.pc-card h2{margin:0 0 4px;font-size:16px}.pc-card p.hint{margin:0 0 10px;color:var(--muted,#637491);font-size:12.5px}
.pc-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.pc-f label{font-size:12px;color:var(--muted,#637491);min-width:0}
.pc-f input,.pc-f select,.pc-f textarea{display:block;width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px;min-height:42px;font:inherit}
.pc-btns{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}.pc-btns .btn{min-height:44px}
.pc-flash{padding:9px 12px;border-radius:10px;overflow-wrap:anywhere}.pc-flash.ok{background:#e9f8ee;color:#0a7c2f}.pc-flash.bad{background:#fdecec;color:#b3261e}
.pc-warn{background:#fff4e5;color:#7a4b00;padding:8px 10px;border-radius:10px}
.pc-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13.5px}.pc-kv b{white-space:nowrap}
.pc-ok{color:#0a7c2f;font-weight:700}.pc-no{color:#b26a00;font-weight:700}
.pc-list{display:grid;gap:8px}
.pc-cr{border:1px solid var(--line,#e0e8f2);border-radius:12px;padding:10px 12px;min-width:0;overflow-wrap:anywhere}
.pc-cr-top b{font-size:16px}.pc-st{display:inline-block;border:1px solid;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700}
.pc-cr-meta,.pc-cr-note{font-size:12.5px;color:var(--muted,#637491);margin-top:4px}.pc-cr-note{color:#7a4b00}
.pc-cr-act{display:grid;gap:6px;margin-top:8px}
.pc-inline{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.pc-inline input[type=text],.pc-inline input:not([type]){flex:1 1 160px;min-width:0;padding:8px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px;min-height:42px;font:inherit}
.pc-chk{font-size:12px;display:flex;gap:6px;align-items:center}
.pc-url{display:block;width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--line,#e0e8f2);border-radius:8px;font-size:13px}
.pc-tbl{width:100%;border-collapse:collapse;font-size:13px}.pc-tbl th,.pc-tbl td{padding:6px 6px;border-bottom:1px solid var(--line,#e0e8f2);text-align:right;white-space:nowrap}
.pc-tbl th:first-child,.pc-tbl td:first-child{text-align:left;white-space:normal}
.pc-scroll{overflow-x:auto;max-width:100%}
details.pc-adv summary{cursor:pointer;font-weight:700;margin:10px 0 6px}
</style>
<div class="pc-wrap">
  <nav class="pc-tabs" aria-label="Payment tabs">
    <a href="?tab=np" class="<?= $tab === 'np' ? 'on' : '' ?>">🇳🇵 नेपाली अनलाइन</a>
    <a href="?tab=in" class="<?= $tab === 'in' ? 'on' : '' ?>">🇮🇳 इन्डियन अनलाइन</a>
    <a href="?tab=cash" class="<?= $tab === 'cash' ? 'on' : '' ?>">💵 नगद</a>
  </nav>
  <?php foreach ($flash as [$t, $m]): ?><div class="pc-flash <?= $e($t) ?>"><?= $e($m) ?></div><?php endforeach; ?>
  <?php if (!$ready && $tab !== 'cash'): ?><div class="pc-warn">⚠️ Migration <b>upgrade-2026-11-25-mp1-pay-credits.sql</b> बाँकी — Gmail / bank API अहिले बन्द रहन्छ · runs quietly off until it is applied.</div><?php endif; ?>

<?php if ($tab === 'np'): $qrNow = Ticket::esewaQrFile(); $mailOn = BankMail::on(); ?>
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
    <h2>📧 Gmail बाट बैंक alert · Global IME credit alerts</h2>
    <p class="hint">बैंकको credit alert मात्र आउने छुट्टै Gmail राख्नुहोस्। Google Account → Security → 2-Step → <b>App passwords</b> बाट १६ अक्षरको password। Mailbox मा केही बदलिँदैन (read-only)। पैसा, PNR र रकम ठ्याक्कै मिलेमा मात्र टिकट आफैं PAID हुन्छ; नत्र तल "व्यक्ति चाहिन्छ" मा आउँछ।</p>
    <div class="pc-f">
      <label>eSewa / bank ID<input name="nepal_esewa_id" value="<?= $e($val('nepal_esewa_id')) ?>" placeholder="98XXXXXXXX"></label>
      <label>eSewa / खाता नाम · name<input name="nepal_esewa_name" value="<?= $e($val('nepal_esewa_name')) ?>"></label>
      <label>Gmail ठेगाना · address<input name="bankmail_user" type="email" value="<?= $e($val('bankmail_user')) ?>" placeholder="shg.bankalerts@gmail.com"></label>
      <label>App password <?= $val('bankmail_app_password') !== '' ? '<span class="pc-ok">✔ saved</span>' : '' ?><input name="bankmail_app_password" type="password" value="" autocomplete="new-password" placeholder="<?= $val('bankmail_app_password') !== '' ? '•••• (बदल्न मात्र लेख्नुहोस्)' : 'xxxx xxxx xxxx xxxx' ?>"></label>
      <label>बैंकको sender (comma) · allowed senders<input name="bankmail_from" value="<?= $e($val('bankmail_from')) ?>" placeholder="alerts@gibl.com.np, @gibl.com.np"></label>
      <label>DKIM domain (बैंकको)<input name="bankmail_dkim_domain" value="<?= $e($val('bankmail_dkim_domain')) ?>" placeholder="gibl.com.np"></label>
      <label>IMAP host<input name="bankmail_host" value="<?= $e($val('bankmail_host', 'imap.gmail.com')) ?>"></label>
      <label style="display:flex;gap:8px;align-items:center;color:inherit"><input type="checkbox" name="pay_bankmail_autoconfirm_on" value="1" style="width:auto;min-height:0" <?= Settings::getBool('pay_bankmail_autoconfirm_on', true) ? 'checked' : '' ?>> मिलेमा आफैं PAID · auto-confirm a full match</label>
    </div>
    <div class="pc-btns">
      <button class="btn" type="submit">सेभ · Save</button>
      <?php if ($mailOn): ?><button class="btn" type="submit" name="enable" value="0">⏸ बन्द · Turn off</button>
      <?php else: ?><button class="btn btn-orange" type="submit" name="enable" value="1">Save &amp; Enable</button><?php endif; ?>
      <button class="btn" type="submit" name="act" value="np_test" formnovalidate>🔌 Test connection</button>
    </div>
    <div class="pc-kv" style="margin-top:10px">
      <b>Status</b><span class="<?= $mailOn ? 'pc-ok' : 'pc-no' ?>"><?= $mailOn ? '● चालु · ON' : '○ बन्द · OFF' ?></span>
      <b>Last poll</b><span><?= $lastPoll !== [] ? $e(($lastPoll['at'] ?? '') . ' · ' . (!empty($lastPoll['ok']) ? '✔ ' : '✖ ') . ($lastPoll['msg'] ?? '')) : '—' ?></span>
      <b>Last UID</b><span><?= (int) Settings::getInt('bankmail_last_uid', 0) ?></span>
      <b>Cron</b><span><code>cron/bank-mail.php</code> हरेक २ मिनेट</span>
    </div>
  </form>

  <div class="pc-card">
    <h2>🏦 Credits (Gmail)</h2>
    <p class="hint">"Link to PNR": रकम ठ्याक्कै मिल्नुपर्छ; Payment risk जाँच पनि हुन्छ। Ignore: हाम्रो होइन / refund।</p>
    <?php $credits('gmail'); ?>
  </div>

<?php elseif ($tab === 'in'): $apiOn = BankApi::on(); $map = BankApi::fieldMap(); ?>
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
    <h2>🏦 Credits (bank API)</h2>
    <?php $credits('bankapi'); ?>
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
    <label style="font-size:12px;color:var(--muted,#637491)">मिति · Day<input type="date" name="day" value="<?= $e($day) ?>" style="display:block;min-height:42px;padding:6px 10px;border:1px solid var(--line,#e0e8f2);border-radius:9px"></label>
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
