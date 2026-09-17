<?php
/**
 * admin/agent-receipt.php?id=<agent_ledger id> — printable settlement
 * receipt / voucher for one wallet row (17 Sep 2026).
 *
 * A payout or a cash handover is a piece of paper the agent is handed (or
 * shown on a phone): company letterhead, voucher number, who, how much, in
 * words, when, who recorded it, and two signature lines. Any ledger row can
 * be printed (a commission credit note, a correction), but the two
 * settlement kinds are what the office reaches for.
 *
 * Standalone HTML — no admin chrome — so it prints clean on an A5/A4 sheet
 * and reads on a phone. Nothing here writes: the row is read as-is.
 *
 * Gate: the signed-in staff member must hold commissions.view, OR be the
 * counter agent whose row this is (Auth::bookingScopeAdminId() === the
 * row's agent). A counter agent can therefore print their own receipts and
 * nobody else's; a counter/support role without commissions.view gets 403.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot();   // signed-in staff — the row-level gate is below

$id  = (int) ($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    try {
        $row = Database::fetch(
            "SELECT l.*, a.full_name AS agent_name, a.username AS agent_username, a.phone AS agent_phone,
                    b.full_name AS by_name, bk.pnr
               FROM agent_ledger l
               LEFT JOIN admins   a  ON a.id  = l.agent_admin_id
               LEFT JOIN admins   b  ON b.id  = l.created_by
               LEFT JOIN bookings bk ON bk.id = l.booking_id
              WHERE l.id = :i
              LIMIT 1",
            ['i' => $id]
        );
    } catch (Throwable $e) {
        $row = null;
    }
}
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No such ledger entry.');
}

$agentId = (int) $row['agent_admin_id'];
$own     = Auth::bookingScopeAdminId() === $agentId;
if (!Auth::can('commissions.view') && !$own) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('You may only view receipts for your own account.');
}

/* ---- What the paper says --------------------------------------------- */
$type   = (string) $row['entry_type'];
$ref    = (string) ($row['ref'] ?? '');
$amount = abs((float) $row['amount']);
$title  = match ($type) {
    'payout'          => 'Commission payout voucher',
    'cash_handover'   => 'Cash handover receipt',
    'commission'      => 'Commission credit note',
    'commission_void' => 'Commission reversal note',
    'cash_due'        => 'Cash collected note',
    default           => (stripos($ref, 'ADVANCE') === 0 ? 'Advance / loan voucher'
                        : (stripos($ref, 'SALARY') === 0 ? 'Salary credit note'
                        : (stripos($ref, 'ADJ') === 0 ? 'Correction note' : 'Wallet adjustment note'))),
};
/* Who handed money to whom — the line a receipt must make unambiguous. */
$line = match ($type) {
    'payout'        => 'Paid by the company to the agent (commission payout)',
    'cash_handover' => 'Received by the company from the agent (cash handover)',
    'commission'    => 'Credited to the agent\'s commission account',
    'commission_void' => 'Debited from the agent\'s commission account (reversal)',
    'cash_due'      => 'Cash collected by the agent, owed to the company',
    default         => ((float) $row['amount'] >= 0 ? 'Credited to' : 'Debited from') . ' the agent\'s '
                       . ((string) $row['account'] === 'cash' ? 'cash' : 'commission') . ' account',
};
$voucher = $ref !== '' ? $ref : '#' . (int) $row['id'];
$code    = AgentWallet::agentCodeLabel($agentId);
$co      = Settings::company();

/* Logo: the admin-set company_logo path wins, then the site logo (same order
   as ReportPdf). Only a file that exists is referenced, so a stale setting
   never prints a broken image. */
$logo = '';
foreach ([Settings::getString('company_logo', ''), 'assets/img/logo.png'] as $cand) {
    $cand = ltrim(trim((string) $cand), '/');
    if ($cand !== '' && !str_contains($cand, '..') && is_file(ROOT_PATH . '/' . $cand)) {
        $logo = '/' . $cand;
        break;
    }
}

/** Indian-style amount in words: "One lakh twenty-three thousand rupees only". */
function receipt_words(float $amount): string
{
    $n     = (int) floor($amount);
    $paise = (int) round(($amount - $n) * 100);
    $ones  = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
              'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens  = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $two   = static function (int $x) use ($ones, $tens): string {
        if ($x < 20) {
            return $ones[$x];
        }
        return $tens[intdiv($x, 10)] . ($x % 10 !== 0 ? '-' . $ones[$x % 10] : '');
    };
    $three = static function (int $x) use ($two, $ones): string {
        $s = '';
        if ($x >= 100) {
            $s  = $ones[intdiv($x, 100)] . ' hundred';
            $x %= 100;
            if ($x > 0) {
                $s .= ' and ';
            }
        }
        return $s . ($x > 0 ? $two($x) : '');
    };
    if ($n === 0) {
        $out = 'zero';
    } else {
        $parts = [];
        foreach ([['crore', 10000000], ['lakh', 100000], ['thousand', 1000]] as [$name, $div]) {
            if ($n >= $div) {
                $parts[] = $two(intdiv($n, $div)) . ' ' . $name;   // ledger amounts stop at 9.99 crore, so < 100 per group
                $n %= $div;
            }
        }
        if ($n > 0) {
            $parts[] = $three($n);
        }
        $out = implode(' ', $parts);
    }
    return ucfirst(trim($out)) . ' rupees' . ($paise > 0 ? ' and ' . $two($paise) . ' paise' : '') . ' only';
}

$e    = static fn($v): string => Security::e((string) ($v ?? ''));
$when = (string) $row['created_at'];
$back = $own && !Auth::can('commissions.view') ? '/admin/agent.php' : '/admin/agent-360.php?agent=' . $agentId . '&tab=settlements';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title) ?> · <?= $e($voucher) ?> · <?= $e($co['name']) ?></title>
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<style>
  :root{--ink:#14213d;--mut:#6b7688;--line:#d9dee8;--navy:#12264E;--blue:#2E5FA8;--soft:#f5f7fb}
  *{box-sizing:border-box}
  body{margin:0;background:#e9edf3;color:var(--ink);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Arial,sans-serif}
  .bar{display:flex;gap:8px;justify-content:center;padding:14px;flex-wrap:wrap}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;border:1px solid var(--line);background:#fff;color:var(--ink);font-weight:700;text-decoration:none;cursor:pointer;font-family:inherit;font-size:14px}
  .btn.primary{background:var(--navy);color:#fff;border-color:var(--navy)}
  .sheet{background:#fff;max-width:720px;margin:0 auto 30px;padding:32px 36px;border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 28px rgba(18,38,78,.08)}
  .head{display:flex;gap:18px;align-items:center;border-bottom:2px solid var(--navy);padding-bottom:16px;margin-bottom:18px}
  .head img{width:64px;height:64px;object-fit:contain}
  .head .co{flex:1;min-width:0}
  .head .co b{display:block;font-size:20px;letter-spacing:-.01em;color:var(--navy)}
  .head .co small{display:block;color:var(--mut);font-size:12px;line-height:1.45}
  .ttl{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
  .ttl h1{margin:0;font-size:19px;letter-spacing:-.01em}
  .ttl .vno{font-family:ui-monospace,Consolas,monospace;font-size:15px;font-weight:800;background:var(--soft);border:1px solid var(--line);padding:6px 12px;border-radius:9px}
  .amt{background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:16px 18px;margin:14px 0 18px}
  .amt .v{font-size:32px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
  .amt .w{color:var(--mut);font-size:13px;margin-top:2px}
  .amt .l{font-size:13px;margin-top:8px;font-weight:600}
  dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 18px;margin:0;font-size:13.5px}
  dt{color:var(--mut);font-weight:600}dd{margin:0;font-weight:600}
  .mono{font-family:ui-monospace,Consolas,monospace}
  .sig{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:46px}
  .sig div{border-top:1px solid var(--ink);padding-top:6px;font-size:12px;color:var(--mut);text-align:center}
  .foot{margin-top:22px;font-size:11px;color:var(--mut);text-align:center;border-top:1px dashed var(--line);padding-top:10px}
  @media print{body{background:#fff}.bar{display:none}.sheet{max-width:none;margin:0;border:0;box-shadow:none;border-radius:0;padding:10mm 12mm}@page{margin:10mm}}
  @media(max-width:600px){.sheet{padding:20px 18px;border-radius:0;margin-bottom:0}.sig{gap:20px}}
</style>
</head>
<body>
<div class="bar">
  <button class="btn primary" type="button" onclick="window.print()">🖨 Print</button>
  <a class="btn" href="<?= $e($back) ?>">← Back</a>
</div>
<div class="sheet">
  <div class="head">
    <?php if ($logo !== ''): ?><img src="<?= $e($logo) ?>" alt=""><?php endif; ?>
    <div class="co">
      <b><?= $e($co['legal'] ?: $co['name']) ?></b>
      <small><?= $e($co['address']) ?></small>
      <small><?= $e($co['phone']) ?><?= $co['email'] !== '' ? ' · ' . $e($co['email']) : '' ?><?= $co['web'] !== '' ? ' · ' . $e($co['web']) : '' ?><?= $co['cin'] !== '' ? ' · CIN ' . $e($co['cin']) : '' ?></small>
    </div>
  </div>

  <div class="ttl">
    <h1><?= $e($title) ?></h1>
    <span class="vno"><?= $e($voucher) ?></span>
  </div>

  <div class="amt">
    <div class="v">₹<?= $e(number_format($amount, 2)) ?></div>
    <div class="w"><?= $e(receipt_words($amount)) ?></div>
    <div class="l"><?= $e($line) ?></div>
  </div>

  <dl>
    <dt>Agent</dt><dd><?= $e($row['agent_name'] ?: $row['agent_username']) ?><?= $code !== '' ? ' <span class="mono">· ' . $e($code) . '</span>' : '' ?></dd>
    <?php if ((string) ($row['agent_phone'] ?? '') !== ''): ?><dt>Mobile</dt><dd class="mono"><?= $e($row['agent_phone']) ?></dd><?php endif; ?>
    <dt>Date</dt><dd><?= $e(formatDate($when, 'l, j F Y')) ?> · <?= $e(formatDate($when, 'g:i A')) ?></dd>
    <dt>Account</dt><dd><?= $e(ucfirst((string) $row['account'])) ?> <span style="color:var(--mut);font-weight:500">(<?= (string) $row['account'] === 'cash' ? 'money the agent holds for the company' : 'money the company owes the agent' ?>)</span></dd>
    <?php if (!empty($row['pnr'])): ?><dt>Booking</dt><dd class="mono"><?= $e($row['pnr']) ?></dd><?php endif; ?>
    <dt>Recorded by</dt><dd><?= $e($row['by_name'] ?: 'System (automatic)') ?></dd>
    <?php if ((string) ($row['note'] ?? '') !== ''): ?><dt>Note</dt><dd style="font-weight:500"><?= $e($row['note']) ?></dd><?php endif; ?>
    <dt>Entry no</dt><dd class="mono">#<?= (int) $row['id'] ?></dd>
  </dl>

  <div class="sig">
    <div><?= $type === 'cash_handover' ? 'Agent (handed over)' : 'Agent (received)' ?></div>
    <div>For <?= $e($co['name']) ?></div>
  </div>

  <div class="foot">
    Computer-generated from the agent wallet ledger · printed <?= $e(date('j M Y, g:i A')) ?> by <?= $e((string) ($admin['full_name'] ?: ($admin['username'] ?? ''))) ?>
    · every payout and handover carries a voucher number the office can trace to this entry.
  </div>
</div>
</body>
</html>
