<?php
/**
 * wa.php — the WhatsApp booking link (19 Nov 2026).
 *
 * Owner: "Meta ko form publish bhaena bhane direct WhatsApp ma khulne link banau, form jasto
 * bharera ticket delivery garos; agent ra customer dubai lai". One short link that opens
 * WhatsApp on the company's bot number with the booking form already typed:
 *
 *     https://www.shreehariglobal.in/wa.php            customer link
 *     https://www.shreehariglobal.in/wa.php?a=SHG-0008 an agent's own link (the ticket is
 *                                                      credited to that agent, see
 *                                                      WaBooking::noteFormAgent)
 *     …&from=Surat&l=en                                optional prefill / language (ne, hi, en)
 *
 * The customer fills the blanks and presses Send; WaBooking::formToSentence reads it and the
 * bot shows the summary, then the ticket. No Meta template or Flow is needed (the customer
 * writes first, so the reply is free). Nothing is stored here.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';

$e    = static fn($v): string => Security::e((string) $v);
$lang = in_array((string) ($_GET['l'] ?? ''), ['ne', 'hi', 'en'], true) ? (string) $_GET['l'] : 'ne';
$from = Security::clean((string) ($_GET['from'] ?? ''), 40);
$agentRaw = strtoupper(Security::clean((string) ($_GET['a'] ?? ''), 16));
$agent = '';
if ($agentRaw !== '') {
    try {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $id = AgentWallet::resolveAgentCodeFromString($agentRaw);
        if ($id !== null && $id > 0) {
            $agent = AgentWallet::agentCodeLabel((int) $id) ?: '';
        }
    } catch (Throwable $ex) {
        $agent = '';
    }
}
$L = [
    'ne' => ['टिकट बुक', 'नाम', 'मोबाइल', 'चढ्ने ठाउँ', 'मिति', 'सिट', 'एजेन्ट'],
    'hi' => ['टिकट बुक', 'नाम', 'मोबाइल', 'चढ्ने ठाउँ', 'मिति', 'सिट', 'एजेन्ट'],
    'en' => ['Ticket book', 'Name', 'Mobile', 'From', 'Date', 'Seat', 'Agent'],
][$lang];
$text = "🎫 {$L[0]}\n{$L[1]}: \n{$L[2]}: \n{$L[3]}: {$from}\n{$L[4]}: \n{$L[5]}: 1" . ($agent !== '' ? "\n{$L[6]}: {$agent}" : '');
$num  = Settings::chatWhatsApp();
$url  = 'https://wa.me/' . $num . '?text=' . rawurlencode($text);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
if ((string) ($_GET['go'] ?? '1') !== '0' && !isset($_GET['qr'])) {
    header('Refresh: 0; url=' . $url);
}
$self = appUrl('wa.php' . ($agent !== '' ? '?a=' . rawurlencode($agent) : ''));
$qr = '';
if (isset($_GET['qr'])) {
    require_once INCLUDE_PATH . '/qr.php';
    $qr = QrCode::dataUri($self, 7, 3);
}
?><!doctype html>
<html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp बाट टिकट · S Hari Global</title>
<style>body{font-family:Poppins,"Noto Sans Devanagari",system-ui,sans-serif;background:#0C306C;color:#fff;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px}
.c{background:#fff;color:#16233C;border-radius:18px;padding:22px;max-width:420px;width:100%;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.3)}
h1{font-size:20px;margin:0 0 6px}p{margin:6px 0;color:#4a5670;font-size:14px}pre{text-align:left;background:#f3f6fb;border-radius:12px;padding:12px;font:14px/1.6 inherit;white-space:pre-wrap}
a.b{display:block;background:#25D366;color:#fff;text-decoration:none;font-weight:700;padding:14px;border-radius:12px;margin-top:12px;font-size:16px}img{max-width:240px;width:100%}</style></head>
<body><div class="c">
  <h1>🎫 WhatsApp बाट टिकट</h1>
  <p>तलको फारम WhatsApp मा खुल्छ — खाली ठाउँ भरेर <b>Send</b> गर्नुहोस्। टिकट त्यहीँ आउँछ।<br>Fill the blanks in WhatsApp and press Send.</p>
  <?php if ($qr !== ''): ?><img src="<?= $e($qr) ?>" alt="QR"><p><?= $e($self) ?></p><?php endif; ?>
  <pre><?= $e($text) ?></pre>
  <a class="b" href="<?= $e($url) ?>">WhatsApp खोल्नुहोस् · Open WhatsApp</a>
  <?php if ($agent !== ''): ?><p>एजेन्ट · Agent: <b><?= $e($agent) ?></b></p><?php endif; ?>
</div></body></html>
