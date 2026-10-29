<?php
/**
 * admin/payslip.php?id=<staff_salary_payments.id> — a printable salary slip
 * (29 Oct 2026, management upgrade 4.4 "pay slip PDF").
 *
 * One A5 page with the company header, the person, the month, the salary,
 * every deduction (advance recovery, other) and bonus, the net paid, how and
 * when it was paid, the advance still to repay, and two signature lines.
 * "Print" in the browser saves it as a PDF — the same way the shift slip and
 * the agent receipt are kept, no second PDF engine to maintain.
 *
 * Read-only. Same gate as Salary / Payroll (payments.view). The figures are
 * exactly the payment row Payroll::pay() wrote; nothing is recomputed.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/payroll.php';
$admin = admin_boot('payments.view');

$e   = static fn($v): string => Security::e((string) $v);
$pay = Payroll::payment((int) ($_GET['id'] ?? 0));
$st  = $pay !== null ? Payroll::staff((int) $pay['staff_id']) : null;
if ($pay === null || $st === null) {
    admin_header('Salary slip', 'payroll');
    echo '<div class="flash bad">Salary payment not found. Open it from <a href="/admin/payroll.php">Salary / Payroll</a>.</div>';
    admin_footer();
    exit;
}
$cur      = (string) ($pay['currency'] ?? $st['currency'] ?? 'INR');
$m        = static fn(float $v): string => Payroll::money($v, $cur);
$owed     = Payroll::openBalance((int) $st['id']);
$company  = Settings::getString('company_name', defined('APP_NAME') ? (string) APP_NAME : 'S Hari Global');
$address  = Settings::getString('company_address', '');
$phone    = Settings::getString('company_phone', '');
$slipNo   = 'SAL-' . str_replace('-', '', (string) $pay['period']) . '-' . (int) $pay['id'];
$by       = (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => (int) ($pay['created_by'] ?? 0)], '');
Logger::audit('payroll.slip_view', 'staff_salary_payment', (string) $pay['id'], null, null, 'salary slip opened');

admin_header('Salary slip · ' . (string) $st['full_name'], 'payroll');
?>
<style>
.ps{max-width:560px;margin:0 auto;background:#fff;color:#111;border:1px solid #d6dbe4;border-radius:12px;padding:26px 28px;font-size:14px}
.ps-h{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:2px solid #12264E;padding-bottom:12px;margin-bottom:14px}
.ps-h b{font-size:18px;color:#12264E}
.ps-h small{display:block;color:#555;font-size:12px;line-height:1.5}
.ps-t{font-size:16px;font-weight:800;text-align:right;color:#F07C1F}
.ps-kv{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;margin:0 0 14px}
.ps-kv span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#666}
.ps table{width:100%;border-collapse:collapse;margin:6px 0 14px}
.ps td{padding:8px 4px;border-bottom:1px solid #e6e9ef}
.ps td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ps tr.net td{border-top:2px solid #12264E;border-bottom:0;font-weight:800;font-size:16px}
.ps-sign{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:34px;font-size:12px;color:#444}
.ps-sign div{border-top:1px solid #888;padding-top:6px;text-align:center}
.ps-acts{max-width:560px;margin:14px auto 0;display:flex;gap:8px;flex-wrap:wrap}
@media print{
  body *{visibility:hidden}
  .ps,.ps *{visibility:visible}
  .ps{position:absolute;left:0;top:0;border:0;max-width:none;width:100%}
  @page{size:A5;margin:12mm}
}
</style>

<div class="ps" id="payslip">
  <div class="ps-h">
    <div><b><?= $e($company) ?></b>
      <small><?= $e($address) ?><?= $phone !== '' ? '<br>📞 ' . $e($phone) : '' ?></small></div>
    <div class="ps-t">SALARY SLIP<br><small style="color:#555;font-weight:600"><?= $e($slipNo) ?></small></div>
  </div>
  <div class="ps-kv">
    <div><span>Name · नाम</span><b><?= $e($st['full_name']) ?></b></div>
    <div><span>Month · महिना</span><b><?= $e(Payroll::periodLabel((string) $pay['period'])) ?></b></div>
    <div><span>Work</span><?= $e(Payroll::ROLES[(string) ($st['role'] ?? '')] ?? (string) ($st['role'] ?? '')) ?><?= !empty($st['base_location']) ? ' · ' . $e($st['base_location']) : '' ?></div>
    <div><span>Paid on</span><?= $e(formatDate((string) $pay['paid_on'], 'j M Y')) ?> · <?= $e(Payroll::METHODS[(string) $pay['method']] ?? (string) $pay['method']) ?><?= !empty($pay['reference']) ? ' (' . $e($pay['reference']) . ')' : '' ?></div>
  </div>
  <table>
    <tr><td>Monthly salary · तलब</td><td class="n"><?= $e($m((float) $pay['gross'])) ?></td></tr>
    <?php if ((float) $pay['bonus'] > 0): ?><tr><td>Bonus · बोनस</td><td class="n">+ <?= $e($m((float) $pay['bonus'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $pay['advance_deduction'] > 0): ?><tr><td>Advance recovered · पेस्की कटौती</td><td class="n">− <?= $e($m((float) $pay['advance_deduction'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $pay['other_deduction'] > 0): ?><tr><td>Other deduction · अन्य कटौती</td><td class="n">− <?= $e($m((float) $pay['other_deduction'])) ?></td></tr><?php endif; ?>
    <tr class="net"><td>Net paid · खुद भुक्तानी</td><td class="n"><?= $e($m((float) $pay['net_paid'])) ?></td></tr>
  </table>
  <?php if ($owed > 0.004): ?><p style="margin:0 0 8px;color:#8a4b00">Advance still to repay: <b><?= $e($m($owed)) ?></b></p><?php endif; ?>
  <?php if (!empty($pay['notes'])): ?><p style="margin:0;color:#555;font-size:12.5px">Note: <?= $e($pay['notes']) ?></p><?php endif; ?>
  <div class="ps-sign">
    <div>Received by · बुझ्ने<br><b><?= $e($st['full_name']) ?></b></div>
    <div>Paid / authorised by · दिने<br><b><?= $e($by !== '' ? $by : $company) ?></b></div>
  </div>
</div>
<div class="ps-acts">
  <button class="btn ok" type="button" onclick="window.print()">🖨 Print / Save as PDF</button>
  <?php $wa = Payroll::waLink($st, Payroll::slipText($st, $pay, $owed)); if ($wa !== ''): ?>
    <a class="btn" href="<?= $e($wa) ?>" target="_blank" rel="noopener">📲 Send on WhatsApp</a>
  <?php endif; ?>
  <a class="btn ghost" href="/admin/payroll.php?m=<?= $e($pay['period']) ?>">← Payroll</a>
</div>
<?php admin_footer();
