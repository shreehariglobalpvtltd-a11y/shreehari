<?php
declare(strict_types=1);

/**
 * Guard the counter's last irreversible step: Quick Ticket must show one
 * fare/discount review before it calls the sell API.
 */

$source = file_get_contents(__DIR__ . '/../admin/quick-ticket.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read admin/quick-ticket.php\n");
    exit(1);
}

$checks = [
    'confirmation function' => "function confirmSale(name)",
    'browser confirmation'  => "return window.confirm(lines.join('\\n'));",
    'base fare review'      => "'Base fare: ' + money(base)",
    'discount review'       => "'Discount: -' + money(discount)",
    'final total review'    => "'Final total: ' + money(finalTotal)",
    'visible discount box'  => 'id="qtDiscountBox"',
    'live final preview'    => "' = तिर्ने ' + money(amounts.finalTotal)",
    'server cap mirrored'   => 'base * Math.max(0, MAX_DISC) / 100',
];

foreach ($checks as $label => $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: missing {$label}\n");
        exit(1);
    }
}

$confirmAt = strpos($source, 'if (!confirmSale(name)) return;');
$sellAt = strpos($source, "action: 'sell'");
if ($confirmAt === false || $sellAt === false || $confirmAt > $sellAt) {
    fwrite(STDERR, "FAIL: confirmation must run before the sell request\n");
    exit(1);
}

echo "PASS: Quick Ticket reviews fare and discount once before issue.\n";
